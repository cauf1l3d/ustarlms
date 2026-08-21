<?php

defined('MOODLE_INTERNAL') || die();

/**
 * Serve USTAR-owned content files.
 *
 * Files:
 *
 * context   = system
 * component = local_ustar
 * filearea  = content_version
 * itemid    = content version id
 */
function local_ustar_pluginfile(
    $course,
    $cm,
    $context,
    string $filearea,
    array $args,
    bool $forcedownload,
    array $options = []
): bool {
    global $USER;

    require_login();


    if (
        $context->contextlevel
        !==
        CONTEXT_SYSTEM
    ) {
        return false;
    }


    if ($filearea === 'game_question_image') {
        global $DB;

        if (!$args) {
            return false;
        }

        $questionid = (int)array_shift($args);
        if ($questionid <= 0 || !$args) {
            return false;
        }

        $question = $DB->get_record('local_ustar_questions', ['id' => $questionid]);
        if (!$question) {
            return false;
        }

        $game = $DB->get_record('local_ustar_games', ['id' => $question->gameid]);
        if (!$game) {
            return false;
        }

        $isadmin = has_capability('local/ustar:admin', $context);
        if (!$isadmin) {
            require_capability('local/ustar:use', $context);
            if (empty($game->active) || empty($question->active)) {
                return false;
            }

            $resolved = \local_ustar\structure::resolve_user((int)$USER->id);
            $department = (string)($resolved['position']['department'] ?? '');
            if (!empty($game->department) && (string)$game->department !== $department) {
                return false;
            }
        }

        $filename = array_pop($args);
        $filepath = '/' . ($args ? implode('/', $args) . '/' : '');
        $file = get_file_storage()->get_file(
            $context->id,
            'local_ustar',
            'game_question_image',
            $questionid,
            $filepath,
            $filename
        );

        if (!$file || $file->is_directory()) {
            return false;
        }

        $mimetype = (string)$file->get_mimetype();
        if (!in_array($mimetype, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            return false;
        }

        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: default-src \'none\'; img-src \'self\'; sandbox;');
        send_stored_file($file, DAYSECS, 0, false, $options);
        return true;
    }


    if (in_array($filearea, ['catalog_image', 'catalog_source'], true)) {
        require_capability('local/ustar:use', $context);

        if (!$args) {
            return false;
        }

        $itemid = (int)array_shift($args);
        if ($itemid <= 0 || !$args || !\local_ustar\catalog::get($itemid)) {
            return false;
        }

        $filename = array_pop($args);
        $filepath = '/' . ($args ? implode('/', $args) . '/' : '');
        $file = get_file_storage()->get_file(
            $context->id,
            'local_ustar',
            $filearea,
            $itemid,
            $filepath,
            $filename
        );

        if (!$file || $file->is_directory()) {
            return false;
        }

        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');

        if ($filearea === 'catalog_image') {
            $mimetype = (string)$file->get_mimetype();
            if (!in_array($mimetype, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
                return false;
            }
            header("Content-Security-Policy: default-src 'none'; img-src 'self'; sandbox;");
            send_stored_file($file, DAYSECS, 0, false, $options);
            return true;
        }

        send_stored_file($file, 0, 0, true, $options);
        return true;
    }


    if (
        $filearea
        !==
        'content_version'
    ) {
        return false;
    }


    if (!$args) {
        return false;
    }


    $versionid =
        (int)array_shift(
            $args
        );


    if ($versionid <= 0) {
        return false;
    }


    if (
        !\local_ustar\content::can_access_version(
            $versionid,
            (int)$USER->id
        )
    ) {
        return false;
    }


    if (!$args) {
        return false;
    }


    $filename =
        array_pop(
            $args
        );


    $filepath =
        '/'
        .
        (
            $args
                ? implode(
                    '/',
                    $args
                )
                    .
                    '/'
                : ''
        );


    $file =
        get_file_storage()
            ->get_file(
                $context->id,
                'local_ustar',
                'content_version',
                $versionid,
                $filepath,
                $filename
            );


    if (
        !$file
        ||
        $file->is_directory()
    ) {
        return false;
    }


    /*
     * USTAR_ACTIVE_CONTENT_SECURITY
     *
     * Active content must never inherit the Moodle / USTAR
     * application origin.
     *
     * HTML:
     *   - opaque sandboxed origin
     *   - scripts allowed only when their SHA-256 matches
     *   - no network access
     *   - no forms / frames / objects
     *
     * SVG:
     *   - scripts disabled completely
     */
    $mimetype =
        (string)$file->get_mimetype();


    if ($mimetype === 'text/html') {

        $html =
            $file->get_content();

        $hashes = [];


        if (
            preg_match_all(
                '~<script\b[^>]*>(.*?)</script>~is',
                $html,
                $matches
            )
        ) {

            foreach ($matches[1] as $script) {

                $hashes[] =
                    "'sha256-"
                    .
                    base64_encode(
                        hash(
                            'sha256',
                            $script,
                            true
                        )
                    )
                    .
                    "'";
            }
        }


        $hashes =
            array_values(
                array_unique(
                    $hashes
                )
            );


        $scriptpolicy =
            $hashes
                ? 'script-src '
                    .
                    implode(
                        ' ',
                        $hashes
                    )
                    .
                    '; '
                : "script-src 'none'; ";


        header(
            'Content-Security-Policy: '
            .
            'sandbox allow-scripts; '
            .
            "default-src 'none'; "
            .
            $scriptpolicy
            .
            "style-src 'unsafe-inline'; "
            .
            'img-src data: blob:; '
            .
            'font-src data:; '
            .
            'media-src data: blob:; '
            .
            "connect-src 'none'; "
            .
            "frame-src 'none'; "
            .
            "object-src 'none'; "
            .
            "form-action 'none'; "
            .
            "base-uri 'none'; "
            .
            "frame-ancestors 'self';"
        );


        header(
            'Referrer-Policy: no-referrer'
        );

        header(
            'X-Content-Type-Options: nosniff'
        );


    } else if (
        $mimetype
        ===
        'image/svg+xml'
    ) {

        header(
            'Content-Security-Policy: '
            .
            'sandbox; '
            .
            "default-src 'none'; "
            .
            "script-src 'none'; "
            .
            "style-src 'unsafe-inline'; "
            .
            'img-src data:; '
            .
            "object-src 'none'; "
            .
            "base-uri 'none'; "
            .
            "frame-ancestors 'self';"
        );


        header(
            'Referrer-Policy: no-referrer'
        );

        header(
            'X-Content-Type-Options: nosniff'
        );
    }


    send_stored_file(
        $file,
        DAYSECS,
        0,
        $forcedownload,
        $options
    );


    return true;
}
