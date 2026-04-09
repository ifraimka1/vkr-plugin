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
 * Preparing course
 *
 * @package     local_vkr
 * @copyright   2025 Ifraim Solomonov <solomonov@sfedu.ru>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vkr;

class course_builder {

    private static array $defaultsections = [
        [
            'name'          => 'Подготовка ВКР',
            'summary'       => '',
            'summaryformat' => FORMAT_HTML,
            'sequence'      => '',
            'visible'       => 1,
            'availability'  => null,
        ],
        [
            'name'          => 'Защита ВКР',
            'summary'       => '',
            'summaryformat' => FORMAT_HTML,
            'sequence'      => '',
            'visible'       => 1,
            'availability'  => null,
        ],
    ];

    private static array $defaultmodules = [
        "adviser" => [
            'name' => 'Отзыв руководителя',
            'dependencies' => [],
        ],
        "commitment" => [
            'name' => 'Обязательство (заявление) на размещение ВКР в ЭБС ЮФУ',
            'dependencies' => ['adviser'],
        ],
        "normcontrol" => [
            'name' => 'Нормоконтроль',
            'dependencies' => ['adviser'],
        ],
        "review" => [
            'name' => 'Рецензент',
            'dependencies' => ['adviser'],
        ],
        "placement" => [
            'name' => 'Размещение ВКР в электронно-библиотечной системе ЮФУ',
            'dependencies' => ['adviser'],
        ],
        "pass" => [
            'name' => 'Допуск',
            'dependencies' => ['adviser', 'commitment', 'normcontrol', 'review', 'placement'],
        ],
    ];

    public static function get_default_modules(): array {
        return self::$defaultmodules;
    }

    public static function get_selected_module_keys($courseid): array {
        if (self::need_to_prepare($courseid) !== false) {
            return array_keys(self::$defaultmodules);
        }

        return array_keys(self::get_existing_auto_modules($courseid));
    }

    public static function prepare_course($courseid, $duedate, ?array $selectedmodulekeys = null): void {
        $sectionnumber = self::need_to_prepare($courseid);
        if ($sectionnumber === false) {return;}

        self::set_course_name($courseid);
        self::create_sections($courseid, $sectionnumber);
        self::create_modules($courseid, ++$sectionnumber, $duedate, $selectedmodulekeys);
    }

    public static function update_course($courseid, $duedate, array $selectedmodulekeys): void {
        $sectionnumber = self::get_modules_section_number($courseid);
        if ($sectionnumber === false) {return;}

        $existingmodules = self::get_existing_auto_modules($courseid);
        $selectedmodules = array_intersect_key(self::$defaultmodules, array_flip($selectedmodulekeys));

        foreach (self::$defaultmodules as $modulekey => $module) {
            $existingitems = $existingmodules[$modulekey] ?? [];
            $shouldexist = array_key_exists($modulekey, $selectedmodules);

            if ($shouldexist) {
                if (empty($existingitems)) {
                    self::create_module($courseid, $sectionnumber, $duedate, $module);
                    continue;
                }

                for ($i = 1; $i < count($existingitems); $i++) {
                    course_delete_module($existingitems[$i]->cmid);
                }
            } else {
                foreach ($existingitems as $existingitem) {
                    course_delete_module($existingitem->cmid);
                }
            }
        }

        rebuild_course_cache($courseid, true);
    }

    public static function reset_course($courseid): void {
        global $DB;

        foreach (self::$defaultsections as $section) {
            $sectionid = $DB->get_field(
                'course_sections',
                'section',
                ['name' => $section['name'], 'course' => $courseid]
            );
            course_delete_section($courseid, $sectionid);
        }

        rebuild_course_cache($courseid, true);
    }

    public static function need_to_prepare($courseid): int|bool {
        global $DB;

        $sections = $DB->get_records('course_sections', ['course' => $courseid]);

        foreach ($sections as $section) {
            if ($section->name === self::$defaultsections[0]['name']) {
                return false;
            }
        }

        return count($sections);
    }

    private static function set_course_name($courseid): void {
        global $DB;

        $updated_course = new \stdClass();
        $updated_course->id = $courseid;
        $updated_course->fullname = "ГЭК - 09.03.02 Информационные системы и технологии 2025";
        $updated_course->shortname = "ГЭК 09.03.02 2025"; // TODO: сделать название курса переменной.

        $current_course = $DB->get_record('course', ['id' => $courseid]);
        if ($current_course->shortname === $updated_course->shortname) {return;}

        $condition = $DB->sql_like('shortname', ':shortname');
        $condition .= " AND id <> :courseid";
        $params = ['courseid' => $courseid, 'shortname' => $updated_course->shortname.'%'];
        $duplicates = $DB->get_records_select(
            'course',
            $condition,
            $params,
            '',
            'id'
        );

        $duplicatesamount = count($duplicates);
        if ($duplicatesamount > 0) {
            $updated_course->shortname .= ' ' . ++$duplicatesamount;
        }

        update_course($updated_course);
    }

