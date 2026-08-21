<?php
namespace local_ustar\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Bulk uploader for USTAR-owned knowledge materials.
 *
 * Each uploaded file becomes its own USTAR Content item and v1.
 */
class material_bulk extends \moodleform {

    protected function definition(): void {
        $mform = $this->_form;

        $categories = $this->_customdata['categories'] ?? [];
        $departments = $this->_customdata['departments'] ?? [];
        $positions = $this->_customdata['positions'] ?? [];

        $systemmax = get_max_upload_file_size();
        $hardmax = 512 * 1024 * 1024;
        $maxbytes = $systemmax > 0 ? min($systemmax, $hardmax) : $hardmax;

        $phpmaxfiles = (int)ini_get('max_file_uploads');
        if ($phpmaxfiles <= 0) {
            $phpmaxfiles = 20;
        }
        $maxfiles = min(100, $phpmaxfiles);

        $mform->addElement(
            'filemanager',
            'files',
            'Файлы',
            null,
            [
                'subdirs' => 0,
                'maxbytes' => $maxbytes,
                'maxfiles' => $maxfiles,
                'accepted_types' => [
                    '.pdf', '.doc', '.docx', '.xls', '.xlsx', '.ppt', '.pptx',
                    '.txt', '.csv', '.html', '.htm',
                    '.jpg', '.jpeg', '.png', '.webp',
                    '.mp4', '.webm', '.mov', '.m4v',
                    '.mp3', '.wav', '.m4a',
                ],
            ]
        );
        $mform->addRule('files', 'Добавьте хотя бы один файл', 'required', null, 'client');

        $categoryoptions = ['' => 'Без категории'];
        foreach ($categories as $id => $name) {
            $categoryoptions[$id] = $name;
        }

        $mform->addElement('select', 'category', 'Категория для всех файлов', $categoryoptions);
        $mform->setType('category', PARAM_ALPHANUMEXT);

        $mform->addElement(
            'textarea',
            'summary',
            'Общее описание',
            [
                'rows' => 3,
                'placeholder' => 'Необязательно. Будет применено ко всем загруженным материалам.',
            ]
        );
        $mform->setType('summary', PARAM_TEXT);

        $mform->addElement(
            'advcheckbox',
            'ackrequired',
            'Ознакомление',
            'Требовать подтверждение ознакомления для всех материалов'
        );

        $mform->addElement(
            'select',
            'accessmode',
            'Доступ',
            [
                'custom' => 'По подразделениям / должностям',
                'all' => 'Вся компания',
            ]
        );
        $mform->setType('accessmode', PARAM_ALPHA);

        $mform->addElement(
            'autocomplete',
            'departments',
            'Подразделения',
            $departments,
            ['multiple' => true]
        );
        $mform->setType('departments', PARAM_ALPHANUMEXT);

        $mform->addElement(
            'autocomplete',
            'positions',
            'Должности',
            $positions,
            ['multiple' => true]
        );
        $mform->setType('positions', PARAM_ALPHANUMEXT);

        $mform->addElement(
            'advcheckbox',
            'skipduplicates',
            'Дубликаты',
            'Пропускать материал, если уже есть USTAR File с таким названием',
            null,
            [0, 1]
        );
        $mform->setDefault('skipduplicates', 1);

        $mform->addElement(
            'advcheckbox',
            'publishnow',
            'Публикация',
            'Сразу опубликовать после успешной загрузки',
            null,
            [0, 1]
        );
        $mform->setDefault('publishnow', 0);

        $mform->addElement(
            'static',
            'publishnote',
            '',
            'Для нормативных документов сначала загрузите черновики, проверьте названия и аудиторию, затем публикуйте. Массовая публикация доступна, если доступ задан корректно.'
        );

        $this->add_action_buttons(true, 'Загрузить материалы');
    }

    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        $accessmode = (string)($data['accessmode'] ?? 'custom');
        $publishnow = !empty($data['publishnow']);
        $departments = $data['departments'] ?? [];
        $positions = $data['positions'] ?? [];

        if (
            $publishnow
            && $accessmode === 'custom'
            && empty($departments)
            && empty($positions)
        ) {
            $errors['accessmode'] = 'Для массовой публикации выберите хотя бы одно подразделение или должность.';
        }

        return $errors;
    }
}
