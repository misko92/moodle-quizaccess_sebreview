# Review only in Safe Exam Browser (quizaccess_sebreview)

A quiz access rule for Moodle 5.2–5.3. When it is turned on, students can only review their quiz attempts
(questions, feedback, right answers) inside **Safe Exam Browser**. Everywhere else they see a short explanation
page with a **Launch Safe Exam Browser** button.

Moodle's own Safe Exam Browser rule only protects *taking* a quiz: once review is allowed by the quiz's review
options, students can open the review in any browser or in the Moodle app. This plugin closes that gap, so you
can let students go over an exam with full feedback without the questions leaving the locked-down browser.

## Setting

Quiz settings → **Extra restrictions on attempts** → **Review only in Safe Exam Browser** (Yes/No).

* Needs **Require the use of Safe Exam Browser** to be on (any mode).
* The review options still decide *what* students see and *when*; this setting only decides *where*.
* Teachers with `quizaccess/seb:bypassseb` or `mod/quiz:viewreports` are not affected.
* The setting is included in quiz backups and restores.

## What is protected

| Route | Without Safe Exam Browser |
|---|---|
| `mod/quiz/review.php`, `mod/quiz/reviewquestion.php` | redirected to an explanation page with Launch / Download configuration buttons |
| Question files of a finished attempt (`pluginfile.php`, `webservice/pluginfile.php`, `tokenpluginfile.php`) | "file not found" |
| Moodle app: `mod_quiz_get_attempt_review` (also inside `tool_mobile_call_external_functions`) | error "You can only review this quiz attempt in Safe Exam Browser." |

Review pages use the same check as the Safe Exam Browser rule (config key / browser exam key, depending on the
quiz's SEB mode). Question images only check the Safe Exam Browser user agent. If Safe Exam Browser is later
turned off for the quiz while this setting stays on, the Safe Exam Browser user agent is still required.

## How it works

Moodle does not run access rules when an attempt is reviewed, so the checks run from the `core\hook\after_config`
hook. It only does work for the scripts listed above; every other request returns straight away.

## Limits

* Safe Exam Browser blocks screenshots and other apps on the device, but it can't stop a phone camera.
* To review, students have to open the quiz in Safe Exam Browser again (the button on the explanation page does this).

## Installation

Copy to `mod/quiz/accessrule/sebreview` (or `public/mod/quiz/accessrule/sebreview` on Moodle 5.1+) and visit
Site administration → Notifications.

## License

GNU GPL v3 or later.
