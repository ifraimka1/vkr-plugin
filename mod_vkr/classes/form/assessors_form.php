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
        $context = \context_course::instance($course->id);
        
        // Получить роль с shortname 'control'
        $controlrole = get_role_by_shortname('control');
        if (!$controlrole) {
            // Если роль не найдена, используем editingteacher как fallback
            $controlrole = get_role_by_shortname('editingteacher');
        }
        
        // Получить всех пользователей с ролью 'control' в курсе
        $controllers = get_enrolled_users($context, 'mod/assign:grade', 0, 'u.*', null, 0, 0, true);
        $controlleroptions = [];
        foreach ($controllers as $user) {
            $controlleroptions[$user->id] = fullname($user);
        }
        $controlleroptions = array(0 => get_string('noassessor', 'mod_vkr')) + $controlleroptions;

        // Получить текущие назначения оценщиков
        $currentassignments = $this->get_current_assignments($course->id);

        $mform->addElement('hidden', 'id', $customdata['cmid']);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'sesskey', sesskey());
        $mform->setType('sesskey', PARAM_RAW);

        // Создать элемент с двумя колонками: группы слева, выбор оценщиков справа
        $groups = groups_get_all_groups($course->id);
        
        if (!empty($groups)) {
            $groupnames = [];
            $selectelements = [];
            
            foreach ($groups as $group) {
                $groupnames[] = $group->name;
                $selectelements[] = $mform->createElement(
                    'select', 
                    'assessorid_' . $group->id, 
                    '', 
                    $controlleroptions,
                    ['style' => 'width: 200px;']
                );
                
                // Установить текущее значение
                if (isset($currentassignments[$group->id])) {
                    $mform->setDefault('assessorid_' . $group->id, $currentassignments[$group->id]);
                }
            }

            $mform->addGroup($groupnames, 'groupnames_group', get_string('selectgroup', 'mod_vkr'), ' ', false);
            $mform->addGroup($selectelements, 'selectors_group', get_string('selectassessor', 'mod_vkr'), ' ', false);
            
            // Добавить help text
            $mform->addHelpButton('groupnames_group', 'selectgroup', 'mod_vkr');
        }

        $mform->addElement('submit', 'submitbutton', get_string('apply', 'mod_vkr'));
    }

    private function get_course_id() {
        global $DB;
        $cm = get_coursemodule_from_id('vkr', $this->_customdata['cmid'], 0, false, MUST_EXIST);
        return $cm->course;
    }
    
    /**
     * Получить текущие назначения оценщиков для групп
     * Возвращает массив [groupid => assessorid]
     */
    private function get_current_assignments($courseid) {
        global $DB;
        
        // Найти задание "Нормоконтроль"
        $normcontrol = $DB->get_record_sql(
            "SELECT cm.id, a.id AS assignid FROM {course_modules} cm
            JOIN {modules} m ON m.id = cm.module
            JOIN {assign} a ON a.id = cm.instance
            WHERE cm.course = ? AND m.name = 'assign' AND a.name = 'Нормоконтроль'",
            [$courseid]
        );
        
        if (!$normcontrol) {
            return [];
        }
        
        $assignments = [];
        $groups = groups_get_all_groups($courseid);
        
        foreach ($groups as $group) {
            // Получить студентов группы
            $members = groups_get_members($group->id, 'u.id');
            if (empty($members)) {
                continue;
            }
            
            $memberids = array_keys($members);
            
            // Найти назначенного оценщика для первого студента группы
            // (предполагаем, что все студенты группы имеют одного оценщика)
            list($insql, $params) = $DB->get_in_or_equal($memberids, SQL_PARAMS_NAMED);
            $params['assignid'] = $normcontrol->assignid;
            
            $gradinguser = $DB->get_record_select(
                'assign_grading_users',
                "assignment = :assignid AND userid $insql",
                $params,
                'id ASC',
                'userid, graderid'
            );
            
            if ($gradinguser && $gradinguser->graderid) {
                $assignments[$group->id] = $gradinguser->graderid;
            } else {
                $assignments[$group->id] = 0;
            }
        }
        
        return $assignments;
    }
}
