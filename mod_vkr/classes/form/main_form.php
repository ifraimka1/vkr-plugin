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

        $needtoprepare = $customdata['needtoprepare'];
        $availablemodules = $customdata['availablemodules'] ?? [];
        $selectedmodules = $customdata['selectedmodules'] ?? [];

        $mform->addElement('hidden', 'id', $customdata['cmid']);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'sesskey', sesskey());
        $mform->setType('id', PARAM_RAW);

        $mform->addElement(
            'date_selector',
            'duedate',
            get_string('duedate', 'mod_vkr'),
            ['optional' => false]
        );
        $mform->setDefault('duedate', time());

        foreach ($availablemodules as $modulekey => $module) {
            $fieldname = 'module_' . $modulekey;
            $mform->addElement('advcheckbox', $fieldname, '', $module['name']);
            $mform->setDefault($fieldname, in_array($modulekey, $selectedmodules, true) ? 1 : 0);
        }

        if ($customdata['needtoprepare']) {
            $mform->addElement(
                'submit',
                'preparebtn',
                get_string('prepare_course', 'mod_vkr')
            );
        } else {
            $buttonarray = [];
            $buttonarray[] = $mform->createElement('submit', 'updatebtn', get_string('update_course', 'mod_vkr'));
            $buttonarray[] = $mform->createElement('submit', 'resetbtn', get_string('reset_course', 'mod_vkr'));
            $mform->addGroup($buttonarray, 'courseactions', '', [' '], false);
        }
    }
}
