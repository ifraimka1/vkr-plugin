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

namespace mod_vkr;

defined('MOODLE_INTERNAL') || die();

/**
 * Helper methods for group assessor allocation in the "Нормоконтроль" assignment.
 *
 * @package     mod_vkr
 * @copyright   2025 Ifraim Solomonov <solomonov@sfedu.ru>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assessors_manager {
    /**
     * Assign an assessor to all members of the specified group.
     *
     * @param int $courseid
     * @param int $groupid
     * @param int|null $assessorid
     * @return bool
     */
    public static function assign_assessor(int $courseid, int $groupid, ?int $assessorid): bool {
        self::log('Starting assessor assignment', [
            'courseid' => $courseid,
            'groupid' => $groupid,
            'assessorid' => $assessorid,
        ]);

        $assignment = self::get_normcontrol_assign($courseid);
        if (!$assignment) {
            self::log('Normcontrol assignment was not found', [
                'courseid' => $courseid,
            ]);
            return false;
        }

        $students = groups_get_members($groupid);
        self::log('Resolved group members', [
            'groupid' => $groupid,
            'count' => count($students),
            'userids' => array_map(static function($student) {
                return (int)$student->id;
            }, $students),
        ]);

        foreach ($students as $student) {
            $flags = $assignment->get_user_flags($student->id, true);
            self::log('Loaded current user flags', [
                'userid' => (int)$student->id,
                'flagsid' => (int)($flags->id ?? 0),
                'currentallocatedmarker' => (int)($flags->allocatedmarker ?? 0),
            ]);
            $flags->allocatedmarker = (int)($assessorid ?? 0);

            if (!$assignment->update_user_flags($flags)) {
                self::log('Failed to update user flags', [
                    'userid' => (int)$student->id,
                    'flagsid' => (int)($flags->id ?? 0),
                    'allocatedmarker' => (int)$flags->allocatedmarker,
                ]);
                return false;
            }

            self::log('Updated user flags', [
                'userid' => (int)$student->id,
                'flagsid' => (int)($flags->id ?? 0),
                'allocatedmarker' => (int)$flags->allocatedmarker,
            ]);
        }

        self::log('Finished assessor assignment', [
            'courseid' => $courseid,
            'groupid' => $groupid,
            'assessorid' => $assessorid,
        ]);
        return true;
    }

    /**
     * Get selected assessor for each course group if allocation is consistent across all members.
     *
     * @param int $courseid
     * @return array<int, int>
     */
    public static function get_group_assessor_map(int $courseid): array {
        $assignment = self::get_normcontrol_assign($courseid);
        if (!$assignment) {
            self::log('Skipping assessor map build because normcontrol assignment was not found', [
                'courseid' => $courseid,
            ]);
            return [];
        }

        $groups = groups_get_all_groups($courseid);
        $mapping = [];

        foreach ($groups as $group) {
            $students = groups_get_members($group->id);
            if (empty($students)) {
                $mapping[$group->id] = 0;
                continue;
            }

            $allocatedmarkers = [];
            foreach ($students as $student) {
                $flags = $assignment->get_user_flags($student->id, false);
                $allocatedmarkers[] = (int)($flags->allocatedmarker ?? 0);
            }

            $allocatedmarkers = array_values(array_unique($allocatedmarkers));
            $mapping[$group->id] = count($allocatedmarkers) === 1 ? $allocatedmarkers[0] : 0;
        }

        self::log('Built group assessor map', [
            'courseid' => $courseid,
            'mapping' => $mapping,
        ]);
        return $mapping;
    }

    /**
     * Get enrolled course users with role shortname "control".
     *
     * @param int $courseid
     * @return array<int, \stdClass>
     */
    public static function get_control_role_users(int $courseid): array {
        global $DB;

        $role = $DB->get_record('role', ['shortname' => 'control'], 'id', IGNORE_MISSING);
        if (!$role) {
            self::log('Role with shortname control was not found', [
                'courseid' => $courseid,
            ]);
            return [];
        }

        $coursecontext = \context_course::instance($courseid);
        $users = get_role_users(
            $role->id,
            $coursecontext,
            false,
            'u.id, u.firstname, u.lastname, u.middlename, u.alternatename, u.firstnamephonetic, u.lastnamephonetic, u.email',
            'u.lastname ASC, u.firstname ASC'
        );

        foreach ($users as $userid => $user) {
            if (!is_enrolled($coursecontext, $user, '', true)) {
                unset($users[$userid]);
            }
        }

        self::log('Resolved control role users', [
            'courseid' => $courseid,
            'userids' => array_keys($users),
        ]);
        return $users;
    }

    /**
     * Render the assessors management form using Moodle HTML helpers.
     *
     * @param int $cmid
     * @param int $courseid
     * @return string
     */
    public static function render_assessors_form(int $cmid, int $courseid): string {
        $groups = groups_get_all_groups($courseid, 0, 0, 'g.*', 'name ASC');
        $teachers = self::get_control_role_users($courseid);
        $currentassessors = self::get_group_assessor_map($courseid);

        $options = [0 => get_string('noassessor', 'mod_vkr')];
        foreach ($teachers as $teacher) {
            $options[$teacher->id] = fullname($teacher);
        }

        $table = new \html_table();
        $table->head = [
            get_string('group', 'group'),
            get_string('assessors', 'mod_vkr'),
        ];
        $table->attributes['class'] = 'generaltable';

        foreach ($groups as $group) {
            $select = \html_writer::select(
                $options,
                'assessorid_' . $group->id,
                $currentassessors[$group->id] ?? 0,
                false
            );

            $table->data[] = [
                format_string($group->name),
                $select,
            ];
        }

        $hidden = \html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'id',
            'value' => $cmid,
        ]);
        $hidden .= \html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'tab',
            'value' => 'assessors',
        ]);
        $hidden .= \html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'saveassessors',
            'value' => 1,
        ]);
        $hidden .= \html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'sesskey',
            'value' => sesskey(),
        ]);

        $submit = \html_writer::empty_tag('input', [
            'type' => 'submit',
            'class' => 'btn btn-primary',
            'value' => get_string('apply', 'mod_vkr'),
        ]);

        return \html_writer::start_tag('form', [
            'method' => 'post',
            'action' => (new \moodle_url('/mod/vkr/view.php'))->out(false),
        ]) .
            $hidden .
            \html_writer::table($table) .
            \html_writer::div($submit, 'mt-3') .
            \html_writer::end_tag('form');
    }

    /**
     * Resolve the "Нормоконтроль" assignment in the course.
     *
     * @param int $courseid
     * @return \assign|null
     */
    private static function get_normcontrol_assign(int $courseid): ?\assign {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $cmidnumber = \local_vkr\course_builder::get_module_idnumber('normcontrol');
        $record = $DB->get_record_sql(
            "SELECT a.id, cm.id AS cmid
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
               JOIN {assign} a ON a.id = cm.instance
              WHERE cm.course = :courseid
                AND m.name = :modname
                AND cm.idnumber = :cmidnumber",
            [
                'courseid' => $courseid,
                'modname' => 'assign',
                'cmidnumber' => $cmidnumber,
            ]
        );

        if (!$record) {
            self::log('SQL did not find the normcontrol assignment', [
                'courseid' => $courseid,
            ]);
            return null;
        }

        $course = get_course($courseid);
        $cm = get_coursemodule_from_id('assign', $record->cmid, $courseid, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        self::log('Resolved normcontrol assignment', [
            'courseid' => $courseid,
            'cmid' => (int)$cm->id,
            'assignid' => (int)$record->id,
        ]);
        return new \assign($context, $cm, $course);
    }

    /**
     * Write a diagnostic message for server-side troubleshooting.
     *
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    private static function log(string $message, array $context = []): void {
        $line = '[mod_vkr][assessors] ' . $message;
        if (!empty($context)) {
            $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json !== false) {
                $line .= ' ' . $json;
            }
        }

        global $CFG;

        error_log($line);

        if (!empty($CFG->debug) && ((int)$CFG->debug & DEBUG_DEVELOPER)) {
            debugging($line, DEBUG_DEVELOPER);
        }
    }
}
