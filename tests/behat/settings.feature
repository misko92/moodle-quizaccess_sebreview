@quizaccess @quizaccess_sebreview
Feature: Review only in Safe Exam Browser setting
  In order to keep exam questions and feedback inside Safe Exam Browser
  As a teacher
  I need to be able to require Safe Exam Browser for reviewing quiz attempts

  Background:
    Given the following "users" exist:
      | username | firstname | lastname |
      | teacher  | Terry     | Teacher  |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user    | course | role           |
      | teacher | C1     | editingteacher |
    And the following "activities" exist:
      | activity | name       | course |
      | quiz     | Final exam | C1     |

  Scenario: The setting needs Safe Exam Browser to be required
    Given I am on the "Final exam" "quiz activity editing" page logged in as "teacher"
    And I expand all fieldsets
    When I set the field "Review only in Safe Exam Browser" to "Yes"
    And I press "Save and display"
    Then I should see "Turn on \"Require the use of Safe Exam Browser\" as well"
    And I set the field "Require the use of Safe Exam Browser" to "Yes – Configure manually"
    And I press "Save and display"
    And I should see "You can only review your attempts at this quiz in Safe Exam Browser."
    And I am on the "Final exam" "quiz activity editing" page
    And the field "Review only in Safe Exam Browser" matches value "Yes"
