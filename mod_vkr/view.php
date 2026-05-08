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
require_once(__DIR__.'/classes/form/supervisors_form.php');
require_once(__DIR__.'/classes/form/reviewer_form.php');

/**
 * Hide already selected students in other marker assignment autocompletes.
 *
 * @param string $selector
 * @return void
 */
function mod_vkr_add_unique_student_select_js(string $selector): void {
    global $PAGE;

    $encodedselector = json_encode($selector, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    $PAGE->requires->js_init_code(<<<JS
(function() {
    const selector = {$encodedselector};
    const selects = Array.from(document.querySelectorAll(selector));
    if (!selects.length) {
        return;
    }

    const masterOptionsBySelect = new Map();
    selects.forEach(function(select) {
        masterOptionsBySelect.set(
            select,
            Array.from(select.options).map(function(option) {
                return {
                    value: option.value,
                    text: option.text
                };
            })
        );
    });

    const syncOptions = function() {
        const selectedByAny = new Set();

        selects.forEach(function(select) {
            const selected = Array.from(select.selectedOptions).map(function(option) {
                return option.value;
            }).filter(function(value) {
                return value !== '';
            });

            Array.from(new Set(selected)).forEach(function(value) {
                selectedByAny.add(value);
            });
        });

        selects.forEach(function(select) {
            const ownselected = new Set(
                Array.from(select.selectedOptions).map(function(option) {
                    return option.value;
                })
            );

            const usedbyothers = new Set(
                Array.from(selectedByAny).filter(function(value) {
                    return !ownselected.has(value);
                })
            );

            const masterOptions = masterOptionsBySelect.get(select) || [];
            const selectedValues = Array.from(ownselected);

            select.innerHTML = '';
            masterOptions.forEach(function(optiondata) {
                if (optiondata.value === '') {
                    const emptyoption = new Option(optiondata.text, optiondata.value, false, false);
                    select.add(emptyoption);
                    return;
                }

                if (usedbyothers.has(optiondata.value)) {
                    return;
                }

                const isselected = ownselected.has(optiondata.value);
                const option = new Option(optiondata.text, optiondata.value, isselected, isselected);
                select.add(option);
            });

            selectedValues.forEach(function(value) {
                const option = Array.from(select.options).find(function(item) {
                    return item.value === value;
                });
                if (option) {
                    option.selected = true;
                }
            });

            if (window.jQuery) {
                window.jQuery(select).trigger('change.select2');
            }
        });
    };

    document.addEventListener('change', function(event) {
        if (event.target && event.target.matches(selector)) {
            syncOptions();
        }
    });

    syncOptions();
})();
JS
    );
}

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
\mod_vkr\local\access::require_gekmanager_access((int)$course->id);

$modulecontext = context_module::instance($cm->id);
$needtoprepare = (bool)\local_vkr\course_builder::need_to_prepare($course->id);

if ($needtoprepare && $tab !== 'main') {
    redirect(new moodle_url('/mod/vkr/view.php', ['id' => $cm->id, 'tab' => 'main']));
}

$PAGE->set_url('/mod/vkr/view.php', ['id' => $cm->id, 'tab' => $tab]);
$PAGE->set_title(format_string($moduleinstance->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($modulecontext);

// Код ниже можно редактировать.
$PAGE->requires->css('/mod/vkr/styles.css');

$tabs = [
    new tabobject('main', new moodle_url('/mod/vkr/view.php', ['id' => $cm->id]), get_string('main', 'mod_vkr')),
];
$supervisorsform = null;
$reviewerform = null;
$reviewassignmentavailable = false;

if (!$needtoprepare) {
    $tabs[] = new tabobject(
        'assessors',
        new moodle_url('/mod/vkr/view.php', ['id' => $cm->id, 'tab' => 'assessors']),
        get_string('assessors', 'mod_vkr')
    );
    $tabs[] = new tabobject(
        'supervisors',
        new moodle_url('/mod/vkr/view.php', ['id' => $cm->id, 'tab' => 'supervisors']),
        get_string('vkrsupervisors', 'mod_vkr')
    );
    $tabs[] = new tabobject(
        'reviewer',
        new moodle_url('/mod/vkr/view.php', ['id' => $cm->id, 'tab' => 'reviewer']),
        get_string('reviewer', 'mod_vkr')
    );
}

switch ($tab) {
    case 'assessors':
        if (optional_param('saveassessors', 0, PARAM_BOOL) && confirm_sesskey()) {
            $success = true;
            $groups = groups_get_all_groups($course->id, 0, 0, 'g.*', 'name ASC');
            foreach (\mod_vkr\assessors_manager::get_assessor_sections() as $section) {
                $modulekey = $section['modulekey'];
                if (!\mod_vkr\assessors_manager::is_assessor_assignment_available($course->id, $modulekey)) {
                    continue;
                }

                foreach ($groups as $group) {
                    $fieldname = \mod_vkr\assessors_manager::get_assessor_field_name($modulekey, (int)$group->id);
                    $assessorid = optional_param($fieldname, 0, PARAM_INT);
                    error_log('[mod_vkr][assessors] Received form value ' . json_encode([
                        'courseid' => (int)$course->id,
                        'modulekey' => $modulekey,
                        'groupid' => (int)$group->id,
                        'fieldname' => $fieldname,
                        'assessorid' => $assessorid,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    if ($assessorid == 0) {
                        $assessorid = null;
                    }
                    $result = \mod_vkr\assessors_manager::assign_assessor(
                        $course->id,
                        (int)$group->id,
                        $assessorid,
                        $modulekey
                    );
                    if (!$result) {
                        $success = false;
                    }
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

    case 'supervisors':
        $supervisors = \mod_vkr\assessors_manager::get_supervisor_role_users($course->id);
        $students = \mod_vkr\assessors_manager::get_student_role_users($course->id);
        $currentmapping = \mod_vkr\assessors_manager::get_advisor_supervisor_map($course->id);
        $supervisorsform = new \mod_vkr\form\supervisors_form(null, [
            'cmid' => $cm->id,
            'supervisors' => $supervisors,
            'students' => $students,
            'currentmapping' => $currentmapping,
        ]);

        if ($data = $supervisorsform->get_data()) {
            $selectedmap = [];
            $studenttosupervisor = [];
            $hasduplicates = false;

            foreach ($supervisors as $supervisor) {
                $fieldname = 'supervisor_students_' . (int)$supervisor->id;
                $studentids = $data->{$fieldname} ?? [];
                if (!is_array($studentids)) {
                    $studentids = [$studentids];
                }
                $studentids = array_values(array_unique(array_filter(array_map('intval', $studentids))));
                $selectedmap[(int)$supervisor->id] = $studentids;

                foreach ($studentids as $studentid) {
                    if (array_key_exists($studentid, $studenttosupervisor)) {
                        $hasduplicates = true;
                        break 2;
                    }
                    $studenttosupervisor[$studentid] = (int)$supervisor->id;
                }
            }

            if ($hasduplicates) {
                \core\notification::error(get_string('notification_supervisormappingduplicate', 'mod_vkr'));
            } else {
                $success = \mod_vkr\assessors_manager::assign_advisor_supervisors($course->id, $selectedmap);
                if ($success) {
                    \core\notification::success(get_string('notification_supervisorssaved', 'mod_vkr'));
                } else {
                    \core\notification::error(get_string('notification_supervisorssaveerror', 'mod_vkr'));
                }
            }

            redirect(new moodle_url('/mod/vkr/view.php', ['id' => $cm->id, 'tab' => 'supervisors']));
        }

        mod_vkr_add_unique_student_select_js("select[name^='supervisor_students_']");

        break;

    case 'reviewer':
        $reviewassignmentavailable = \mod_vkr\assessors_manager::is_assessor_assignment_available($course->id, 'review');
        if (!$reviewassignmentavailable) {
            if (optional_param('savereviewers', 0, PARAM_BOOL) && confirm_sesskey()) {
                \core\notification::error(get_string('notification_reviewerssaveerror', 'mod_vkr'));
                redirect(new moodle_url('/mod/vkr/view.php', ['id' => $cm->id, 'tab' => 'reviewer']));
            }
            break;
        }

        $reviewers = \mod_vkr\assessors_manager::get_reviewer_role_users($course->id);
        $students = \mod_vkr\assessors_manager::get_student_role_users($course->id);
        $currentmapping = \mod_vkr\assessors_manager::get_review_reviewer_map($course->id);
        $reviewerform = new \mod_vkr\form\reviewer_form(null, [
            'cmid' => $cm->id,
            'reviewers' => $reviewers,
            'students' => $students,
            'currentmapping' => $currentmapping,
            'assignmentavailable' => $reviewassignmentavailable,
        ]);

        if ($data = $reviewerform->get_data()) {
            $selectedmap = [];
            $studenttoreviewer = [];
            $hasduplicates = false;

            foreach ($reviewers as $reviewer) {
                $fieldname = 'reviewer_students_' . (int)$reviewer->id;
                $studentids = $data->{$fieldname} ?? [];
                if (!is_array($studentids)) {
                    $studentids = [$studentids];
                }
                $studentids = array_values(array_unique(array_filter(array_map('intval', $studentids))));
                $selectedmap[(int)$reviewer->id] = $studentids;

                foreach ($studentids as $studentid) {
                    if (array_key_exists($studentid, $studenttoreviewer)) {
                        $hasduplicates = true;
                        break 2;
                    }
                    $studenttoreviewer[$studentid] = (int)$reviewer->id;
                }
            }

            if ($hasduplicates) {
                \core\notification::error(get_string('notification_reviewermappingduplicate', 'mod_vkr'));
            } else {
                $success = \mod_vkr\assessors_manager::assign_review_reviewers($course->id, $selectedmap);
                if ($success) {
                    \core\notification::success(get_string('notification_reviewerssaved', 'mod_vkr'));
                } else {
                    \core\notification::error(get_string('notification_reviewerssaveerror', 'mod_vkr'));
                }
            }

            redirect(new moodle_url('/mod/vkr/view.php', ['id' => $cm->id, 'tab' => 'reviewer']));
        }

        mod_vkr_add_unique_student_select_js("select[name^='reviewer_students_']");

        break;

    default:
        $availablemodules = \local_vkr\course_builder::get_default_modules();
        $selectedmodules = \local_vkr\course_builder::get_selected_module_keys($course->id);
        $moduleduedates = \local_vkr\course_builder::get_module_due_dates($course->id);
        $specialityoptions = \local_vkr\course_builder::get_training_direction_options();
        $yearoptions = \local_vkr\course_builder::get_year_options();
        $selectednameconfig = \local_vkr\course_builder::get_selected_course_name_config($course->id);
        if (!empty($selectednameconfig['speciality']) &&
                !array_key_exists($selectednameconfig['speciality'], $specialityoptions)) {
            $specialityoptions[$selectednameconfig['speciality']] = $selectednameconfig['speciality'];
        }
        $selectedyearkey = (string)$selectednameconfig['courseyear'];
        if (!array_key_exists($selectedyearkey, $yearoptions)) {
            $yearoptions[$selectedyearkey] = $selectedyearkey;
        }
        $formdata = [
            'cmid' => $cm->id,
            'needtoprepare' => $needtoprepare,
            'availablemodules' => $availablemodules,
            'selectedmodules' => $selectedmodules,
            'moduleduedates' => $moduleduedates,
            'specialityoptions' => $specialityoptions,
            'yearoptions' => $yearoptions,
            'selectedspeciality' => $selectednameconfig['speciality'],
            'selectedcourseyear' => $selectednameconfig['courseyear'],
        ];
        $form = new \mod_vkr\form\main_form(null, $formdata);
        if ($data = $form->get_data()) {
            $selectedmodulekeys = [];
            $moduleduedates = [];
            foreach (array_keys($availablemodules) as $modulekey) {
                $fieldname = 'module_' . $modulekey;
                $duedatefield = 'duedate_' . $modulekey;
                if (!empty($data->{$fieldname})) {
                    $selectedmodulekeys[] = $modulekey;
                    $moduleduedates[$modulekey] = isset($data->{$duedatefield})
                        ? (int)$data->{$duedatefield}
                        : time();
                }
            }

            if (!empty($data->preparebtn)) {
                \local_vkr\course_builder::prepare_course(
                    $course->id,
                    $moduleduedates,
                    $data->speciality,
                    (int)$data->courseyear,
                    $selectedmodulekeys
                );
                \core\notification::success(get_string('notification_courseprepared', 'mod_vkr'));
            } else if (!empty($data->updatebtn)) {
                \local_vkr\course_builder::update_course(
                    $course->id,
                    $moduleduedates,
                    $selectedmodulekeys,
                    $data->speciality,
                    (int)$data->courseyear
                );
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

    case 'supervisors':
        if ($supervisorsform !== null) {
            $supervisorsform->display();
        }
        break;

    case 'reviewer':
        if (!$reviewassignmentavailable) {
            echo \html_writer::div(get_string('reviewassignmentmissing', 'mod_vkr'), 'alert alert-warning');
        } else if ($reviewerform !== null) {
            $reviewerform->display();
        }
        break;

    default:
        $form->display();
        break;
}

echo $OUTPUT->footer();
