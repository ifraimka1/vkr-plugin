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
 * Prints an instance of mod_vkr.
 *
 * @package     mod_vkr
 * @copyright   2025 Ifraim Solomonov <solomonov@sfedu.ru>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__.'/../../config.php');
require_once(__DIR__.'/lib.php');

// Course module id.
$id = optional_param('id', 0, PARAM_INT);
// Activity instance id.
$v = optional_param('v', 0, PARAM_INT);
// Tab.
$tab = optional_param('tab', 'main', PARAM_ALPHA);

if ($id) {
    $cm = get_coursemodule_from_id('vkr', $id, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    $moduleinstance = $DB->get_record('vkr', ['id' => $cm->instance], '*', MUST_EXIST);
} else {
    $moduleinstance = $DB->get_record('vkr', ['id' => $v], '*', MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $moduleinstance->course], '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('vkr', $moduleinstance->id, $course->id, false, MUST_EXIST);
}

require_login($course, true, $cm);

$modulecontext = context_module::instance($cm->id);

$PAGE->set_url('/mod/vkr/view.php', ['id' => $cm->id, 'tab' => $tab]);
$PAGE->set_title(format_string($moduleinstance->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($modulecontext);

// Код ниже можно редактировать.
$PAGE->requires->css('/mod/vkr/styles.css');

$tabs = [
    new tabobject('main', new moodle_url('/mod/vkr/view.php', ['id' => $cm->id]), get_string('main', 'mod_vkr')),
    new tabobject('assessors', new moodle_url('/mod/vkr/view.php', ['id' => $cm->id, 'tab' => 'assessors']), get_string('assessors', 'mod_vkr')),
];

switch ($tab) {
    case 'assessors':
        if (optional_param('saveassessors', 0, PARAM_BOOL) && confirm_sesskey()) {
            $success = true;
            $groups = groups_get_all_groups($course->id, 0, 0, 'g.*', 'name ASC');
            foreach ($groups as $group) {
                $fieldname = 'assessorid_' . $group->id;
                $assessorid = optional_param($fieldname, 0, PARAM_INT);
                error_log('[mod_vkr][assessors] Received form value ' . json_encode([
                    'courseid' => (int)$course->id,
                    'groupid' => (int)$group->id,
                    'fieldname' => $fieldname,
                    'assessorid' => $assessorid,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                if ($assessorid == 0) {
                    $assessorid = null;
                }
                $result = \mod_vkr\assessors_manager::assign_assessor($course->id, $group->id, $assessorid);
                if (!$result) {
                    $success = false;
                }
            }
            if ($success) {
                \core\notification::success(get_string('notification_assessorassigned', 'mod_vkr'));
            } else {
                \core\notification::error(get_string('notification_assessorerror', 'mod_vkr'));
            }
            redirect(new moodle_url('/mod/vkr/view.php', ['id' => $cm->id, 'tab' => 'assessors']));
        }

        break;

    default:
        $availablemodules = \local_vkr\course_builder::get_default_modules();
        $selectedmodules = \local_vkr\course_builder::get_selected_module_keys($course->id);
        $formdata = [
            'cmid' => $cm->id,
            'needtoprepare' => (bool)\local_vkr\course_builder::need_to_prepare($course->id),
            'availablemodules' => $availablemodules,
            'selectedmodules' => $selectedmodules,
        ];
        $form = new \mod_vkr\form\main_form(null, $formdata);
        if ($data = $form->get_data()) {
            $selectedmodulekeys = [];
            foreach (array_keys($availablemodules) as $modulekey) {
                $fieldname = 'module_' . $modulekey;
                if (!empty($data->{$fieldname})) {
                    $selectedmodulekeys[] = $modulekey;
                }
            }

            if (!empty($data->preparebtn)) {
                \local_vkr\course_builder::prepare_course($course->id, $data->duedate, $selectedmodulekeys);
                \core\notification::success(get_string('notification_courseprepared', 'mod_vkr'));
            } else if (!empty($data->updatebtn)) {
                \local_vkr\course_builder::update_course($course->id, $data->duedate, $selectedmodulekeys);
                \core\notification::success(get_string('notification_courseupdated', 'mod_vkr'));
            } else if (!empty($data->resetbtn)) {
                \local_vkr\course_builder::reset_course($course->id);
                \core\notification::success(get_string('notification_coursereset', 'mod_vkr'));
            }
            redirect(new moodle_url('/mod/vkr/view.php', ['id' => $cm->id]));
        }
        break;
}

echo $OUTPUT->header();
echo $OUTPUT->tabtree($tabs, $tab);

switch ($tab) {
    case 'assessors':
        echo \mod_vkr\assessors_manager::render_assessors_form($cm->id, $course->id);
        break;

    default:
        $form->display();
        break;
}

echo $OUTPUT->footer();
