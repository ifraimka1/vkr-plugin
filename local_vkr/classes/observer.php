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
 * Event observers for local_vkr plugin
 *
 * @package   local_vkr
 * @copyright 2025 Ifraim Solomonov <solomonov@sfedu.ru>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vkr;

/**
 * Event observers class
 */
class observer {
    private const AUTO_IDNUMBER_PREFIX = 'vkr_';

    /**
     * Prevent deletion of auto-generated course modules
     *
     * @param \core\event\course_module_deleting $event
     * @throws \moodle_exception if module is auto-generated
     */
    public static function course_module_deleting(\core\event\course_module_deleting $event): void {
        global $DB;

        $cmid = $event->objectid;
        $cm = $DB->get_record('course_modules', ['id' => $cmid]);

        if ($cm && property_exists($cm, 'idnumber') && self::is_vkr_idnumber((string)$cm->idnumber)) {
            throw new \moodle_exception('cannotdeletemodule', 'local_vkr');
        }
    }

    /**
     * Prevent deletion of auto-generated course sections
     *
     * @param \core\event\section_deleting $event
     * @throws \moodle_exception if section is auto-generated
     */
    public static function section_deleting(\core\event\section_deleting $event): void {
        global $DB;

        $sectionid = $event->objectid;
        $section = $DB->get_record('course_sections', ['id' => $sectionid]);

        if ($section && property_exists($section, 'idnumber') &&
                self::is_vkr_idnumber((string)$section->idnumber)) {
            throw new \moodle_exception('cannotdeletesection', 'local_vkr');
        }
    }

    /**
     * Check whether idnumber belongs to auto-generated VKR entities.
     *
     * @param string $idnumber
     * @return bool
     */
    private static function is_vkr_idnumber(string $idnumber): bool {
        return strpos($idnumber, self::AUTO_IDNUMBER_PREFIX) === 0;
    }

    public static function role_assigned(\core\event\role_assigned $event): void {
        global $DB;

        if ((int)$event->contextlevel !== CONTEXT_COURSE) {
            return;
        }

        $role = $DB->get_record('role', ['id' => $event->objectid], 'id, shortname', IGNORE_MISSING);
        if (!$role || !course_builder::is_supported_gek_role($role->shortname)) {
            return;
        }

        $courseid = (int)$event->courseid;
        $userid = (int)$event->relateduserid;

        if (!$courseid || !$userid) {
            return;
        }

        if (!course_builder::need_to_prepare($courseid) === false) {
            // курс не подготовлен — ничего не делаем
            return;
        }

        $user = $DB->get_record('user', ['id' => $userid], 'id, firstname, lastname', IGNORE_MISSING);
        if (!$user) {
            return;
        }

        course_builder::create_or_update_gek_role_module($courseid, $user, $role->shortname);
    }

    public static function role_unassigned(\core\event\role_unassigned $event): void {
        global $DB;

        if ((int)$event->contextlevel !== CONTEXT_COURSE) {
            return;
        }

        $role = $DB->get_record('role', ['id' => $event->objectid], 'id, shortname', IGNORE_MISSING);
        if (!$role || !course_builder::is_supported_gek_role($role->shortname)) {
            return;
        }

        $courseid = (int)$event->courseid;
        $userid = (int)$event->relateduserid;

        if (!$courseid || !$userid) {
            return;
        }

        course_builder::delete_gek_role_module($courseid, $userid, $role->shortname);
    }
}
