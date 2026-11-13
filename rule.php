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

use mod_quiz\local\access_rule_base;
use mod_quiz\quiz_settings;

/**
 * Quiz access rule: attempts may only be reviewed in Safe Exam Browser.
 *
 * This class only provides the quiz setting and the notice on the quiz page.
 * The review pages, question files and the review web service are guarded by
 * {@see \quizaccess_sebreview\local\guard}, because Moodle does not run access
 * rules when an attempt is reviewed.
 *
 * @package    quizaccess_sebreview
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quizaccess_sebreview extends access_rule_base {
    #[\Override]
    public static function make(quiz_settings $quizobj, $timenow, $canignoretimelimits) {
        if (empty($quizobj->get_quiz()->sebreview_enabled)) {
            return null;
        }
        return new self($quizobj, $timenow);
    }

    #[\Override]
    public function description() {
        $messages = [get_string('description', 'quizaccess_sebreview')];
        if ($this->should_offer_launch()) {
            $buttons = \quizaccess_sebreview\local\guard::get_seb_buttons($this->quizobj);
            if ($buttons !== '') {
                $messages[] = \html_writer::div($buttons, 'mt-2');
            }
        }
        return $messages;
    }

    /**
     * Should the quiz page offer a way into Safe Exam Browser to review a finished attempt?
     *
     * Core's Safe Exam Browser rule only shows its launch buttons when a new attempt can be
     * started, so once a student has used their attempts there is no way back in. Offer the
     * buttons then, but only when there is something to review there.
     *
     * @return bool
     */
    protected function should_offer_launch(): bool {
        global $CFG, $USER;
        if (!isloggedin() || isguestuser()) {
            return false;
        }
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        if ((new \quizaccess_seb\seb_access_manager($this->quizobj))->is_using_seb()) {
            // Already in Safe Exam Browser: launching it again would only confuse.
            return false;
        }
        $attempts = quiz_get_user_attempts($this->quiz->id, $USER->id, 'all');
        if (!$attempts) {
            return false;
        }
        $finished = [];
        foreach ($attempts as $attempt) {
            if ($attempt->state === \mod_quiz\quiz_attempt::IN_PROGRESS || $attempt->state === \mod_quiz\quiz_attempt::OVERDUE) {
                // Core shows its own launch buttons to continue the attempt.
                return false;
            }
            $finished[] = $attempt;
        }
        $last = end($finished);
        if (!$this->quizobj->get_access_manager($this->timenow)->prevent_new_attempt(count($finished), $last)) {
            // A new attempt can be started, so core shows its launch buttons.
            return false;
        }
        foreach ($finished as $attempt) {
            if (\quizaccess_sebreview\local\guard::may_review($attempt, true)) {
                // Already in Safe Exam Browser, or allowed to review anywhere.
                return false;
            }
            $options = \mod_quiz\question\display_options::make_from_quiz(
                $this->quiz,
                quiz_attempt_state($this->quiz, $attempt)
            );
            if ($options->attempt) {
                return true;
            }
        }
        return false;
    }

    #[\Override]
    public static function add_settings_form_fields(mod_quiz_mod_form $quizform, MoodleQuickForm $mform) {
        $mform->addElement('selectyesno', 'sebreview_enabled', get_string('enabled', 'quizaccess_sebreview'));
        $mform->addHelpButton('sebreview_enabled', 'enabled', 'quizaccess_sebreview');
        $mform->setDefault('sebreview_enabled', 0);
    }

    #[\Override]
    public static function validate_settings_form_fields(array $errors, array $data, $files, mod_quiz_mod_form $quizform) {
        if (
            !empty($data['sebreview_enabled']) && array_key_exists('seb_requiresafeexambrowser', $data)
                && (int)$data['seb_requiresafeexambrowser'] === \quizaccess_seb\settings_provider::USE_SEB_NO
        ) {
            $errors['sebreview_enabled'] = get_string('requiresseb', 'quizaccess_sebreview');
        }
        return $errors;
    }

    #[\Override]
    public static function save_settings($quiz) {
        global $DB;
        if (!isset($quiz->sebreview_enabled)) {
            // Not on the form (e.g. quiz created by a generator or web service): leave as is.
            return;
        }
        $enabled = empty($quiz->sebreview_enabled) ? 0 : 1;
        $record = $DB->get_record('quizaccess_sebreview', ['quizid' => $quiz->id]);
        if ($record) {
            if ((int)$record->enabled !== $enabled) {
                $DB->set_field('quizaccess_sebreview', 'enabled', $enabled, ['id' => $record->id]);
            }
        } else if ($enabled) {
            $DB->insert_record('quizaccess_sebreview', (object)['quizid' => $quiz->id, 'enabled' => 1]);
        }
        \quizaccess_sebreview\local\guard::reset_cache();
    }

    #[\Override]
    public static function delete_settings($quiz) {
        global $DB;
        $DB->delete_records('quizaccess_sebreview', ['quizid' => $quiz->id]);
        \quizaccess_sebreview\local\guard::reset_cache();
    }

    #[\Override]
    public static function get_settings_sql($quizid) {
        return [
            'COALESCE(sebreview.enabled, 0) AS sebreview_enabled',
            'LEFT JOIN {quizaccess_sebreview} sebreview ON sebreview.quizid = quiz.id',
            [],
        ];
    }
}
