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

namespace quizaccess_sebreview\local;

use mod_quiz\quiz_settings;
use moodle_exception;
use moodle_url;
use quizaccess_seb\seb_access_manager;
use stdClass;

/**
 * Keeps attempt reviews of protected quizzes inside Safe Exam Browser.
 *
 * Runs from the after_config hook on every request, but only does work for the
 * few scripts that can show review content:
 *  - mod/quiz/review.php and mod/quiz/reviewquestion.php (redirect to an explanation page),
 *  - question files of a finished attempt (pluginfile.php and friends),
 *  - the mod_quiz_get_attempt_review web service (Moodle app), also inside
 *    tool_mobile_call_external_functions batches.
 *
 * @package    quizaccess_sebreview
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class guard {
    /** @var string[] Scripts that render an attempt review. */
    const REVIEW_SCRIPTS = ['/mod/quiz/review.php', '/mod/quiz/reviewquestion.php'];

    /** @var string[] Scripts that serve files. */
    const FILE_SCRIPTS = ['/pluginfile.php', '/webservice/pluginfile.php', '/tokenpluginfile.php'];

    /** @var string Web service function that returns an attempt review. */
    const REVIEW_FUNCTION = 'mod_quiz_get_attempt_review';

    /** @var array<int, bool> quizid => enabled, per request. */
    protected static $enabled = [];

    /**
     * Hook callback for \core\hook\after_config.
     *
     * @return void
     */
    public static function after_config(): void {
        global $SCRIPT;

        if (
            CLI_SCRIPT || empty($SCRIPT) || during_initial_install()
                || !get_config('quizaccess_sebreview', 'version')
        ) {
            return;
        }

        if (in_array($SCRIPT, self::REVIEW_SCRIPTS, true)) {
            self::check_review_page(optional_param('attempt', 0, PARAM_INT));
        } else if (in_array($SCRIPT, self::FILE_SCRIPTS, true)) {
            if (self::is_file_blocked(get_file_argument())) {
                // Answer like core does when a file may not be seen.
                send_file_not_found();
            }
        } else if (preg_match('~^/webservice/[a-z0-9_]+/(simple)?server\.php$~', $SCRIPT)) {
            self::check_webservice();
        }
    }

    /**
     * Forget cached settings (after a quiz setting changes, and in tests).
     *
     * @return void
     */
    public static function reset_cache(): void {
        self::$enabled = [];
    }

    /**
     * Is "review only in Safe Exam Browser" on for this quiz?
     *
     * @param int $quizid
     * @return bool
     */
    public static function is_enabled(int $quizid): bool {
        global $DB;
        if (!array_key_exists($quizid, self::$enabled)) {
            self::$enabled[$quizid] = $DB->record_exists('quizaccess_sebreview', ['quizid' => $quizid, 'enabled' => 1]);
        }
        return self::$enabled[$quizid];
    }

    /**
     * Review pages: send the user to the explanation page if they may not review here.
     *
     * @param int $attemptid
     * @return void
     */
    public static function check_review_page(int $attemptid): void {
        $attempt = self::get_attempt(['id' => $attemptid]);
        if ($attempt && !self::may_review($attempt, true)) {
            redirect(new moodle_url('/mod/quiz/accessrule/sebreview/blocked.php', ['attempt' => $attempt->id]));
        }
    }

    /**
     * Is this a question file of a finished attempt the user may not review here?
     *
     * Question files in an attempt have paths like .../question/<area>/<usageid>/...
     *
     * @param string|null $relativepath file path from get_file_argument()
     * @return bool
     */
    public static function is_file_blocked(?string $relativepath): bool {
        $usageid = self::get_usage_id_from_path((string)$relativepath);
        if (!$usageid) {
            return false;
        }
        $attempt = self::get_attempt(['uniqueid' => $usageid]);
        return $attempt && !self::may_review($attempt, false);
    }

    /**
     * Find the question usage id in a pluginfile path, or 0.
     *
     * Handles /ctx/question/area/usage/... as well as the token-prefixed forms.
     *
     * @param string $relativepath
     * @return int
     */
    public static function get_usage_id_from_path(string $relativepath): int {
        $args = explode('/', trim($relativepath, '/'));
        foreach ($args as $i => $arg) {
            if (
                $arg === 'question' && $i > 0 && ctype_digit($args[$i - 1]) && isset($args[$i + 2])
                    && ctype_digit($args[$i + 2])
            ) {
                return (int)$args[$i + 2];
            }
        }
        return 0;
    }

    /**
     * Web service requests (Moodle app): refuse attempt reviews of protected quizzes.
     *
     * @return void
     */
    public static function check_webservice(): void {
        global $DB;
        $attemptids = self::get_webservice_attempt_ids();
        if (!$attemptids) {
            return;
        }
        // The user is only known once the web service server has checked the token, so look it up here.
        $token = optional_param('wstoken', '', PARAM_ALPHANUM);
        $userid = $token ? (int)$DB->get_field('external_tokens', 'userid', ['token' => $token]) : 0;
        foreach ($attemptids as $attemptid) {
            $attempt = self::get_attempt(['id' => $attemptid]);
            if ($attempt && !self::may_review($attempt, true, $userid ?: null)) {
                throw new moodle_exception('reviewrequiresseb', 'quizaccess_sebreview');
            }
        }
    }

    /**
     * Attempt ids this web service request wants a review of.
     *
     * @return int[]
     */
    public static function get_webservice_attempt_ids(): array {
        $ids = [];
        $function = optional_param('wsfunction', '', PARAM_ALPHANUMEXT);
        if ($function === self::REVIEW_FUNCTION) {
            $ids[] = optional_param('attemptid', 0, PARAM_INT);
        } else if ($function === 'tool_mobile_call_external_functions') {
            // The app sends a nested array of requests, each with a function name and JSON arguments.
            // The optional_param_array function cannot read nested arrays, so read the raw request.
            $requests = $_POST['requests'] ?? $_GET['requests'] ?? [];
            foreach ((array)$requests as $request) {
                if (!is_array($request) || clean_param($request['function'] ?? '', PARAM_ALPHANUMEXT) !== self::REVIEW_FUNCTION) {
                    continue;
                }
                $arguments = json_decode((string)($request['arguments'] ?? ''), true);
                $ids[] = clean_param($arguments['attemptid'] ?? 0, PARAM_INT);
            }
        }
        return array_values(array_filter(array_map('intval', $ids)));
    }

    /**
     * May the current (or given) user see the review of this attempt from where they are now?
     *
     * @param stdClass $attempt quiz_attempts record
     * @param bool $strict true: full Safe Exam Browser key check; false: Safe Exam Browser user agent is enough
     * @param int|null $userid user to check capabilities for, defaults to the current user
     * @return bool
     */
    public static function may_review(stdClass $attempt, bool $strict, ?int $userid = null): bool {
        if (!self::is_enabled((int)$attempt->quiz)) {
            return true;
        }
        if ($attempt->state !== \mod_quiz\quiz_attempt::FINISHED && $attempt->state !== \mod_quiz\quiz_attempt::ABANDONED) {
            // Still being attempted: the attempt itself is protected by the Safe Exam Browser rule.
            return true;
        }
        if ($userid === null && !isloggedin()) {
            // Let core send the user to the login page.
            return true;
        }
        $quizobj = quiz_settings::create((int)$attempt->quiz);
        $context = $quizobj->get_context();
        if (
            has_capability('quizaccess/seb:bypassseb', $context, $userid)
                || has_capability('mod/quiz:viewreports', $context, $userid)
        ) {
            return true;
        }
        return self::in_seb($quizobj, $strict);
    }

    /**
     * Is this request coming from Safe Exam Browser, set up for this quiz?
     *
     * @param quiz_settings $quizobj
     * @param bool $strict check the config / browser exam keys, not just the user agent
     * @return bool
     */
    public static function in_seb(quiz_settings $quizobj, bool $strict): bool {
        $manager = new seb_access_manager($quizobj);
        if (!$manager->is_using_seb()) {
            return false;
        }
        if (!$strict || !$manager->seb_required()) {
            return true;
        }
        if ($manager->validate_session_access()) {
            // Newer Safe Exam Browser versions send their keys through JavaScript on the quiz page
            // instead of headers; Moodle then remembers in the session that the keys were valid.
            return true;
        }
        return $manager->validate_basic_header()
            && $manager->validate_config_key()
            && $manager->validate_browser_exam_key();
    }

    /**
     * Buttons that open this quiz in Safe Exam Browser, following the quizaccess_seb "showseblinks" setting.
     *
     * Empty when the quiz uses a client-side configuration (nothing to launch).
     *
     * @param quiz_settings $quizobj
     * @return string HTML
     */
    public static function get_seb_buttons(quiz_settings $quizobj): string {
        $manager = new seb_access_manager($quizobj);
        if (!$manager->seb_required() || !$manager->should_validate_config_key()) {
            return '';
        }
        $cmid = $quizobj->get_cmid();
        $linkconfig = explode(',', (string)get_config('quizaccess_seb', 'showseblinks'));
        $buttons = '';
        if (in_array('seb', $linkconfig)) {
            // A plain link, not a form button, so browsers don't warn about the sebs:// scheme.
            $buttons .= \html_writer::link(
                \quizaccess_seb\link_generator::get_link($cmid, true, is_https()),
                get_string('seblinkbutton', 'quizaccess_seb'),
                ['class' => 'btn btn-primary me-2 mb-2']
            );
        }
        if (in_array('http', $linkconfig)) {
            $buttons .= \html_writer::link(
                \quizaccess_seb\link_generator::get_link($cmid, false, is_https()),
                get_string('httplinkbutton', 'quizaccess_seb'),
                ['class' => 'btn btn-secondary me-2 mb-2']
            );
        }
        return $buttons;
    }

    /**
     * Fetch the attempt fields we need, or null.
     *
     * @param array $conditions
     * @return stdClass|null
     */
    protected static function get_attempt(array $conditions): ?stdClass {
        global $DB;
        if (!array_filter($conditions)) {
            return null;
        }
        return $DB->get_record('quiz_attempts', $conditions, 'id, quiz, userid, state, uniqueid') ?: null;
    }
}
