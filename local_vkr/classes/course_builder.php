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

use local_vkr\local\access;

class course_builder {
    private const AUTO_IDNUMBER_PREFIX = 'vkr_';
    private const MODULE_IDNUMBER_PREFIX = 'vkr_mod_';
    private const GEK_MEMBER_MODULE_IDNUMBER_PREFIX = 'vkr_gekmember_';
    private const PRESEDATEL_MODULE_IDNUMBER_PREFIX = 'vkr_predsedatel_';
    private const GEK_MEMBER_ROLE_SHORTNAME = 'gekmember';
    private const PRESEDATEL_ROLE_SHORTNAME = 'predsedatel';

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
        "advisor" => [
            'name' => 'Отзыв руководителя',
            'dependencies' => [],
        ],
        "commitment" => [
            'name' => 'Обязательство (заявление) на размещение ВКР в ЭБС ЮФУ',
            'dependencies' => ['advisor'],
        ],
        "normcontrol" => [
            'name' => 'Нормоконтроль',
            'dependencies' => ['advisor'],
        ],
        "review" => [
            'name' => 'Рецензент',
            'dependencies' => ['advisor'],
        ],
        "placement" => [
            'name' => 'Размещение ВКР в электронно-библиотечной системе ЮФУ',
            'dependencies' => ['advisor'],
        ],
        "pass" => [
            'name' => 'Допуск',
            'dependencies' => ['advisor', 'commitment', 'normcontrol', 'review', 'placement'],
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
        access::require_gekmanager_in_course((int)$courseid);

        if (self::is_prepared($courseid) === false) {
            return array_keys(self::$defaultmodules);
        }

        return array_keys(self::get_existing_auto_modules($courseid));
    }

    public static function get_module_due_dates(int $courseid): array {
        global $DB;

        access::require_gekmanager_in_course($courseid);

        $result = [];
        $existingmodules = self::get_existing_auto_modules($courseid);

        foreach ($existingmodules as $modulekey => $items) {
            if (empty($items)) {
                continue;
            }

            $cmid = (int)$items[0]->cmid;
            $duedate = $DB->get_field_sql(
                "SELECT a.duedate
                   FROM {course_modules} cm
                   JOIN {modules} m ON m.id = cm.module
                   JOIN {assign} a ON a.id = cm.instance
                  WHERE cm.id = :cmid
                    AND m.name = :modname",
                ['cmid' => $cmid, 'modname' => 'assign']
            );

            if ($duedate !== false) {
                $result[$modulekey] = (int)$duedate;
            }
        }

        return $result;
    }

    public static function prepare_course(
        int $courseid,
        array $moduleduedates,
        string $speciality,
        int $courseyear,
        ?array $selectedmodulekeys = null
    ): void {
        access::require_gekmanager_in_course($courseid);

        $sectionnumber = self::need_to_prepare($courseid);
        if ($sectionnumber === false) {
            return;
        }

        self::enable_course_completion_tracking($courseid);
        self::set_course_name($courseid, $speciality, $courseyear);
        self::create_sections($courseid, $sectionnumber);
        self::create_modules($courseid, ++$sectionnumber, $moduleduedates, $selectedmodulekeys);
        self::sync_gek_role_modules($courseid);
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

        access::require_gekmanager_in_course($courseid);

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
        array $moduleduedates,
        array $selectedmodulekeys,
        ?string $speciality = null,
        ?int $courseyear = null
    ): void {
        access::require_gekmanager_in_course((int)$courseid);

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
                $duedate = self::get_module_due_date_value($moduleduedates, $modulekey);

                if (empty($existingitems)) {
                    self::create_module($courseid, $sectionnumber, $duedate, $modulekey, $module);
                    continue;
                }

                self::update_module_due_date((int)$existingitems[0]->cmid, $duedate);
                self::update_module_availability_by_dependencies($courseid, (int)$existingitems[0]->cmid, $module);

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

        access::require_gekmanager_in_course((int)$courseid);

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

    private static function enable_course_completion_tracking(int $courseid): void {
        global $DB;

        $currentvalue = $DB->get_field('course', 'enablecompletion', ['id' => $courseid]);
        if ((int)$currentvalue === 1) {
            return;
        }

        $updatedcourse = new \stdClass();
        $updatedcourse->id = $courseid;
        $updatedcourse->enablecompletion = 1;
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

    private static function create_modules(
        $courseid,
        $sectionnumber,
        array $moduleduedates,
        ?array $selectedmodulekeys = null
    ): void {
        $modules = self::$defaultmodules;
        if ($selectedmodulekeys !== null) {
            $modules = array_intersect_key($modules, array_flip($selectedmodulekeys));
        }

        foreach ($modules as $modulekey => $module) {
            $duedate = self::get_module_due_date_value($moduleduedates, $modulekey);
            self::create_module($courseid, $sectionnumber, $duedate, $modulekey, $module);
        }
    }

    private static function create_module($courseid, $sectionnumber, int $duedate, string $modulekey, array $module): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/lib.php');
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $availability = self::build_availability_by_dependencies(
            (int)$courseid,
            $module['dependencies'] ?? []
        );

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
            'availability' => $availability,
            'assignsubmission_file_enabled' => 1,
            'assignsubmission_file_maxfiles' => 20,
            'assignsubmission_file_maxsizebytes' => 5242880,
        ];
        $createdmodinfo = create_module($createdmodinfo);

        self::protect_cm($createdmodinfo->coursemodule);
    }

