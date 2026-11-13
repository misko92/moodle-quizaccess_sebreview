<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace quizaccess_sebreview;

use quizaccess_seb\seb_access_manager;
use quizaccess_seb\seb_quiz_settings;
use quizaccess_seb\settings_provider;
use quizaccess_sebreview\local\guard;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/seb/tests/test_helper_trait.php');
require_once($CFG->dirroot . '/mod/quiz/accessrule/sebreview/rule.php');

/**
 * Tests for the review guard, the quiz setting and backup/restore.
 *
 * @package    quizaccess_sebreview
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(guard::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\quizaccess_sebreview::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\backup_quizaccess_sebreview_subplugin::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\restore_quizaccess_sebreview_subplugin::class)]
final class guard_test extends \advanced_testcase {
    use \quizaccess_seb_test_helper_trait;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
        guard::reset_cache();
        unset(
            $_SERVER['HTTP_USER_AGENT'],
            $_SERVER['HTTP_X_SAFEEXAMBROWSER_CONFIGKEYHASH'],
            $_SERVER['HTTP_X_SAFEEXAMBROWSER_REQUESTHASH']
        );
    }

    #[\Override]
    protected function tearDown(): void {
        unset($_SERVER['HTTP_USER_AGENT'], $_SERVER['HTTP_X_SAFEEXAMBROWSER_CONFIGKEYHASH'], $_POST['requests']);
        parent::tearDown();
    }

    /**
     * Quiz with SEB, a finished student attempt, and the setting turned on/off.
     *
     * @param int $sebtype
     * @param bool $enabled
     * @return array [quiz, student, attempt record]
     */
    protected function make_finished_attempt(int $sebtype, bool $enabled = true): array {
        global $DB;
        $this->quiz = $this->create_test_quiz($this->course, $sebtype);
        if ($enabled) {
            \quizaccess_sebreview::save_settings((object)['id' => $this->quiz->id, 'sebreview_enabled' => 1]);
        }
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        [, , $attemptobj] = $this->attempt_quiz($this->quiz, $student);
        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptobj->get_attemptid()]);
        $this->setUser($student);
        return [$this->quiz, $student, $attempt];
    }

    public function test_settings_save_make_and_delete(): void {
        global $DB;
        $this->quiz = $this->create_test_quiz($this->course, settings_provider::USE_SEB_CONFIG_MANUALLY);
        $quizobj = \mod_quiz\quiz_settings::create($this->quiz->id);
        $this->assertNull(\quizaccess_sebreview::make($quizobj, time(), false));
        $this->assertFalse(guard::is_enabled($this->quiz->id));

        \quizaccess_sebreview::save_settings((object)['id' => $this->quiz->id, 'sebreview_enabled' => 1]);
        $this->assertTrue(guard::is_enabled($this->quiz->id));
        $quizobj = \mod_quiz\quiz_settings::create($this->quiz->id);
        $rule = \quizaccess_sebreview::make($quizobj, time(), false);
        $this->assertInstanceOf(\quizaccess_sebreview::class, $rule);
        $this->assertEquals([get_string('description', 'quizaccess_sebreview')], $rule->description());

        // Missing from the data (e.g. generator): unchanged.
        \quizaccess_sebreview::save_settings((object)['id' => $this->quiz->id]);
        $this->assertTrue(guard::is_enabled($this->quiz->id));

        \quizaccess_sebreview::save_settings((object)['id' => $this->quiz->id, 'sebreview_enabled' => 0]);
        $this->assertFalse(guard::is_enabled($this->quiz->id));

        \quizaccess_sebreview::delete_settings((object)['id' => $this->quiz->id]);
        $this->assertFalse($DB->record_exists('quizaccess_sebreview', ['quizid' => $this->quiz->id]));
    }

    public function test_validation_needs_seb(): void {
        $this->quiz = $this->create_test_quiz($this->course);
        $form = $this->createMock(\mod_quiz_mod_form::class);
        $errors = \quizaccess_sebreview::validate_settings_form_fields(
            [],
            ['sebreview_enabled' => 1, 'seb_requiresafeexambrowser' => settings_provider::USE_SEB_NO],
            [],
            $form
        );
        $this->assertArrayHasKey('sebreview_enabled', $errors);
        $errors = \quizaccess_sebreview::validate_settings_form_fields(
            [],
            ['sebreview_enabled' => 1, 'seb_requiresafeexambrowser' => settings_provider::USE_SEB_CONFIG_MANUALLY],
            [],
            $form
        );
        $this->assertEmpty($errors);
        $errors = \quizaccess_sebreview::validate_settings_form_fields(
            [],
            ['sebreview_enabled' => 0, 'seb_requiresafeexambrowser' => settings_provider::USE_SEB_NO],
            [],
            $form
        );
        $this->assertEmpty($errors);
    }

    public function test_student_outside_seb_is_blocked(): void {
        [, , $attempt] = $this->make_finished_attempt(settings_provider::USE_SEB_CONFIG_MANUALLY);
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Chrome';
        $this->assertFalse(guard::may_review($attempt, true));
        $this->assertFalse(guard::may_review($attempt, false));
    }

    public function test_student_in_seb_with_valid_config_key_is_allowed(): void {
        global $FULLME;
        [$quiz, , $attempt] = $this->make_finished_attempt(settings_provider::USE_SEB_CONFIG_MANUALLY);
        $configkey = seb_quiz_settings::get_record(['quizid' => $quiz->id])->get_config_key();
        $FULLME = 'https://example.com/mod/quiz/review.php?attempt=' . $attempt->id;
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 SEB/3.6';

        // SEB, but the wrong config (e.g. a different exam's settings): strict check fails, file check passes.
        $_SERVER['HTTP_X_SAFEEXAMBROWSER_CONFIGKEYHASH'] = hash('sha256', $FULLME . 'wrong');
        $this->assertFalse(guard::may_review($attempt, true));
        $this->assertTrue(guard::may_review($attempt, false));

        $_SERVER['HTTP_X_SAFEEXAMBROWSER_CONFIGKEYHASH'] = hash('sha256', $FULLME . $configkey);
        $this->assertTrue(guard::may_review($attempt, true));
    }

    public function test_spoofed_user_agent_without_keys_is_blocked(): void {
        [, , $attempt] = $this->make_finished_attempt(settings_provider::USE_SEB_CONFIG_MANUALLY);
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 SEB/3.6';
        $this->assertFalse(guard::may_review($attempt, true));
    }

    public function test_client_config_mode_uses_user_agent(): void {
        [, , $attempt] = $this->make_finished_attempt(settings_provider::USE_SEB_CLIENT_CONFIG);
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Chrome';
        $this->assertFalse(guard::may_review($attempt, true));
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 SEB/3.6';
        $this->assertTrue(guard::may_review($attempt, true));
    }

    public function test_seb_turned_off_later_still_needs_seb_browser(): void {
        [, , $attempt] = $this->make_finished_attempt(settings_provider::USE_SEB_NO);
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Chrome';
        $this->assertFalse(guard::may_review($attempt, true));
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 SEB/3.6';
        $this->assertTrue(guard::may_review($attempt, true));
    }

    public function test_setting_off_allows_review(): void {
        [, , $attempt] = $this->make_finished_attempt(settings_provider::USE_SEB_CONFIG_MANUALLY, false);
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Chrome';
        $this->assertTrue(guard::may_review($attempt, true));
    }

    public function test_teacher_and_unfinished_attempt_allowed(): void {
        [, , $attempt] = $this->make_finished_attempt(settings_provider::USE_SEB_CONFIG_MANUALLY);
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Chrome';

        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher'); // Non-editing: viewreports only.
        $this->setUser($teacher);
        $this->assertTrue(guard::may_review($attempt, true));
        // Capability check for another user (web service path).
        $this->assertTrue(guard::may_review($attempt, true, (int)$teacher->id));
        $this->assertFalse(guard::may_review($attempt, true, (int)$attempt->userid));

        $inprogress = clone $attempt;
        $inprogress->state = \mod_quiz\quiz_attempt::IN_PROGRESS;
        $this->setUser($attempt->userid);
        $this->assertTrue(guard::may_review($inprogress, true));
    }

    public function test_check_functions_throw_and_redirect(): void {
        [, , $attempt] = $this->make_finished_attempt(settings_provider::USE_SEB_CONFIG_MANUALLY);
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Chrome';

        // Files of this attempt's question usage.
        $this->assertTrue(guard::is_file_blocked('/123/question/questiontext/' . $attempt->uniqueid . '/1/45/image.png'));
        // Unrelated files are untouched.
        $this->assertFalse(guard::is_file_blocked('/123/mod_page/content/0/image.png'));
        $this->assertFalse(guard::is_file_blocked('/123/question/questiontext/999999/1/45/image.png'));
        // Inside Safe Exam Browser the files load.
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 SEB/3.6';
        $this->assertFalse(guard::is_file_blocked('/123/question/questiontext/' . $attempt->uniqueid . '/1/45/image.png'));
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Chrome';

        // Review page redirects.
        try {
            guard::check_review_page((int)$attempt->id);
            $this->fail('Expected redirect');
        } catch (\moodle_exception $e) {
            $this->assertEquals('redirecterrordetected', $e->errorcode);
        }
    }

    /**
     * Description of the rule for the current user, as the quiz page shows it.
     *
     * @param \stdClass $quiz
     * @return array
     */
    protected function get_description(\stdClass $quiz): array {
        guard::reset_cache();
        $quizobj = \mod_quiz\quiz_settings::create($quiz->id);
        return \quizaccess_sebreview::make($quizobj, time(), false)->description();
    }

    public function test_quiz_page_offers_launch_when_no_attempts_left(): void {
        global $DB;
        [$quiz, , ] = $this->make_finished_attempt(settings_provider::USE_SEB_CONFIG_MANUALLY);
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Chrome';
        $later = \mod_quiz\question\display_options::IMMEDIATELY_AFTER | \mod_quiz\question\display_options::LATER_WHILE_OPEN;

        // Attempts left: core's Safe Exam Browser rule shows its own buttons.
        $DB->update_record('quiz', ['id' => $quiz->id, 'attempts' => 0, 'reviewattempt' => $later]);
        $this->assertCount(1, $this->get_description($quiz));

        // No attempts left and the attempt may be reviewed: offer the way in.
        $DB->set_field('quiz', 'attempts', 1, ['id' => $quiz->id]);
        $messages = $this->get_description($quiz);
        $this->assertCount(2, $messages);
        $this->assertStringContainsString('sebs://', $messages[1]);
        $this->assertStringContainsString(get_string('seblinkbutton', 'quizaccess_seb'), $messages[1]);

        // Follows the showseblinks setting.
        set_config('showseblinks', 'http', 'quizaccess_seb');
        $messages = $this->get_description($quiz);
        $this->assertStringNotContainsString('sebs://', $messages[1]);
        $this->assertStringContainsString(get_string('httplinkbutton', 'quizaccess_seb'), $messages[1]);
        set_config('showseblinks', 'seb,http', 'quizaccess_seb');

        // Review not allowed now: nothing to see in Safe Exam Browser either.
        $DB->set_field('quiz', 'reviewattempt', \mod_quiz\question\display_options::DURING, ['id' => $quiz->id]);
        $this->assertCount(1, $this->get_description($quiz));
    }

    public function test_quiz_page_no_launch_for_teacher_or_client_config(): void {
        global $DB;
        $later = \mod_quiz\question\display_options::IMMEDIATELY_AFTER | \mod_quiz\question\display_options::LATER_WHILE_OPEN;
        [$quiz, , ] = $this->make_finished_attempt(settings_provider::USE_SEB_CLIENT_CONFIG);
        $DB->update_record('quiz', ['id' => $quiz->id, 'attempts' => 1, 'reviewattempt' => $later]);
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Chrome';
        // Client configuration: nothing to launch.
        $this->assertCount(1, $this->get_description($quiz));

        $this->setAdminUser();
        $this->assertCount(1, $this->get_description($quiz));
    }

    public function test_keys_checked_by_javascript_count_as_seb(): void {
        [$quiz, , $attempt] = $this->make_finished_attempt(settings_provider::USE_SEB_CONFIG_MANUALLY);
        // Newer Safe Exam Browser: user agent, but no key headers.
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 SEB/3.5';
        $this->assertFalse(guard::may_review($attempt, true));
        // The Safe Exam Browser rule's JavaScript check passed for this session.
        (new seb_access_manager(\mod_quiz\quiz_settings::create($quiz->id)))->set_session_access(true);
        $this->assertTrue(guard::may_review($attempt, true));
        // A normal browser never counts, whatever the session says.
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Chrome';
        $this->assertFalse(guard::may_review($attempt, true));
    }

    public function test_quiz_page_no_launch_inside_seb(): void {
        global $DB;
        $later = \mod_quiz\question\display_options::IMMEDIATELY_AFTER | \mod_quiz\question\display_options::LATER_WHILE_OPEN;
        [$quiz, , ] = $this->make_finished_attempt(settings_provider::USE_SEB_CONFIG_MANUALLY);
        $DB->update_record('quiz', ['id' => $quiz->id, 'attempts' => 1, 'reviewattempt' => $later]);
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 SEB/3.5';
        $this->assertCount(1, $this->get_description($quiz));
    }

    public function test_usage_id_from_path(): void {
        $this->assertEquals(456, guard::get_usage_id_from_path('/123/question/questiontext/456/1/789/a.png'));
        $this->assertEquals(456, guard::get_usage_id_from_path('/abcdef0123/123/question/generalfeedback/456/1/789/a.png'));
        $this->assertEquals(0, guard::get_usage_id_from_path('/123/mod_quiz/intro/0/a.png'));
        $this->assertEquals(0, guard::get_usage_id_from_path('/123/question/questiontext/a.png'));
        $this->assertEquals(0, guard::get_usage_id_from_path(''));
    }

    public function test_webservice_attempt_ids(): void {
        $_POST['requests'] = [
            ['function' => 'core_course_get_contents', 'arguments' => '{"courseid":2}'],
            ['function' => 'mod_quiz_get_attempt_review', 'arguments' => '{"attemptid":77,"page":-1}'],
        ];
        $_POST['wsfunction'] = 'tool_mobile_call_external_functions';
        $this->assertEquals([77], guard::get_webservice_attempt_ids());

        unset($_POST['requests']);
        $_POST['wsfunction'] = 'mod_quiz_get_attempt_review';
        $_POST['attemptid'] = '88';
        $this->assertEquals([88], guard::get_webservice_attempt_ids());

        $_POST['wsfunction'] = 'mod_quiz_get_attempt_data';
        $this->assertEquals([], guard::get_webservice_attempt_ids());
        unset($_POST['wsfunction'], $_POST['attemptid']);
    }

    public function test_backup_and_restore_keeps_setting(): void {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        $this->quiz = $this->create_test_quiz($this->course, settings_provider::USE_SEB_CONFIG_MANUALLY);
        \quizaccess_sebreview::save_settings((object)['id' => $this->quiz->id, 'sebreview_enabled' => 1]);

        $bc = new \backup_controller(
            \backup::TYPE_1ACTIVITY,
            $this->quiz->cmid,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $bc->execute_plan();
        $file = $bc->get_results()['backup_destination'];
        $backupid = 'test-sebreview';
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $CFG->dataroot . '/temp/backup/' . $backupid);
        $bc->destroy();

        $newcourse = $this->getDataGenerator()->create_course();
        $rc = new \restore_controller(
            $backupid,
            $newcourse->id,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_EXISTING_ADDING
        );
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        $newquiz = $DB->get_record('quiz', ['course' => $newcourse->id], '*', MUST_EXIST);
        $this->assertTrue($DB->record_exists('quizaccess_sebreview', ['quizid' => $newquiz->id, 'enabled' => 1]));
    }
}
