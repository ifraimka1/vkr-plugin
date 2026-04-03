<?php
namespace mod_vkr\form;

use moodleform;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

class assessors_form extends moodleform {
    protected function definition() {
        $mform = $this->_form;
        $customdata = $this->_customdata;

        $courseid = $this->get_course_id();
        $course = get_course($courseid);
        $groups = groups_get_all_groups($course->id);
        
        // Получаем пользователей с ролью "нормоконтроль" через assessors_manager
        $teachers = \mod_vkr\assessors_manager::get_normcontrol_teachers($courseid);
        
        $teacheroptions = [0 => get_string('noassessor', 'mod_vkr')] + $teachers;

        $mform->addElement('hidden', 'id', $customdata['cmid']);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'sesskey', sesskey());
        $mform->setType('sesskey', PARAM_RAW);

        foreach ($groups as $group) {
            // Получаем текущего оценщика для группы
            $currentassessor = \mod_vkr\assessors_manager::get_assessor_for_group($courseid, $group->id);
            
            $elementname = 'assessorid_' . $group->id;
            $mform->addElement('select', $elementname, $group->name, $teacheroptions);
            
            // Устанавливаем значение по умолчанию, если оценщик уже назначен
            if ($currentassessor !== null) {
                $mform->setDefault($elementname, $currentassessor);
            }
        }

        $mform->addElement('submit', 'submitbutton', get_string('apply', 'mod_vkr'));
    }

    private function get_course_id() {
        global $DB;
        $cm = get_coursemodule_from_id('vkr', $this->_customdata['cmid'], 0, false, MUST_EXIST);
        return $cm->course;
    }
}