    private static function create_sections($courseid, $sectionnumber) {
        global $DB;

        foreach (self::$defaultsections as $section) {
            $section['course'] = $courseid;
            $section['section'] = ++$sectionnumber;
            $section['idnumber'] = 'vkr_auto';
            $DB->insert_record('course_sections', $section);
        }

        rebuild_course_cache($courseid, true);
    }

    private static function create_modules($courseid, $sectionnumber, $duedate, ?array $selectedmodulekeys = null): void {
        $modules = self::$defaultmodules;
        if ($selectedmodulekeys !== null) {
            $modules = array_intersect_key($modules, array_flip($selectedmodulekeys));
        }

        foreach ($modules as $mod) {
            self::create_module($courseid, $sectionnumber, $duedate, $mod);
        }
    }

    private static function create_module($courseid, $sectionnumber, $duedate, array $mod): void {
        global $CFG;
        require_once($CFG->dirroot.'/mod/assign/lib.php');
        require_once($CFG->dirroot.'/mod/assign/locallib.php');

        $createdmodinfo = (object)[
            'modulename' => 'assign',
            'section' => $sectionnumber,
            'course' => $courseid,
            'name' => $mod['name'],
            'introeditor' => [
                'text' => 'Загрузите окончательную версию ВКР и отзыв руководителя',
                'format' => FORMAT_HTML,
            ],
            'alwaysshowdescription' => 1,
            'submissiondrafts' => 0,
            'requiresubmissionstatement' => 0,
            'sendnotifications' => 0,
            'allowsubmissionsfromdate' => 0,
            'sendlatenotifications' => 0,
            'duedate' => $duedate,
            'cutoffdate' => 0,
            'grade' => 100,
            'gradingduedate' => 0,
            'teamsubmission' => 0,
            'requireallteammemberssubmit' => 0,
            'teamsubmissiongroupingid' => 0,
            'blindmarking' => 0,
            'hidegrader' => 0,
            'attemptreopenmethod' => 'none',
            'maxattempts' => -1,
            'markingworkflow' => 1,
            'markingallocation' => 1,
            'assignfeedback_comments_enabled' => 1,
            'visible' => 1,
            'cmidnumber' => '',
            'availability' => null,
            'assignsubmission_file_enabled' => 1,
            'assignsubmission_file_maxfiles' => 20,
            'assignsubmission_file_maxsizebytes' => 5242880,
        ];
        $createdmodinfo = create_module($createdmodinfo);

        self::protect_cm($createdmodinfo->coursemodule);
        self::set_cm_idnumber($createdmodinfo->coursemodule);
    }

    private static function get_modules_section_number($courseid): int|bool {
        global $DB;

        return $DB->get_field(
            'course_sections',
            'section',
            ['course' => $courseid, 'name' => self::$defaultsections[1]['name']]
        );
    }

    private static function get_existing_auto_modules($courseid): array {
        global $DB;

        $sql = "SELECT cm.id AS cmid, a.name
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                  JOIN {assign} a ON a.id = cm.instance
                 WHERE cm.course = :courseid
                   AND cm.idnumber = :idnumber
                   AND m.name = :modname";
        $records = $DB->get_records_sql($sql, [
            'courseid' => $courseid,
            'idnumber' => 'vkr_auto',
            'modname' => 'assign',
        ]);

        $modulesbykey = [];
        foreach ($records as $record) {
            $modulekey = self::get_module_key_by_name($record->name);
            if ($modulekey === null) {
                continue;
            }

            if (!array_key_exists($modulekey, $modulesbykey)) {
                $modulesbykey[$modulekey] = [];
            }

            $modulesbykey[$modulekey][] = $record;
        }

        return $modulesbykey;
    }

    private static function get_module_key_by_name(string $modulename): ?string {
        foreach (self::$defaultmodules as $modulekey => $module) {
            if ($module['name'] === $modulename) {
                return $modulekey;
            }
        }

        return null;
    }

    private static function protect_cm($cmid) {
        $context = \context_module::instance($cmid);

        $capabilitiestolock = [
            'moodle/course:activityvisibility'
        ];

        $roles = role_fix_names(get_all_roles());

        foreach ($capabilitiestolock as $capability) {
            foreach ($roles as $role) {
                role_change_permission($role->id, $context, $capability, CAP_PROHIBIT);
            }
        }
    }

    private static function set_cm_idnumber($cmid) {
        global $DB;
        $DB->set_field('course_modules', 'idnumber', 'vkr_auto', ['id' => $cmid]);
    }
}
