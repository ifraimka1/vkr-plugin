<?php
namespace mod_vkr\form;

use moodleform;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

class assessors_form extends moodleform {
    protected function definition() {
        $mform = $this->_form;
        $customdata = $this->_customdata;

        $course = get_course($this->get_course_id());
        $groups = groups_get_all_groups($course->id);
        $teachers = get_role_users(3, \context_course::instance($course->id)); // Роль 3 — учитель
        $teacheroptions = array_map(function($user) {
            return fullname($user);
        }, $teachers);
        $teacheroptions = array_combine(array_keys($teacheroptions), $teacheroptions);
        $teacheroptions = array(0 => get_string('noassessor', 'mod_vkr')) + $teacheroptions;


        $mform->addElement('hidden', 'id', $customdata['cmid']);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'sesskey', sesskey());
        $mform->setType('sesskey', PARAM_RAW);

        foreach ($groups as $group) {
            $mform->addElement('group', 'group_' . $group->id, $group->name, [
                $mform->createElement('select', 'assessorid_' . $group->id, '', $teacheroptions)
            ], '', false);
        }

        $mform->addElement('submit', 'submitbutton', get_string('apply', 'mod_vkr'));
    }

    private function get_course_id() {
        global $DB;
        $cm = get_coursemodule_from_id('vkr', $this->_customdata['cmid'], 0, false, MUST_EXIST);
        return $cm->course;
    }
}
