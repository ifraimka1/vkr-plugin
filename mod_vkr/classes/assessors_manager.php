<?php
namespace mod_vkr;

class assessors_manager {
    /**
     * Назначить оценщика для студентов группы в задании "Нормоконтроль"
     * 
     * @param int $courseid ID курса
     * @param int $groupid ID группы
     * @param int|null $assessorid ID оценщика (null для удаления назначения)
     * @return bool true при успехе, false при ошибке
     */
    public static function assign_assessor($courseid, $groupid, $assessorid) {
        global $DB;

        // Найти задание "Нормоконтроль"
        $normcontrol = self::get_normcontrol_assign($courseid);
        if (!$normcontrol) {
            return false;
        }

        // Получить студентов группы
        $students = groups_get_members($groupid);
        if (empty($students)) {
            return true; // Нет студентов в группе - ничего не делаем
        }

        $context = \context_module::instance($normcontrol->cmid);
        
        foreach ($students as $student) {
            if ($assessorid) {
                // Назначить оценщика через marking allocation
                self::set_grader($context, $normcontrol->assignid, $student->id, $assessorid);
            } else {
                // Убрать оценщика
                self::remove_grader($context, $normcontrol->assignid, $student->id);
            }
        }

        return true;
    }

    /**
     * Получить информацию о задании "Нормоконтроль"
     * 
     * @param int $courseid ID курса
     * @return object|null Объект с cmid и assignid или null если не найдено
     */
    private static function get_normcontrol_assign($courseid) {
        global $DB;

        $record = $DB->get_record_sql(
            "SELECT cm.id AS cmid, a.id AS assignid
             FROM {course_modules} cm
             JOIN {modules} m ON m.id = cm.module
             JOIN {assign} a ON a.id = cm.instance
             WHERE cm.course = ? AND m.name = 'assign' AND a.name = 'Нормоконтроль'",
            [$courseid]
        );

        return $record ?: null;
    }

    /**
     * Назначить оценщика для студента в задании
     * 
     * @param \context_module $context Контекст модуля
     * @param int $assignid ID задания
     * @param int $studentid ID студента
     * @param int $assessorid ID оценщика
     */
    private static function set_grader($context, $assignid, $studentid, $assessorid) {
        global $DB;

        // Проверяем, существует ли уже назначение
        $existing = $DB->get_record('assign_marking_allocation', [
            'assignment' => $assignid,
            'userid' => $studentid
        ]);

        if ($existing) {
            // Обновляем существующее назначение
            $DB->set_field('assign_marking_allocation', 'allocatorid', $assessorid, ['id' => $existing->id]);
        } else {
            // Создаем новое назначение
            $allocation = new \stdClass();
            $allocation->assignment = $assignid;
            $allocation->userid = $studentid;
            $allocation->allocatorid = $assessorid;
            $DB->insert_record('assign_marking_allocation', $allocation);
        }
    }

    /**
     * Удалить назначение оценщика для студента
     * 
     * @param \context_module $context Контекст модуля
     * @param int $assignid ID задания
     * @param int $studentid ID студента
     */
    private static function remove_grader($context, $assignid, $studentid) {
        global $DB;

        $DB->delete_records('assign_marking_allocation', [
            'assignment' => $assignid,
            'userid' => $studentid
        ]);
    }

    /**
     * Получить текущего оценщика для студента в задании "Нормоконтроль"
     * 
     * @param int $courseid ID курса
     * @param int $studentid ID студента
     * @return int|null ID оценщика или null если не назначен
     */
    public static function get_assessor_for_student($courseid, $studentid) {
        global $DB;

        $normcontrol = self::get_normcontrol_assign($courseid);
        if (!$normcontrol) {
            return null;
        }

        $allocation = $DB->get_record('assign_marking_allocation', [
            'assignment' => $normcontrol->assignid,
            'userid' => $studentid
        ]);

        return $allocation ? (int)$allocation->allocatorid : null;
    }

    /**
     * Получить назначенного оценщика для группы (по первому студенту в группе)
     * 
     * @param int $courseid ID курса
     * @param int $groupid ID группы
     * @return int|null ID оценщика или null если не назначен
     */
    public static function get_assessor_for_group($courseid, $groupid) {
        $students = groups_get_members($groupid);
        if (empty($students)) {
            return null;
        }

        // Берем первого студента для определения оценщика группы
        $firststudent = reset($students);
        return self::get_assessor_for_student($courseid, $firststudent->id);
    }

    /**
     * Получить пользователей с ролью "нормоконтроль" в курсе
     * 
     * @param int $courseid ID курса
     * @return array Массив пользователей [id => fullname]
     */
    public static function get_normcontrol_teachers($courseid) {
        global $DB;
        
        $context = \context_course::instance($courseid);
        
        // Получаем роль по шортнейму 'control'
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'control']);
        if (!$roleid) {
            // Если роль с шортнеймом не найдена, пробуем найти по имени
            $roleid = $DB->get_field('role', 'id', ['name' => 'Нормоконтроль']);
        }

        if (!$roleid) {
            return [];
        }

        $users = get_role_users($roleid, $context, false, 'u.id, u.firstname, u.lastname, u.email');
        
        $result = [];
        foreach ($users as $user) {
            $result[$user->id] = fullname($user);
        }

        return $result;
    }
}
