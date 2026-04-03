<?php
namespace mod_vkr;

class assessors_manager {
    public static function assign_assessor($courseid, $groupid, $assessorid) {
        global $DB;

        // Найти задание "Нормоконтроль"
        $normcontrol = $DB->get_record_sql(
            "SELECT cm.id FROM {course_modules} cm
            JOIN {modules} m ON m.id = cm.module
            JOIN {assign} a ON a.id = cm.instance
            WHERE cm.course = ? AND m.name = 'assign' AND a.name = 'Нормоконтроль'",
            [$courseid]
        );

        if (!$normcontrol) {
            return false;
        }

        // Получить студентов группы
        $students = groups_get_members($groupid);
        $assign = new \assign(\context_module::instance($normcontrol->id), false, false);

        foreach ($students as $student) {
            if ($assessorid) {
                // Назначить оценщика
                $assign->set_grader($student->id, $assessorid);
            } else {
                // Убрать оценщика
                $assign->remove_grader($student->id);
            }
        }

        return true;
    }
}
