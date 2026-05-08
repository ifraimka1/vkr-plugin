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
     * Get assessor sections configuration for the "Оценщики" tab.
     *
     * @return array<int, array<string, string>>
     */
    public static function get_assessor_sections(): array {
        return [
            [
                'modulekey' => 'normcontrol',
                'roleshortname' => 'control',
                'titlestring' => 'assessorblock_normcontrol',
            ],
        ];
    }

    /**
     * Build assessor field name for submitted form.
     *
     * @param string $modulekey
     * @param int $groupid
     * @return string
     */
    public static function get_assessor_field_name(string $modulekey, int $groupid): string {
        return 'assessorid_' . $modulekey . '_' . $groupid;
    }

    /**
     * Checks whether the assignment for assessor section exists in course.
     *
     * @param int $courseid
     * @param string $modulekey
     * @return bool
     */
    public static function is_assessor_assignment_available(int $courseid, string $modulekey): bool {
        return self::get_assign_by_module_key($courseid, $modulekey) !== null;
    }

    /**
     * Assign an assessor to all members of the specified group.
     *
     * @param int $courseid
     * @param int $groupid
     * @param int|null $assessorid
     * @param string $modulekey
     * @return bool
     */
    public static function assign_assessor(
        int $courseid,
        int $groupid,
        ?int $assessorid,
        string $modulekey = 'normcontrol'
    ): bool {
        self::log('Starting assessor assignment', [
            'courseid' => $courseid,
            'groupid' => $groupid,
            'assessorid' => $assessorid,
            'modulekey' => $modulekey,
        ]);

        $assignment = self::get_assign_by_module_key($courseid, $modulekey);
        if (!$assignment) {
            self::log('Assessor assignment was not found', [
                'courseid' => $courseid,
                'modulekey' => $modulekey,
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
            'modulekey' => $modulekey,
        ]);
        return true;
    }

    /**
     * Get selected assessor for each course group if allocation is consistent across all members.
     *
     * @param int $courseid
     * @return array<int, int>
     */
    public static function get_group_assessor_map(int $courseid, string $modulekey = 'normcontrol'): array {
        $assignment = self::get_assign_by_module_key($courseid, $modulekey);
        if (!$assignment) {
            self::log('Skipping assessor map build because assignment was not found', [
                'courseid' => $courseid,
                'modulekey' => $modulekey,
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
            'modulekey' => $modulekey,
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
        return self::get_role_users_by_shortname($courseid, 'control');
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
        $blockshtml = '';
        $haseditableblocks = false;
        foreach (self::get_assessor_sections() as $section) {
            $modulekey = $section['modulekey'];
            $roleshortname = $section['roleshortname'];
            $title = get_string($section['titlestring'], 'mod_vkr');

            $blockshtml .= \html_writer::tag('h4', $title, ['class' => 'mt-4']);

            if (!self::is_assessor_assignment_available($courseid, $modulekey)) {
                $blockshtml .= \html_writer::div(
                    get_string('assessorassignmentmissing', 'mod_vkr', $title),
                    'alert alert-warning'
                );
                continue;
            }

            $haseditableblocks = true;
            $teachers = self::get_role_users_by_shortname($courseid, $roleshortname);
            $currentassessors = self::get_group_assessor_map($courseid, $modulekey);
            $blockshtml .= self::render_assessor_block_table(
                $groups,
                $teachers,
                $currentassessors,
                $modulekey
            );
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
        $actions = $haseditableblocks ? \html_writer::div($submit, 'mt-3') : '';

        return \html_writer::start_tag('form', [
            'method' => 'post',
            'action' => (new \moodle_url('/mod/vkr/view.php'))->out(false),
        ]) .
            $hidden .
            $blockshtml .
            $actions .
            \html_writer::end_tag('form');
    }

    /**
     * Render one assessor table block for assignment module key.
     *
     * @param array<int, \stdClass> $groups
     * @param array<int, \stdClass> $teachers
     * @param array<int, int> $currentassessors
     * @param string $modulekey
     * @return string
     */
    private static function render_assessor_block_table(
        array $groups,
        array $teachers,
        array $currentassessors,
        string $modulekey
    ): string {
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
            $fieldname = self::get_assessor_field_name($modulekey, (int)$group->id);
            $select = \html_writer::select(
                $options,
                $fieldname,
                $currentassessors[(int)$group->id] ?? 0,
                false
            );

            $table->data[] = [
                format_string($group->name),
                $select,
            ];
        }

        return \html_writer::table($table);
    }

    /**
     * Get enrolled course users with role "Руководитель ВКР".
     *
     * @param int $courseid
     * @return array<int, \stdClass>
     */
    public static function get_supervisor_role_users(int $courseid): array {
        return self::get_role_users_by_candidates(
            $courseid,
            ['advisor', 'vkrsupervisor', 'supervisorvkr', 'supervisor'],
            ['Руководитель ВКР', 'VKR Supervisor']
        );
    }

    /**
     * Get enrolled course users with role shortname "recenzent".
     *
     * @param int $courseid
     * @return array<int, \stdClass>
     */
    public static function get_reviewer_role_users(int $courseid): array {
        return self::get_role_users_by_shortname($courseid, 'recenzent');
    }

    /**
     * Get enrolled course users with role "student".
     *
     * @param int $courseid
     * @return array<int, \stdClass>
     */
    public static function get_student_role_users(int $courseid): array {
        return self::get_role_users_by_candidates(
            $courseid,
            ['student'],
            ['Студент', 'Student']
        );
    }

    /**
     * Get current supervisor => student ids mapping for "advisor" assignment.
     *
     * @param int $courseid
     * @return array<int, array<int, int>>
     */
    public static function get_advisor_supervisor_map(int $courseid): array {
        $assignment = self::get_advisor_assign($courseid);
        if (!$assignment) {
            return [];
        }

        $students = self::get_student_role_users($courseid);
        $mapping = [];
        foreach ($students as $student) {
            $flags = $assignment->get_user_flags((int)$student->id, false);
            $supervisorid = (int)($flags->allocatedmarker ?? 0);
            if ($supervisorid <= 0) {
                continue;
            }

            if (!array_key_exists($supervisorid, $mapping)) {
                $mapping[$supervisorid] = [];
            }
            $mapping[$supervisorid][] = (int)$student->id;
        }

        return $mapping;
    }

    /**
     * Get current reviewer => student ids mapping for "review" assignment.
     *
     * @param int $courseid
     * @return array<int, array<int, int>>
     */
    public static function get_review_reviewer_map(int $courseid): array {
        $assignment = self::get_review_assign($courseid);
        if (!$assignment) {
            return [];
        }

        $students = self::get_student_role_users($courseid);
        $mapping = [];
        foreach ($students as $student) {
            $flags = $assignment->get_user_flags((int)$student->id, false);
            $reviewerid = (int)($flags->allocatedmarker ?? 0);
            if ($reviewerid <= 0) {
                continue;
            }

            if (!array_key_exists($reviewerid, $mapping)) {
                $mapping[$reviewerid] = [];
            }
            $mapping[$reviewerid][] = (int)$student->id;
        }

        return $mapping;
    }

    /**
     * Assign supervisors to students in "advisor" assignment using allocatedmarker.
     *
     * @param int $courseid
     * @param array<int, array<int, int>> $supervisorstudentmap
     * @return bool
     */
    public static function assign_advisor_supervisors(int $courseid, array $supervisorstudentmap): bool {
        $assignment = self::get_advisor_assign($courseid);
        if (!$assignment) {
            self::log('advisor assignment was not found', ['courseid' => $courseid]);
            return false;
        }

        $students = self::get_student_role_users($courseid);
        $validstudentids = array_map(static function($student) {
            return (int)$student->id;
        }, array_values($students));
        $validstudentids = array_flip($validstudentids);

        $studenttosupervisor = [];
        foreach ($supervisorstudentmap as $supervisorid => $studentids) {
            $supervisorid = (int)$supervisorid;
            if ($supervisorid <= 0) {
                continue;
            }

            foreach ($studentids as $studentid) {
                $studentid = (int)$studentid;
                if (!array_key_exists($studentid, $validstudentids)) {
                    continue;
                }
                $studenttosupervisor[$studentid] = $supervisorid;
            }
        }

        foreach ($students as $student) {
            $studentid = (int)$student->id;
            $flags = $assignment->get_user_flags($studentid, true);
            $flags->allocatedmarker = (int)($studenttosupervisor[$studentid] ?? 0);
            if (!$assignment->update_user_flags($flags)) {
                self::log('Failed to update advisor allocation', [
                    'courseid' => $courseid,
                    'studentid' => $studentid,
                    'allocatedmarker' => (int)$flags->allocatedmarker,
                ]);
                return false;
            }
        }

        self::log('Updated advisor supervisor allocation', [
            'courseid' => $courseid,
            'pairs' => $studenttosupervisor,
        ]);
        return true;
    }

    /**
     * Assign reviewers to students in "review" assignment using allocatedmarker.
     *
     * @param int $courseid
     * @param array<int, array<int, int>> $reviewerstudentmap
     * @return bool
     */
    public static function assign_review_reviewers(int $courseid, array $reviewerstudentmap): bool {
        $assignment = self::get_review_assign($courseid);
        if (!$assignment) {
            self::log('review assignment was not found', ['courseid' => $courseid]);
            return false;
        }

        $students = self::get_student_role_users($courseid);
        $validstudentids = array_map(static function($student) {
            return (int)$student->id;
        }, array_values($students));
        $validstudentids = array_flip($validstudentids);

        $studenttoreviewer = [];
        foreach ($reviewerstudentmap as $reviewerid => $studentids) {
            $reviewerid = (int)$reviewerid;
            if ($reviewerid <= 0) {
                continue;
            }

            foreach ($studentids as $studentid) {
                $studentid = (int)$studentid;
                if (!array_key_exists($studentid, $validstudentids)) {
                    continue;
                }
                $studenttoreviewer[$studentid] = $reviewerid;
            }
        }

        foreach ($students as $student) {
            $studentid = (int)$student->id;
            $flags = $assignment->get_user_flags($studentid, true);
            $flags->allocatedmarker = (int)($studenttoreviewer[$studentid] ?? 0);
            if (!$assignment->update_user_flags($flags)) {
                self::log('Failed to update review allocation', [
                    'courseid' => $courseid,
                    'studentid' => $studentid,
                    'allocatedmarker' => (int)$flags->allocatedmarker,
                ]);
                return false;
            }
        }

        self::log('Updated review reviewer allocation', [
            'courseid' => $courseid,
            'pairs' => $studenttoreviewer,
        ]);
        return true;
    }

    /**
     * Render supervisor assignment form using Moodle HTML helpers.
     *
     * @param int $cmid
     * @param int $courseid
     * @return string
     */
    public static function render_supervisors_form(int $cmid, int $courseid): string {
        $supervisors = self::get_supervisor_role_users($courseid);
        $students = self::get_student_role_users($courseid);
        $currentmapping = self::get_advisor_supervisor_map($courseid);

        $studentoptions = [];
        foreach ($students as $student) {
            $studentoptions[(int)$student->id] = fullname($student);
        }

        $table = new \html_table();
        $table->head = [
            get_string('vkrsupervisors', 'mod_vkr'),
            get_string('students', 'mod_vkr'),
        ];
        $table->attributes['class'] = 'generaltable';

        foreach ($supervisors as $supervisor) {
            $fieldname = 'supervisor_students_' . (int)$supervisor->id;
            $select = \html_writer::select(
                $studentoptions,
                $fieldname . '[]',
                $currentmapping[(int)$supervisor->id] ?? [],
                false,
                ['multiple' => 'multiple', 'size' => 10]
            );

            $table->data[] = [
                fullname($supervisor),
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
            'value' => 'supervisors',
        ]);
        $hidden .= \html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'savevkrsupervisors',
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
            'value' => get_string('savechanges'),
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
        return self::get_assign_by_module_key($courseid, 'normcontrol');
    }

    /**
     * Resolve the "advisor" assignment in the course.
     *
     * @param int $courseid
     * @return \assign|null
     */
    private static function get_advisor_assign(int $courseid): ?\assign {
        return self::get_assign_by_module_key($courseid, 'advisor');
    }

    /**
     * Resolve the "review" assignment in the course.
     *
     * @param int $courseid
     * @return \assign|null
     */
    private static function get_review_assign(int $courseid): ?\assign {
        return self::get_assign_by_module_key($courseid, 'review');
    }

    /**
     * Resolve assignment by local_vkr module key.
     *
     * @param int $courseid
     * @param string $modulekey
     * @return \assign|null
     */
    private static function get_assign_by_module_key(int $courseid, string $modulekey): ?\assign {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $cmidnumber = \local_vkr\course_builder::get_module_idnumber($modulekey);
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
            self::log('SQL did not find assignment by key', [
                'courseid' => $courseid,
                'modulekey' => $modulekey,
            ]);
            return null;
        }

        $course = get_course($courseid);
        $cm = get_coursemodule_from_id('assign', $record->cmid, $courseid, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        return new \assign($context, $cm, $course);
    }

    /**
     * Get enrolled course users by role shortname.
     *
     * @param int $courseid
     * @param string $roleshortname
     * @return array<int, \stdClass>
     */
    private static function get_role_users_by_shortname(int $courseid, string $roleshortname): array {
        global $DB;

        $role = $DB->get_record('role', ['shortname' => $roleshortname], 'id', IGNORE_MISSING);
        if (!$role) {
            self::log('Role by shortname was not found', [
                'courseid' => $courseid,
                'roleshortname' => $roleshortname,
            ]);
            return [];
        }

        $coursecontext = \context_course::instance($courseid);
        $users = get_role_users(
            (int)$role->id,
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

        self::log('Resolved role users by shortname', [
            'courseid' => $courseid,
            'roleshortname' => $roleshortname,
            'userids' => array_keys($users),
        ]);

        return $users;
    }

    /**
     * Get enrolled course users for one of candidate role ids.
     *
     * @param int $courseid
     * @param array<int, string> $shortnames
     * @param array<int, string> $names
     * @return array<int, \stdClass>
     */
    private static function get_role_users_by_candidates(int $courseid, array $shortnames, array $names): array {
        global $DB;

        $role = null;
        foreach ($shortnames as $shortname) {
            $role = $DB->get_record('role', ['shortname' => $shortname], 'id', IGNORE_MISSING);
            if ($role) {
                break;
            }
        }
        if (!$role) {
            foreach ($names as $name) {
                $role = $DB->get_record('role', ['name' => $name], 'id', IGNORE_MISSING);
                if ($role) {
                    break;
                }
            }
        }
        if (!$role) {
            return [];
        }

        $coursecontext = \context_course::instance($courseid);
        $users = get_role_users(
            (int)$role->id,
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

        return $users;
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

        error_log($line);
    }
}
