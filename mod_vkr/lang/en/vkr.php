<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Plugin strings are defined here.
 *
 * @package     mod_vkr
 * @category    string
 * @copyright   2025 Ifraim Solomonov solomonov@sfedu.ru
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

//general
$string['pluginname'] = 'VKR';
$string['pluginadministration'] = 'VKR administration';
$string['modulename'] = 'VKR';
$string['modulenameplural'] = 'VKR Activities';

//mdl_form
$string['vkrname'] = 'Title';
$string['vkrname_help'] = 'Enter the name for this VKR activity.';

$string['main'] = 'Main';
$string['prepare_course'] = 'Prepare course';
$string['prepare_course_help'] = 'Initialize course: create sections and default mods';
$string['notification_courseprepared'] = 'Course prepared successfully';
$string['prepared_course_help'] = 'Course already prepared';
$string['reset_course'] = 'Reset course';
$string['update_course'] = 'Update course';
$string['notification_coursereset'] = 'Course reset successfully';
$string['notification_courseupdated'] = 'Course updated successfully';
$string['duedate'] = 'Deadline for assignments';
$string['speciality'] = 'Training direction';
$string['courseyear'] = 'Year';
$string['select_option'] = 'Select...';
$string['error_speciality'] = 'Select a training direction';
$string['error_courseyear'] = 'Select a year';

//assessors
$string['assessors'] = 'Assessors';
$string['noassessor'] = 'No assessor';
$string['assessorblock_normcontrol'] = 'Normcontrol';
$string['assessorblock_review'] = 'Reviewer';
$string['assessorassignmentmissing'] = 'Assignment "{$a}" is missing';
$string['reviewer'] = 'Reviewer';
$string['reviewers'] = 'Reviewers';
$string['reviewassignmentmissing'] = 'Reviewer assignment is missing';
$string['selectgroup'] = 'Select group';
$string['selectassessor'] = 'Select assessor';
$string['apply'] = 'Apply';
$string['notification_assessorassigned'] = 'Assessor assigned successfully.';
$string['notification_assessorerror'] = 'Error assigning assessor.';
$string['vkrsupervisors'] = 'VKR Supervisors';
$string['students'] = 'Students';
$string['notification_supervisorssaved'] = 'Supervisor assignments saved successfully.';
$string['notification_supervisorssaveerror'] = 'Error saving supervisor assignments.';
$string['notification_supervisormappingduplicate'] = 'A student can be assigned to only one VKR supervisor.';
$string['notification_reviewerssaved'] = 'Reviewer assignments saved successfully.';
$string['notification_reviewerssaveerror'] = 'Error saving reviewer assignments.';
$string['notification_reviewermappingduplicate'] = 'A student can be assigned to only one reviewer.';
$string['taskstogenerate'] = 'Assignments to create';
$string['singleinstanceonly'] = 'Only one VKR module is allowed per course';
