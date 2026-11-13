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

/**
 * Explains that this quiz can only be reviewed in Safe Exam Browser, with a button to launch it.
 *
 * @package    quizaccess_sebreview
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../../config.php');

use mod_quiz\quiz_settings;
use quizaccess_seb\seb_access_manager;

$attemptid = required_param('attempt', PARAM_INT);
$attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], 'id, quiz', MUST_EXIST);
$quizobj = quiz_settings::create((int)$attempt->quiz);
$cm = $quizobj->get_cm();

$PAGE->set_url(new moodle_url('/mod/quiz/accessrule/sebreview/blocked.php', ['attempt' => $attemptid]));
require_login($quizobj->get_course(), false, $cm);
$PAGE->set_title(get_string('blockedheading', 'quizaccess_sebreview'));
$PAGE->set_heading($quizobj->get_course()->fullname);
$PAGE->activityheader->disable();

$manager = new seb_access_manager($quizobj);
if ($manager->seb_required() && $manager->should_validate_config_key()) {
    $message = get_string('blockedmessage', 'quizaccess_sebreview');
} else {
    $message = get_string('blockedmessageclient', 'quizaccess_sebreview');
}
$buttons = \quizaccess_sebreview\local\guard::get_seb_buttons($quizobj);
$buttons .= html_writer::link(
    $quizobj->view_url(),
    get_string('backtoquiz', 'quizaccess_sebreview'),
    ['class' => 'btn btn-secondary mb-2']
);

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($quizobj->get_quiz_name()) . ': ' . get_string('blockedheading', 'quizaccess_sebreview'));
echo $OUTPUT->notification($message, \core\output\notification::NOTIFY_WARNING, false);
echo html_writer::div($buttons, 'mt-3');
echo $OUTPUT->footer();
