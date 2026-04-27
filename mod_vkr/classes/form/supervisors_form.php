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

use moodleform;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form for assigning VKR supervisors to students.
 *
 * @package     mod_vkr
 * @copyright   2025 Ifraim Solomonov <solomonov@sfedu.ru>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class supervisors_form extends moodleform {
    /**
     * Form definition.
     *
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;
        $customdata = $this->_customdata;

        $cmid = (int)$customdata['cmid'];
        $supervisors = $customdata['supervisors'] ?? [];
        $students = $customdata['students'] ?? [];
        $currentmapping = $customdata['currentmapping'] ?? [];

        $studentoptions = [];
        foreach ($students as $student) {
            $studentoptions[(int)$student->id] = fullname($student);
        }

        $mform->addElement('hidden', 'id', $cmid);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'tab', 'supervisors');
        $mform->setType('tab', PARAM_ALPHA);
        $mform->addElement('hidden', 'savevkrsupervisors', 1);
        $mform->setType('savevkrsupervisors', PARAM_INT);

        foreach ($supervisors as $supervisor) {
            $fieldname = 'supervisor_students_' . (int)$supervisor->id;
            $mform->addElement(
                'autocomplete',
                $fieldname,
                fullname($supervisor),
                $studentoptions,
                [
                    'multiple' => true,
                    'noselectionstring' => get_string('select_option', 'mod_vkr'),
                ]
            );
            $mform->setDefault($fieldname, $currentmapping[(int)$supervisor->id] ?? []);
        }

        $this->add_action_buttons(false, get_string('savechanges'));
    }
}
