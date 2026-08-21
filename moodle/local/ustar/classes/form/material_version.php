<?php
namespace local_ustar\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->libdir . '/formslib.php');


class material_version extends \moodleform {

    protected function definition(): void {

        $mform =
            $this->_form;


        /*
         * USTAR_VERSION_CONTENT_ID
         *
         * Preserve the material ID on POST.
         */
        $contentid =
            (int)(
                $this->_customdata['contentid']
                ?? 0
            );


        $mform->addElement(
            'hidden',
            'id',
            $contentid
        );

        $mform->setType(
            'id',
            PARAM_INT
        );



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
            'textarea',
            'changenote',
            'Что изменилось',
            [
                'rows' =>
                    3,

                'placeholder' =>
                    'Например: обновлены правила оформления и сроки',
            ]
        );

        $mform->setType(
            'changenote',
            PARAM_TEXT
        );


        $mform->addElement(
            'filepicker',
            'contentfile',
            'Новый файл',
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
            'Добавьте файл новой версии',
            'required',
            null,
            'client'
        );


        $this->add_action_buttons(
            true,
            'Создать новую версию'
        );
    }
}
