# This file is part of Moodle - http://moodle.org/
#
# Moodle is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# Moodle is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with Moodle.  If not, see <http://www.gnu.org/licenses/>.
#
# Behat feature for Collab group sessions.
#
# @package    mod_collaborate
# @author     Guy Thomas
# @copyright  Copyright (c) 2017 Blackboard Ltd
# @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

@mod @mod_collaborate
Feature: Separate sessions are created for the course and individual groups.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | 1        | teacher1@example.com |
      | student1 | Student   | 1        | student1@example.com |
      | student2 | Student   | 2        | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the following "groups" exist:
      | name    | course  | idnumber |
      | Group 1 | C1      | G1       |
      | Group 2 | C1      | G2       |
    And the following "group members" exist:
      | user     | group |
      | student1 | G1    |
      | student2 | G1    |
      | student2 | G2    |

  Scenario: Collaborate instance with group mode enabled shows appropriate options for joining session.
    Given I log in as "teacher1"
    And I follow "Course 1"
    And I turn editing mode on
    And I add a "Collaborate" to section "1" and I fill the form with:
      | Session name | Test collab |
      | Group mode | Separate groups |
    And I follow "Test collab"
    And I should see "No group" in the ".mod-collaborate-group-selector" "css_element"
    And I should see "Group 1" in the ".mod-collaborate-group-selector" "css_element"
    And I should see "Group 2" in the ".mod-collaborate-group-selector" "css_element"
    And ".mod-collaborate-group-selector input[value=\"Join session\"]" "css_element" should exist
    # Note, if you run this scenario with an @javascript tag it breaks on the redirect after pressing "Join session".
    And I press "Join session"
    And I should see "Joined a fake session for the collaborate instance"
    And I log out
    # Log in as student and make sure student doesn't see any group selectors if they are only in one group.
    And I log in as "student1"
    And I follow "Course 1"
    And I follow "Test collab"
    And ".mod-collaborate-group-selector" "css_element" should not exist
    And I should see "Join session" in the "a.btn-success" "css_element"
    And I follow "Join session"
    And I should see "Joined a fake session for group \"Group 1\""
    And I log out
    # Log in as student and make sure student sees group selectors if they are in more than one group.
    And I log in as "student2"
    And I follow "Course 1"
    And I follow "Test collab"
    And ".mod-collaborate-group-selector" "css_element" should exist
    And I should not see "No group" in the ".mod-collaborate-group-selector" "css_element"
    And I should see "Group 1" in the ".mod-collaborate-group-selector" "css_element"
    And I should see "Group 2" in the ".mod-collaborate-group-selector" "css_element"
    And ".mod-collaborate-group-selector input[value=\"Join session\"]" "css_element" should exist
    And I press "Join session"
    And I should see "Joined a fake session for group \"Group 1\""
    And I am on site homepage
    And I follow "Course 1"
    And I follow "Test collab"
    And I check the "Group 2" meeting group radio button
    When I press "Join session"
    Then I should see "Joined a fake session for group \"Group 2\""

  Scenario: Collaborate - deleting a group removes the group from the list of available groups when joining a session.
    Given I log in as "teacher1"
    And I follow "Course 1"
    And I turn editing mode on
    And I add a "Collaborate" to section "1" and I fill the form with:
      | Session name | Test collab |
      | Group mode | Separate groups |
    And I log out
    And I log in as "student2"
    And I follow "Course 1"
    And I follow "Test collab"
    And ".mod-collaborate-group-selector" "css_element" should exist
    And I should see "Group 1" in the ".mod-collaborate-group-selector" "css_element"
    And I should see "Group 2" in the ".mod-collaborate-group-selector" "css_element"
    And the group "Group 1" is deleted
    And I reload the page
    # Student will no longer see any options as they are only in 1 group.
    And ".mod-collaborate-group-selector" "css_element" should not exist
    And I follow "Join session"
    And I should see "Joined a fake session for group \"Group 2\""
