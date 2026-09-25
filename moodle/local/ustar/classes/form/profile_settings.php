<?php
namespace local_ustar\form;
defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->libdir . '/formslib.php');

final class profile_settings extends \moodleform {
    public function definition() {
        $m = $this->_form;
        $m->addElement('header', 'appearance', 'Личные настройки');
        $m->addElement('select', 'preset', 'Цвет Академии', [
            'yellow'=>'USTAR', 'graphite'=>'Графит', 'ocean'=>'Океан',
            'forest'=>'Лес', 'berry'=>'Ягодный', 'sand'=>'Песок']);
        $m->addElement('select', 'lang', 'Язык', get_string_manager()->get_list_of_translations());
        $m->addElement('select', 'timezone', 'Часовой пояс', \core_date::get_list_of_timezones());
        if ($this->_customdata['canphoto']) {
            $m->addElement('header', 'photo', 'Фотография');
            $m->addElement('filemanager', 'imagefile', 'Новое фото', null, $this->_customdata['fileoptions']);
            $m->addElement('advcheckbox', 'deletepicture', 'Удалить текущее фото');
        }
        $this->add_action_buttons(true, 'Сохранить настройки');
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        foreach (['preset'=>['yellow'=>1,'graphite'=>1,'ocean'=>1,'forest'=>1,'berry'=>1,'sand'=>1],
            'lang'=>get_string_manager()->get_list_of_translations(),
            'timezone'=>\core_date::get_list_of_timezones()] as $key=>$allowed) {
            if (!array_key_exists((string)($data[$key] ?? ''), $allowed)) {$errors[$key]='Выберите значение из списка.';}
        }
        return $errors;
    }
}
