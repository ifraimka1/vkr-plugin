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

namespace mod_vkr\form;

use mod_vkr\assessors_manager;
use moodleform;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form for bulk assessor assignment by course group.
 *
 * @package     mod_vkr
 * @copyright   2025 Ifraim Solomonov <solomonov@sfedu.ru>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assessors_form extends moodleform {
    /**
     * Form definition.
     *
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;
        $customdata = $this->_customdata;

        $course = get_course($this->get_course_id());
        $groups = groups_get_all_groups($course->id, 0, 0, 'g.*', 'name ASC');
        $teachers = assessors_manager::get_control_role_users($course->id);
        $currentassessors = $customdata['currentassessors'] ?? [];

        $teacheroptions = [0 => get_string('noassessor', 'mod_vkr')];
        foreach ($teachers as $teacher) {
            $teacheroptions[$teacher->id] = fullname($teacher);
        }

        $mform->addElement('hidden', 'id', $customdata['cmid']);
        $mform->setType('id', PARAM_INT);

        foreach ($groups as $group) {
            $mform->addElement(
                'select',
                'assessorid_' . $group->id,
                format_string($group->name),
                $teacheroptions,
                ['class' => 'custom-select mod-vkr-assessors__select']
            );
            $mform->setDefault('assessorid_' . $group->id, $currentassessors[$group->id] ?? 0);
        }

        $this->add_action_buttons(false, get_string('apply', 'mod_vkr'));
    }

    /**
     * Resolve the course from the module instance.
     *
     * @return int
     */
    private function get_course_id(): int {
        $cm = get_coursemodule_from_id('vkr', $this->_customdata['cmid'], 0, false, MUST_EXIST);
        return (int)$cm->course;
    }
}
