<?php
namespace local_ustar\form;
defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

final class user_history_reset_form extends \moodleform {
    public function definition(): void {
        global $DB, $USER;
        $mform = $this->_form;
        $users = $DB->get_records_sql('SELECT id, username, firstname, lastname, email
            FROM {user} WHERE id > 1 AND deleted = 0 ORDER BY lastname, firstname, id');
        $options = [0 => '— Выберите сотрудника —'];
        foreach ($users as $user) {
            $employee = \local_ustar\accounts::is_business_account((int)$user->id);
            $tester = (string)$user->username === \local_ustar\route_tester::USERNAME_PREFIX . (int)$USER->id
                && $DB->record_exists('local_ustar_route_testers',
                    ['actorid' => (int)$USER->id, 'sandboxuserid' => (int)$user->id]);
            if (!$employee && !$tester) {
                continue;
            }
            $label = fullname($user) . ' [' . $user->username . ']';
            if (!empty($user->email)) { $label .= ' — ' . $user->email; }
            $options[(int)$user->id] = $label;
        }
        $mform->addElement('autocomplete', 'userid', 'Сотрудник или ваша тестовая учётная запись', $options, ['multiple' => false]);
        $mform->setType('userid', PARAM_INT);
        $mform->addRule('userid', 'Выберите сотрудника', 'required', null, 'client');
        $mform->addElement('static', 'warning', 'Что будет сброшено', 'Все Quiz/SCORM попытки и завершения курсов этого сотрудника, прогресс точек маршрута, аттестации и связанные учебные события. Подтверждённые циклы и награды за маршрут будут отозваны; если наградные монеты уже потрачены, новые начисления сначала погасит этот остаток. Сохраняются учётная запись, профиль, назначения, структура, маршруты, контент и проверяемая история наградных операций.');
        $mform->addElement('advcheckbox', 'confirmreset', 'Подтверждение', 'Я понимаю, что прохождение маршрута и награды выбранного сотрудника будут сброшены.');
        $mform->setType('confirmreset', PARAM_BOOL);
        $this->add_action_buttons(false, 'Очистить учебную историю');
    }

    public function validation($data, $files): array {
        global $DB, $USER;
        $errors = parent::validation($data, $files);
        $userid = (int)($data['userid'] ?? 0);
        if ($userid <= 1 || !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            $errors['userid'] = 'Выберите сотрудника.';
        } else {
            try {
                \local_ustar\user_history_reset::assert_allowed($userid, (int)$USER->id);
            } catch (\moodle_exception $e) {
                $errors['userid'] = $e->getMessage();
            }
        }
        if (empty($data['confirmreset'])) {
            $errors['confirmreset'] = 'Нужно подтвердить очистку.';
        }
        return $errors;
    }
}
