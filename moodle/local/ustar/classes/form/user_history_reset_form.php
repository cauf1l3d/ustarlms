<?php
namespace local_ustar\form;
defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

final class user_history_reset_form extends \moodleform {
    public function definition(): void {
        global $DB;
        $mform = $this->_form;
        $users = $DB->get_records_select('user', 'deleted = 0 AND id > 1', [], 'lastname ASC, firstname ASC, username ASC', 'id,username,firstname,lastname,email');
        $options = [0 => '— Выберите сотрудника —'];
        foreach ($users as $user) {
            $label = fullname($user) . ' [' . $user->username . ']';
            if (!empty($user->email)) { $label .= ' — ' . $user->email; }
            $options[(int)$user->id] = $label;
        }
        $mform->addElement('autocomplete', 'userid', 'Сотрудник', $options, ['multiple' => false]);
        $mform->setType('userid', PARAM_INT);
        $mform->addRule('userid', 'Выберите сотрудника', 'required', null, 'client');
        $mform->addElement('static', 'warning', 'Что будет удалено', 'Вся учебная история сотрудника: Quiz/SCORM попытки, completion, маршрутный прогресс USTAR, lifecycle аттестаций, переобучение и связанные workflow-события. <strong>Не удаляются:</strong> аккаунт, профиль, должность, staff place, руководитель, назначения, оргструктура, маршруты, контент, вопросы, политики, монеты, задачи и отзывы.');
        $mform->addElement('advcheckbox', 'confirmreset', 'Подтверждение', 'Я понимаю, что учебная история выбранного сотрудника будет удалена.');
        $mform->setType('confirmreset', PARAM_BOOL);
        $this->add_action_buttons(false, 'Очистить учебную историю');
    }

    public function validation($data, $files): array {
        global $DB;
        $errors = parent::validation($data, $files);
        $userid = (int)($data['userid'] ?? 0);
        if ($userid <= 1 || !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            $errors['userid'] = 'Выберите действующего сотрудника.';
        }
        if (empty($data['confirmreset'])) {
            $errors['confirmreset'] = 'Нужно подтвердить очистку.';
        }
        return $errors;
    }
}
