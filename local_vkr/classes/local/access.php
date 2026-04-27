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
 * Access helper for local_vkr user-facing actions.
 *
 * @package   local_vkr
 * @copyright 2025 Ifraim Solomonov <solomonov@sfedu.ru>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vkr\local;

defined('MOODLE_INTERNAL') || die();

class access {
    /** @var string Role shortname that can use local_vkr UI/actions */
    private const GEK_MANAGER_ROLE_SHORTNAME = 'gekmanager';

    /**
     * Checks whether the user has role "gekmanager" exactly in course context.
     *
     * @param int $courseid
     * @param int|null $userid
     * @return bool
     */
    public static function has_gekmanager_in_course(int $courseid, ?int $userid = null): bool {
        global $USER;

        $userid = $userid ?? (int)$USER->id;
        if (is_siteadmin($userid)) {
            return true;
        }

        $context = \context_course::instance($courseid);
        $roles = get_user_roles($context, $userid, false);

        foreach ($roles as $role) {
            if (!empty($role->shortname) && $role->shortname === self::GEK_MANAGER_ROLE_SHORTNAME) {
                return true;
            }
        }

        return false;
    }

    /**
     * Enforces "gekmanager" access for current user in course context.
     *
     * @param int $courseid
     * @return void
     */
    public static function require_gekmanager_in_course(int $courseid): void {
        $context = \context_course::instance($courseid);
        if (self::has_gekmanager_in_course($courseid)) {
            return;
        }

        print_error('nopermissions', 'error', '', $context->get_context_name(false, true));
    }
}
