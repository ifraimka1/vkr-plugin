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

        if ($cm && $cm->idnumber === 'vkr_auto') {
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

        if ($section && $section->idnumber === 'vkr_auto') {
            throw new \moodle_exception('cannotdeletesection', 'local_vkr');
        }
    }
}
