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
 * Form for main page.
 *
 * @package     mod_vkr
 * @copyright   2025 Ifraim Solomonov solomonov@sfedu.ru
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_vkr\form;

use moodleform;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

class main_form extends moodleform {
    protected function definition() {
        $mform = $this->_form;
        $customdata = $this->_customdata;

        $needtoprepare = !empty($customdata['needtoprepare']);
        $availablemodules = $customdata['availablemodules'] ?? [];
        $selectedmodules = $customdata['selectedmodules'] ?? [];
        $specialityoptions = $customdata['specialityoptions'] ?? [];
        $yearoptions = $customdata['yearoptions'] ?? [];
        $selectedspeciality = $customdata['selectedspeciality'] ?? '';
        $selectedcourseyear = (int)($customdata['selectedcourseyear'] ?? date('Y'));
        $moduleduedates = $customdata['moduleduedates'] ?? [];
        $instructionurl = trim((string)get_config('local_vkr', 'instructionurl'));

        if ($instructionurl !== '') {
            $mform->addElement(
                'html',
                \html_writer::div(
                    \html_writer::link(
                        new \moodle_url($instructionurl),
                        get_string('viewinstruction', 'mod_vkr'),
                        ['target' => '_blank', 'rel' => 'noopener noreferrer']
                    ),
                    'vkr-instruction-link'
                )
            );
        }

        $mform->addElement('hidden', 'id', $customdata['cmid']);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'sesskey', sesskey());
        $mform->setType('sesskey', PARAM_RAW);

        $mform->addElement(
            'select',
            'speciality',
            get_string('speciality', 'mod_vkr'),
            ['' => get_string('select_option', 'mod_vkr')] + $specialityoptions
        );
        $mform->setType('speciality', PARAM_TEXT);
        $mform->setDefault('speciality', $selectedspeciality);

        $mform->addElement(
            'select',
            'courseyear',
            get_string('courseyear', 'mod_vkr'),
            ['' => get_string('select_option', 'mod_vkr')] + $yearoptions
        );
        $mform->setType('courseyear', PARAM_INT);
        $mform->setDefault('courseyear', $selectedcourseyear);

        $mform->addElement('header', 'taskstogenerate', get_string('taskstogenerate', 'mod_vkr'));
        foreach ($availablemodules as $modulekey => $module) {
            $fieldname = 'module_' . $modulekey;
            $duedatefield = 'duedate_' . $modulekey;
            $taskrow = [];
            $taskrow[] = $mform->createElement('advcheckbox', $fieldname, '', $module['name']);
            $taskrow[] = $mform->createElement('date_selector', $duedatefield, '', ['optional' => false]);
            $mform->addGroup($taskrow, 'taskrow_' . $modulekey, '', '', false);
            $mform->setDefault($fieldname, in_array($modulekey, $selectedmodules, true) ? 1 : 0);
            $mform->setDefault($duedatefield, $moduleduedates[$modulekey] ?? time());
        }

        if ($needtoprepare) {
            $mform->addElement(
                'submit',
                'preparebtn',
                get_string('prepare_course', 'mod_vkr')
            );
            $mform->closeHeaderBefore('preparebtn');
        } else {
            $buttonarray = [];
            $buttonarray[] = $mform->createElement('submit', 'updatebtn', get_string('update_course', 'mod_vkr'));
            $buttonarray[] = $mform->createElement('submit', 'resetbtn', get_string('reset_course', 'mod_vkr'));
            $mform->addGroup($buttonarray, 'courseactions', '', [' '], false);
            $mform->closeHeaderBefore('courseactions');
        }
    }

    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        if (!empty($data['preparebtn']) || !empty($data['updatebtn'])) {
            $specialityoptions = $this->_customdata['specialityoptions'] ?? [];
            $yearoptions = $this->_customdata['yearoptions'] ?? [];

            if (empty($data['speciality']) ||
                    !array_key_exists($data['speciality'], $specialityoptions)) {
                $errors['speciality'] = get_string('error_speciality', 'mod_vkr');
            }

            $yearkey = (string)($data['courseyear'] ?? '');
            if ($yearkey === '' || !array_key_exists($yearkey, $yearoptions)) {
                $errors['courseyear'] = get_string('error_courseyear', 'mod_vkr');
            }
        }

        return $errors;
    }
}
