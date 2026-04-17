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
    private const AUTO_IDNUMBER_PREFIX = 'vkr_';
    private const MODULE_IDNUMBER_PREFIX = 'vkr_mod_';

    private static array $defaultsections = [
        [
            'key' => 'prepare',
            'name' => 'Подготовка ВКР',
            'summary' => '',
            'summaryformat' => FORMAT_HTML,
            'sequence' => '',
            'visible' => 1,
            'availability' => null,
        ],
        [
            'key' => 'defense',
            'name' => 'Защита ВКР',
            'summary' => '',
            'summaryformat' => FORMAT_HTML,
            'sequence' => '',
            'visible' => 1,
            'availability' => null,
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
    private static ?bool $hassectionsidnumber = null;

    public static function get_default_modules(): array {
        return self::$defaultmodules;
    }

    public static function get_module_idnumber(string $modulekey): string {
        return self::MODULE_IDNUMBER_PREFIX . $modulekey;
    }

    public static function get_selected_module_keys($courseid): array {
        if (self::is_prepared($courseid) === false) {
            return array_keys(self::$defaultmodules);
        }

        return array_keys(self::get_existing_auto_modules($courseid));
    }

    public static function prepare_course(
        int $courseid,
        int $duedate,
        string $speciality,
        int $courseyear,
        ?array $selectedmodulekeys = null
    ): void {
        $sectionnumber = self::need_to_prepare($courseid);
        if ($sectionnumber === false) {
            return;
        }

        self::set_course_name($courseid, $speciality, $courseyear);
        self::create_sections($courseid, $sectionnumber);
        self::create_modules($courseid, ++$sectionnumber, $duedate, $selectedmodulekeys);
    }

    public static function get_training_direction_options(): array {
        $rawvalue = (string)get_config('local_vkr', 'specialitys');
        if ($rawvalue === '') {
            return [];
        }

        $lines = preg_split('/\R/u', $rawvalue) ?: [];
        $options = [];
        foreach ($lines as $line) {
            $direction = trim($line);
            if ($direction === '') {
                continue;
            }
            $options[$direction] = $direction;
        }

        return $options;
    }

    public static function get_year_options(): array {
        $currentyear = (int)date('Y');
        $options = [];
        for ($i = 0; $i < 4; $i++) {
            $year = (string)($currentyear + $i);
            $options[$year] = $year;
        }

        return $options;
    }

    public static function get_selected_course_name_config(int $courseid): array {
        global $DB;

        $result = [
            'speciality' => '',
            'courseyear' => (int)date('Y'),
        ];

        $course = $DB->get_record('course', ['id' => $courseid], 'fullname', IGNORE_MISSING);
        if (!$course || empty($course->fullname)) {
            return $result;
        }

        $fullname = trim($course->fullname);
        $patterns = [
            '/^ГЭК\s*-\s*(.+)\s+(\d{4})$/u',
            '/^ГЭК\s+(.+)\s-\s(\d{4})$/u',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $fullname, $matches)) {
                $result['speciality'] = trim($matches[1]);
                $result['courseyear'] = (int)$matches[2];
                return $result;
            }
        }

        return $result;
    }

    public static function update_course(
        $courseid,
        $duedate,
        array $selectedmodulekeys,
        ?string $speciality = null,
        ?int $courseyear = null
    ): void {
        $sectionnumber = self::get_modules_section_number($courseid);
        if ($sectionnumber === false) {
            return;
        }

        if ($speciality !== null && $courseyear !== null) {
            self::set_course_name((int)$courseid, $speciality, (int)$courseyear);
        }

        $existingmodules = self::get_existing_auto_modules($courseid);
        $selectedmodules = array_intersect_key(self::$defaultmodules, array_flip($selectedmodulekeys));

        foreach (self::$defaultmodules as $modulekey => $module) {
            $existingitems = $existingmodules[$modulekey] ?? [];
            $shouldexist = array_key_exists($modulekey, $selectedmodules);

            if ($shouldexist) {
                if (empty($existingitems)) {
                    self::create_module($courseid, $sectionnumber, $duedate, $modulekey, $module);
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
            $conditions = ['course' => $courseid];
            if (self::has_sections_idnumber()) {
                $conditions['idnumber'] = self::get_section_idnumber($section['key']);
            } else {
                $conditions['name'] = $section['name'];
            }
            $sectionid = $DB->get_field('course_sections', 'section', $conditions);
            if ($sectionid !== false) {
                course_delete_section($courseid, $sectionid);
            }
        }

        rebuild_course_cache($courseid, true);
    }

    public static function need_to_prepare($courseid): int|bool {
        global $DB;

        $sections = $DB->get_records('course_sections', ['course' => $courseid]);
        $hassectionsidnumber = self::has_sections_idnumber();

        foreach ($sections as $section) {
            if ($hassectionsidnumber && !empty($section->idnumber) &&
                    $section->idnumber === self::get_section_idnumber('prepare')) {
                return false;
            }
            if (!$hassectionsidnumber && $section->name === self::$defaultsections[0]['name']) {
                return false;
            }
        }

        return count($sections);
    }

    private static function set_course_name(int $courseid, string $speciality, int $courseyear): void {
        global $DB;

        $updatedcourse = new \stdClass();
        $updatedcourse->id = $courseid;
        $speciality = trim($speciality);
        $courseyear = (int)$courseyear;
        $coursename = "ГЭК - {$speciality} {$courseyear}";
        $updatedcourse->fullname = $coursename;
        $updatedcourse->shortname = $coursename;

        $currentcourse = $DB->get_record('course', ['id' => $courseid]);
        if ($currentcourse->shortname === $updatedcourse->shortname) {
            return;
        }

        $condition = $DB->sql_like('shortname', ':shortname');
        $condition .= " AND id <> :courseid";
        $params = ['courseid' => $courseid, 'shortname' => $updatedcourse->shortname . '%'];
        $duplicates = $DB->get_records_select(
            'course',
            $condition,
            $params,
            '',
            'id'
        );

        $duplicatesamount = count($duplicates);
        if ($duplicatesamount > 0) {
            $updatedcourse->shortname .= ' ' . ++$duplicatesamount;
        }

        update_course($updatedcourse);
    }

    private static function create_sections($courseid, $sectionnumber): void {
        global $DB;
        $hassectionsidnumber = self::has_sections_idnumber();

        foreach (self::$defaultsections as $section) {
            $sectionrecord = $section;
            $sectionrecord['course'] = $courseid;
            $sectionrecord['section'] = ++$sectionnumber;
            if ($hassectionsidnumber) {
                $sectionrecord['idnumber'] = self::get_section_idnumber($section['key']);
            }
            unset($sectionrecord['key']);
            $DB->insert_record('course_sections', $sectionrecord);
        }

        rebuild_course_cache($courseid, true);
    }

    private static function create_modules($courseid, $sectionnumber, $duedate, ?array $selectedmodulekeys = null): void {
        $modules = self::$defaultmodules;
        if ($selectedmodulekeys !== null) {
            $modules = array_intersect_key($modules, array_flip($selectedmodulekeys));
        }

        foreach ($modules as $modulekey => $module) {
            self::create_module($courseid, $sectionnumber, $duedate, $modulekey, $module);
        }
    }

    private static function create_module($courseid, $sectionnumber, $duedate, string $modulekey, array $module): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/lib.php');
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $createdmodinfo = (object)[
            'modulename' => 'assign',
            'section' => $sectionnumber,
            'course' => $courseid,
            'name' => $module['name'],
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
            'cmidnumber' => self::get_module_idnumber($modulekey),
            'availability' => null,
            'assignsubmission_file_enabled' => 1,
            'assignsubmission_file_maxfiles' => 20,
            'assignsubmission_file_maxsizebytes' => 5242880,
        ];
        $createdmodinfo = create_module($createdmodinfo);

        self::protect_cm($createdmodinfo->coursemodule);
    }

    private static function get_modules_section_number($courseid): int|bool {
        global $DB;

        $conditions = ['course' => $courseid];
        if (self::has_sections_idnumber()) {
            $conditions['idnumber'] = self::get_section_idnumber('prepare');
        } else {
            $conditions['name'] = self::$defaultsections[0]['name'];
        }

        return $DB->get_field('course_sections', 'section', $conditions);
    }

    private static function get_existing_auto_modules($courseid): array {
        global $DB;

        $sql = "SELECT cm.id AS cmid, cm.idnumber
                  FROM {course_modules} cm
                  JOIN {course_sections} cs ON cs.id = cm.section
                  JOIN {modules} m ON m.id = cm.module
                 WHERE cs.course = :courseid
                   AND " . $DB->sql_like('cm.idnumber', ':idnumberprefix') . "
                   AND m.name = :modname";
        $records = $DB->get_records_sql($sql, [
            'courseid' => $courseid,
            'idnumberprefix' => self::MODULE_IDNUMBER_PREFIX . '%',
            'modname' => 'assign',
        ]);

        $modulesbykey = [];
        foreach ($records as $record) {
            $modulekey = self::get_module_key_by_idnumber($record->idnumber);
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

    private static function get_module_key_by_idnumber(string $idnumber): ?string {
        foreach (array_keys(self::$defaultmodules) as $modulekey) {
            if (self::get_module_idnumber($modulekey) === $idnumber) {
                return $modulekey;
            }
        }

        return null;
    }

    private static function get_section_idnumber(string $sectionkey): string {
        return self::AUTO_IDNUMBER_PREFIX . 'section_' . $sectionkey;
    }

    private static function is_prepared(int $courseid): bool {
        global $DB;

        $conditions = ['course' => $courseid];
        if (self::has_sections_idnumber()) {
            $conditions['idnumber'] = self::get_section_idnumber('prepare');
        } else {
            $conditions['name'] = self::$defaultsections[0]['name'];
        }

        return (bool)$DB->record_exists('course_sections', $conditions);
    }

    /**
     * Check whether the course_sections table supports idnumber.
     *
     * @return bool
     */
    private static function has_sections_idnumber(): bool {
        global $DB;

        if (self::$hassectionsidnumber !== null) {
            return self::$hassectionsidnumber;
        }

        $columns = $DB->get_columns('course_sections');
        self::$hassectionsidnumber = is_array($columns) && array_key_exists('idnumber', $columns);
        return self::$hassectionsidnumber;
    }

    private static function protect_cm($cmid): void {
        $context = \context_module::instance($cmid);

        $capabilitiestolock = [
            'moodle/course:activityvisibility',
        ];

        $roles = role_fix_names(get_all_roles());

        foreach ($capabilitiestolock as $capability) {
            foreach ($roles as $role) {
                role_change_permission($role->id, $context, $capability, CAP_PROHIBIT);
            }
        }
    }
}
