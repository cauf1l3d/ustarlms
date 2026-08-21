<?php
namespace local_ustar\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->libdir . '/formslib.php');


class material_create extends \moodleform {

    protected function definition(): void {
        $mform = $this->_form;

        $categories =
            $this->_customdata['categories']
            ?? [];


        /*
         * Hard product limit for v1 = 512 MiB,
         * while still respecting Moodle/PHP limits.
         */
        $systemmax =
            get_max_upload_file_size();

        $hardmax =
            512 * 1024 * 1024;

        $maxbytes =
            $systemmax > 0
                ? min(
                    $systemmax,
                    $hardmax
                )
                : $hardmax;


        $mform->addElement(
            'text',
            'title',
            'Название',
            [
                'maxlength' =>
                    255,

                'size' =>
                    60,
            ]
        );

        $mform->setType(
            'title',
            PARAM_TEXT
        );

        $mform->addRule(
            'title',
            'Укажите название материала',
            'required',
            null,
            'client'
        );


        $categoryoptions = [
            '' =>
                'Без категории',
        ];

        foreach (
            $categories
            as $id => $name
        ) {
            $categoryoptions[$id] =
                $name;
        }


        $mform->addElement(
            'select',
            'category',
            'Категория',
            $categoryoptions
        );

        $mform->setType(
            'category',
            PARAM_ALPHANUMEXT
        );


        $mform->addElement(
            'textarea',
            'summary',
            'Описание',
            [
                'rows' =>
                    4,

                'placeholder' =>
                    'Коротко: что это за материал и когда его использовать',
            ]
        );

        $mform->setType(
            'summary',
            PARAM_TEXT
        );


        /*
         * Single-file upload.
         *
         * Tests / SCORM remain Moodle runtime.
         * This uploader is for USTAR-owned content.
         */
        $mform->addElement(
            'filepicker',
            'contentfile',
            'Файл',
            null,
            [
                'maxbytes' =>
                    $maxbytes,

                'accepted_types' => [
                    '.pdf',
                    '.doc',
                    '.docx',
                    '.xls',
                    '.xlsx',
                    '.ppt',
                    '.pptx',
                    '.txt',
                    '.csv',

                    '.html',
                    '.htm',

                    '.jpg',
                    '.jpeg',
                    '.png',
                    '.webp',

                    '.mp4',
                    '.webm',
                    '.mov',
                    '.m4v',

                    '.mp3',
                    '.wav',
                    '.m4a',
                ],
            ]
        );

        $mform->addRule(
            'contentfile',
            'Добавьте файл',
            'required',
            null,
            'client'
        );


        $mform->addElement(
            'advcheckbox',
            'ackrequired',
            'Ознакомление',
            'Требовать подтверждение ознакомления'
        );


        $this->add_action_buttons(
            true,
            'Создать черновик'
        );
    }
}
