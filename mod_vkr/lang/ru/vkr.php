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
 * Plugin strings are defined here.
 *
 * @package     mod_vkr
 * @category    string
 * @copyright   2025 Ifraim Solomonov solomonov@sfedu.ru
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

//general
$string['pluginname'] = 'Защита ВКР';
$string['pluginadministration'] = 'Администрирование защиты ВКР';
$string['modulename'] = 'Защита ВКР';
$string['modulenameplural'] = 'Защиты ВКР';

//mdl_form
$string['vkrname'] = 'Название';
$string['vkrname_help'] = 'Введите название для модуля "Защита ВКР".';
$string['vkrsettings'] = 'Настройки';
$string['vkrfieldset'] = 'А эта строка тут нужна вообще?';

$string['main'] = 'Главная';
$string['prepare_course'] = 'Подготовить курс';
$string['prepare_course_help'] = 'Нажатие на кнопку создаст разделы для подготовки и защиты ВКР с необходимыми элементами';
$string['notification_courseprepared'] = 'Курс успешно подготовлен';
$string['prepared_course_help'] = 'В курсе уже есть необходимые разделы';
$string['reset_course'] = 'Сбросить курс';
$string['update_course'] = 'Обновить курс';
$string['notification_coursereset'] = 'Курс успешно сброшен';
$string['notification_courseupdated'] = 'Курс успешно обновлен';
$string['duedate'] = 'Срок сдачи ВКР';

//assessors
$string['assessors'] = 'Оценщики';
$string['noassessor'] = 'Без оценщика';
$string['selectgroup'] = 'Выбор группы';
$string['selectassessor'] = 'Выбор оценщика';
$string['apply'] = 'Подтвердить';
$string['notification_assessorassigned'] = 'Оценщики успешно назначены.';
$string['notification_assessorerror'] = 'Возникла ошибка при назначении оценщиков.';

$string['speciality'] = 'Направление подготовки';
$string['courseyear'] = 'Год';
$string['select_option'] = 'Выберите...';
$string['error_speciality'] = 'Выберите направление подготовки';
$string['error_courseyear'] = 'Выберите год';

$string['vkrsupervisors'] = 'Руководители ВКР';
$string['students'] = 'Студенты';
$string['notification_supervisorssaved'] = 'Назначения руководителей ВКР успешно сохранены.';
$string['notification_supervisorssaveerror'] = 'Ошибка при сохранении назначений руководителей ВКР.';
$string['notification_supervisormappingduplicate'] = 'Один студент может быть назначен только одному руководителю ВКР.';