    private static function update_module_due_date(int $cmid, int $duedate): void {
        global $DB;

        $record = $DB->get_record_sql(
            "SELECT cm.id AS cmid, cm.course, cm.instance, a.id AS assignid
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
               JOIN {assign} a ON a.id = cm.instance
              WHERE cm.id = :cmid
                AND m.name = :modname",
            ['cmid' => $cmid, 'modname' => 'assign']
        );

        if (!$record) {
            return;
        }

        $assign = new \stdClass();
        $assign->id = (int)$record->assignid;
        $assign->duedate = $duedate;
        $assign->timemodified = time();

        $DB->update_record('assign', $assign);
    }

    private static function get_module_due_date_value(array $moduleduedates, string $modulekey): int {
        $value = $moduleduedates[$modulekey] ?? time();
        return max(0, (int)$value);
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

    public static function create_or_update_gek_member_module(int $courseid, \stdClass $user): void {
        self::create_or_update_gek_role_module($courseid, $user, self::GEK_MEMBER_ROLE_SHORTNAME);
    }

    public static function create_or_update_gek_role_module(
        int $courseid,
        \stdClass $user,
        string $roleshortname
    ): void {
        global $CFG, $DB;

        if (!self::is_prepared($courseid) || !self::is_supported_gek_role($roleshortname)) {
            return;
        }

        require_once($CFG->dirroot . '/mod/assign/lib.php');
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $sectionnumber = self::get_modules_section_number($courseid);
        if ($sectionnumber === false) {
            return;
        }
        $sectionnumber += 1;

        $idnumber = self::get_gek_role_module_idnumber((int)$user->id, $roleshortname);
        $name = self::get_gek_role_module_name($user, $roleshortname);

        $existingcms = self::get_existing_gek_role_modules_for_user($courseid, (int)$user->id, $roleshortname);

        if (!empty($existingcms)) {
            $existingcm = reset($existingcms);
            $assign = $DB->get_record('assign', ['id' => $existingcm->instance], '*', MUST_EXIST);

            $data = (object)[
                'coursemodule' => $existingcm->id,
                'course' => $courseid,
                'modulename' => 'assign',
                'instance' => $assign->id,
                'name' => $name,
                'duedate' => 0,
                'cmidnumber' => $idnumber,
            ];

            update_moduleinfo($existingcm, $data, $courseid);

            foreach (array_slice($existingcms, 1) as $duplicatecm) {
                course_delete_module($duplicatecm->id);
            }
            return;
        }

        $createdmodinfo = (object)[
            'modulename' => 'assign',
            'section' => $sectionnumber,
            'course' => $courseid,
            'name' => $name,
            'introeditor' => [
                'text' => '',
                'format' => FORMAT_HTML,
            ],
            'alwaysshowdescription' => 1,
            'submissiondrafts' => 0,
            'requiresubmissionstatement' => 0,
            'sendnotifications' => 0,
            'allowsubmissionsfromdate' => 0,
            'sendlatenotifications' => 0,
            'duedate' => 0,
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
            'cmidnumber' => $idnumber,
            'assignsubmission_file_enabled' => 1,
            'assignsubmission_file_maxfiles' => 20,
            'assignsubmission_file_maxsizebytes' => 5242880,
        ];

        $created = create_module($createdmodinfo);
        self::protect_cm($created->coursemodule);
    }

    public static function sync_gek_member_modules(int $courseid): void {
        self::sync_gek_role_modules($courseid);
    }

    public static function sync_gek_role_modules(int $courseid): void {
        global $DB;

        if (!self::is_prepared($courseid)) {
            return;
        }

        $context = \context_course::instance($courseid);
        foreach (self::get_supported_gek_roles() as $roleshortname) {
            $role = $DB->get_record('role', ['shortname' => $roleshortname], 'id', IGNORE_MISSING);
            if (!$role) {
                continue;
            }

            $users = get_role_users($role->id, $context, false, 'u.id, u.firstname, u.lastname');
            $existingbyuser = self::get_existing_gek_role_modules($courseid, $roleshortname);

            $actualuserids = [];
            foreach ($users as $user) {
                $actualuserids[] = (int)$user->id;
                self::create_or_update_gek_role_module($courseid, $user, $roleshortname);
            }

            foreach ($existingbyuser as $userid => $cms) {
                if (!in_array((int)$userid, $actualuserids, true)) {
                    foreach ($cms as $cm) {
                        course_delete_module($cm->id);
                    }
                }
            }
        }

        rebuild_course_cache($courseid, true);
    }

    public static function delete_gek_member_module(int $courseid, int $userid): void {
        self::delete_gek_role_module($courseid, $userid, self::GEK_MEMBER_ROLE_SHORTNAME);
    }

    public static function delete_gek_role_module(int $courseid, int $userid, string $roleshortname): void {
        if (!self::is_supported_gek_role($roleshortname)) {
            return;
        }

        $cms = self::get_existing_gek_role_modules_for_user($courseid, $userid, $roleshortname);
        if (!empty($cms)) {
            foreach ($cms as $cm) {
                course_delete_module($cm->id);
            }
            rebuild_course_cache($courseid, true);
        }
    }

    private static function get_existing_gek_role_modules_for_user(
        int $courseid,
        int $userid,
        string $roleshortname
    ): array {
        global $DB;

        $sql = "SELECT cm.*, cs.course
              FROM {course_modules} cm
              JOIN {course_sections} cs ON cs.id = cm.section
              JOIN {modules} m ON m.id = cm.module
             WHERE cs.course = :courseid
               AND m.name = :modname
               AND cm.idnumber = :idnumber";

        return array_values($DB->get_records_sql($sql, [
            'courseid' => $courseid,
            'modname' => 'assign',
            'idnumber' => self::get_gek_role_module_idnumber($userid, $roleshortname),
        ]));
    }

    private static function get_existing_gek_role_modules(int $courseid, string $roleshortname): array {
        global $DB;

        $prefix = self::get_gek_role_module_idnumber_prefix($roleshortname);
        if ($prefix === null) {
            return [];
        }

        $sql = "SELECT cm.id, cm.idnumber
              FROM {course_modules} cm
              JOIN {course_sections} cs ON cs.id = cm.section
              JOIN {modules} m ON m.id = cm.module
             WHERE cs.course = :courseid
               AND m.name = :modname
               AND " . $DB->sql_like('cm.idnumber', ':prefix');

        $records = $DB->get_records_sql($sql, [
            'courseid' => $courseid,
            'modname' => 'assign',
            'prefix' => $prefix . '%',
        ]);

        $result = [];
        foreach ($records as $record) {
            $userid = (int)substr($record->idnumber, strlen($prefix));
            if (!array_key_exists($userid, $result)) {
                $result[$userid] = [];
            }
            $result[$userid][] = $record;
        }

        return $result;
    }

    private static function get_gek_role_module_idnumber(int $userid, string $roleshortname): string {
        $prefix = self::get_gek_role_module_idnumber_prefix($roleshortname);
        if ($prefix === null) {
            return '';
        }

        return $prefix . $userid;
    }

    private static function get_gek_role_module_idnumber_prefix(string $roleshortname): ?string {
        if ($roleshortname === self::GEK_MEMBER_ROLE_SHORTNAME) {
            return self::GEK_MEMBER_MODULE_IDNUMBER_PREFIX;
        }
        if ($roleshortname === self::PRESEDATEL_ROLE_SHORTNAME) {
            return self::PRESEDATEL_MODULE_IDNUMBER_PREFIX;
        }

        return null;
    }

    private static function get_gek_role_module_name(\stdClass $user, string $roleshortname): string {
        $lastname = trim($user->lastname ?? '');
        $firstname = trim($user->firstname ?? '');

        if ($roleshortname === self::PRESEDATEL_ROLE_SHORTNAME) {
            return "Председатель ГЭК {$lastname} {$firstname}";
        }

        return "Член ГЭК - {$lastname} {$firstname}";
    }

    public static function is_supported_gek_role(string $roleshortname): bool {
        return in_array($roleshortname, self::get_supported_gek_roles(), true);
    }

    public static function get_supported_gek_roles(): array {
        return [
            self::GEK_MEMBER_ROLE_SHORTNAME,
            self::PRESEDATEL_ROLE_SHORTNAME,
        ];
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

    private static function update_module_availability_by_dependencies(int $courseid, int $cmid, array $module): void {
        global $DB;

        $availability = self::build_availability_by_dependencies(
            $courseid,
            $module['dependencies'] ?? []
        );
        $DB->set_field('course_modules', 'availability', $availability, ['id' => $cmid]);
    }

    private static function build_availability_by_dependencies(int $courseid, array $dependencykeys): ?string {
        if (empty($dependencykeys)) {
            return null;
        }

        $dependencycmids = self::get_dependency_cmids($courseid, $dependencykeys);
        if (empty($dependencycmids)) {
            return null;
        }

        $conditions = [];
        $showconditions = [];
        foreach ($dependencycmids as $dependencycmid) {
            $conditions[] = [
                'type' => 'completion',
                'cm' => (int)$dependencycmid,
                'e' => 1,
            ];
            $showconditions[] = true;
        }

        return json_encode([
            'op' => '&',
            'c' => $conditions,
            'showc' => $showconditions,
        ]);
    }

    private static function get_dependency_cmids(int $courseid, array $dependencykeys): array {
        global $DB;

        $dependencyidnumbers = [];
        foreach ($dependencykeys as $dependencykey) {
            if (!array_key_exists($dependencykey, self::$defaultmodules)) {
                continue;
            }
            $dependencyidnumbers[$dependencykey] = self::get_module_idnumber($dependencykey);
        }
        if (empty($dependencyidnumbers)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal(array_values($dependencyidnumbers), SQL_PARAMS_NAMED, 'dep');
        $params['courseid'] = $courseid;
        $sql = "SELECT cm.id, cm.idnumber
                  FROM {course_modules} cm
                  JOIN {course_sections} cs ON cs.id = cm.section
                 WHERE cs.course = :courseid
                   AND cm.idnumber $insql";
        $records = $DB->get_records_sql($sql, $params);
        if (empty($records)) {
            return [];
        }

        $cmidsbyidnumber = [];
        foreach ($records as $record) {
            $cmidsbyidnumber[$record->idnumber] = (int)$record->id;
        }

        $result = [];
        foreach ($dependencykeys as $dependencykey) {
            if (!array_key_exists($dependencykey, $dependencyidnumbers)) {
                continue;
            }
            $idnumber = $dependencyidnumbers[$dependencykey];
            if (!array_key_exists($idnumber, $cmidsbyidnumber)) {
                continue;
            }
            $result[] = $cmidsbyidnumber[$idnumber];
        }

        return $result;
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
