<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

/** Portable visual source codec. Validation never executes or silently rewrites imported source. */
final class course_studio_project {
    public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR;
    public static function encode(mixed $value): string {
        return json_encode($value, self::JSON_FLAGS);
    }
    public static function fingerprint(\stdClass $project): string {
        return hash('sha256', self::encode($project));
    }
    public static function path(string $path): bool {
        return (bool)preg_match('~^(assets|fonts)/[a-zA-Z0-9_./-]+$~D', $path)
            && !str_contains($path, '..') && !str_contains($path, '//');
    }
    private static function reject(string $message): never {
        throw new \invalid_parameter_exception($message);
    }
    public static function validate(\stdClass $p): void {
        if (strlen(self::encode($p)) > 5 * 1024 * 1024 || !isset($p->data, $p->theme)
                || !is_object($p->data) || !is_object($p->theme)
                || !preg_match('/^[a-zA-Z0-9_-]{1,60}$/D', $p->data->code ?? '')
                || !is_string($p->data->title ?? null) || trim($p->data->title) === ''
                || mb_strlen($p->data->title, 'UTF-8') > 255
                || !is_array($p->data->pages ?? null) || count($p->data->pages) < 1
                || count($p->data->pages) > 500 || !is_object($p->assets ?? null)
                || !is_array($p->fonts ?? null) || count($p->fonts) > 40) {
            self::reject('Некорректный проект: до 500 экранов и название до 255 символов.');
        }
        foreach (['fontSize' => [12,40], 'headingSize' => [18,72], 'lineHeight' => [1.3,2]] as $key => [$min,$max]) {
            $n = $p->theme->$key ?? null;
            if ((!is_float($n) && !is_int($n)) || $n < $min || $n > $max) { self::reject('Некорректный размер: ' . $key); }
        }
        foreach (['font','headingFont'] as $key) {
            if (!preg_match('/^[\w ,.-]{1,120}$/D', $p->theme->$key ?? '')) { self::reject('Некорректный шрифт.'); }
        }
        foreach (['ink','accent','paper','background'] as $key) {
            if (!preg_match('/^#[0-9a-f]{6}$/iD', $p->theme->$key ?? '')) { self::reject('Некорректный цвет.'); }
        }
        $size = 0;
        foreach ((array)$p->assets as $path => $a) {
            if (!self::path($path) || !is_object($a) || !preg_match('/^[a-f0-9]{64}$/D', $a->hash ?? '')
                    || !is_int($a->size ?? null) || $a->size <= 0 || $a->size > 25 * 1024 * 1024
                    || !in_array($a->type ?? '', ['image/png','image/jpeg','image/gif','image/webp',
                        'font/woff','font/woff2','font/ttf','font/otf'], true)) { self::reject('Некорректный ресурс проекта.'); }
            $size += $a->size;
        }
        if ($size > 150 * 1024 * 1024 || count((array)$p->assets) > 4000) { self::reject('Ресурсы превышают 150 МБ или 4000 файлов.'); }
        $ids = [];
        foreach ($p->data->pages as $page) {
            if (!is_object($page) || !is_string($page->id ?? null) || strlen($page->id) > 200
                    || isset($ids[$page->id]) || !is_string($page->title ?? null)
                    || trim($page->title) === '' || !is_string($page->html ?? null)
                    || strlen($page->html) > 2000000) { self::reject('Некорректный экран курса.'); }
            foreach (['chapter','kind','source_text','titleHTML'] as $field) {
                if (isset($page->$field) && !is_string($page->$field)) { self::reject('Некорректные свойства экрана.'); }
            }
            if (isset($page->titleStyle) && !is_object($page->titleStyle)) { self::reject('Некорректные свойства заголовка.'); }
            $ids[$page->id] = true;
            self::html($page->html, $p);
            self::html((string)($page->titleHTML ?? ''), $p);
            foreach ((array)($page->titleStyle ?? []) as $key => $value) {
                if (!in_array($key, ['textAlign','color','fontWeight','fontStyle','textDecoration','fontSize',
                        'fontFamily','backgroundColor'], true) || !is_string($value)
                        || preg_match('/[<>;{}\\\\]|url|expression|@/i', $value)) { self::reject('Некорректное оформление заголовка.'); }
            }
        }
        foreach ($p->fonts as $f) {
            if (!is_object($f) || !preg_match('/^RCFont[a-zA-Z0-9_-]+$/D', $f->family ?? '')
                    || !isset($p->assets->{$f->path ?? ''}) || !str_starts_with($f->path, 'fonts/')) {
                self::reject('Некорректный файл шрифта.');
            }
        }
    }
    private static function html(string $html, \stdClass $p): void {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try { $dom->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NONET); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $tags = ['html','body','p','div','span','section','article','button','img','br','li','figure','figcaption',
            'h1','h2','h3','h4','h5','h6','details','summary','strong','b','i','em','u','s','strike','del','sub','sup',
            'mark','small','ul','ol','a','blockquote','pre','code','table','thead','tbody','tfoot','tr','th','td','hr','font'];
        foreach ($dom->getElementsByTagName('*') as $el) {
            if (!in_array(strtolower($el->tagName), $tags, true)) { self::reject('Запрещённый HTML-тег: ' . $el->tagName); }
            foreach ($el->attributes as $attr) {
                $name = strtolower($attr->name); $v = $attr->value;
                if (!in_array($name, ['class','style','src','data-image','data-source-id','alt','loading','type',
                        'aria-label','href','target','rel','title','colspan','rowspan','align','width','height',
                        'face','color','size','open','start'], true)) { self::reject('Запрещённый атрибут: ' . $name); }
                if (in_array($name, ['src','data-image'], true) && (!self::path($v) || !isset($p->assets->$v))) {
                    self::reject('Изображение отсутствует в локальных ресурсах.');
                }
                if ($name === 'href' && !preg_match('~^(https?://|mailto:|tel:|#)[^\x00-\x20]*$~iD', $v)) {
                    self::reject('Небезопасная ссылка.');
                }
                if ($name === 'style' && (preg_match('/[\\\\<>@]|url|expression|behavior|binding/i', $v)
                        || preg_match('/(?:^|;)\s*[^:;]+\s*:/', $v) === false)) { self::reject('Небезопасный стиль.'); }
                if ($name === 'style') {
                    foreach (explode(';', $v) as $rule) {
                        if (trim($rule) === '') { continue; }
                        $property = strtolower(trim(explode(':', $rule, 2)[0]));
                        if (!in_array($property, ['color','background','background-color','font','font-size','font-family',
                            'font-weight','font-style','line-height','text-align','text-decoration','text-indent',
                            'text-decoration-line','white-space','vertical-align','width','max-width','min-width','height','max-height','min-height',
                            'margin','margin-left','margin-right','margin-top','margin-bottom','padding','padding-left',
                            'padding-right','padding-top','padding-bottom','border','border-left','border-right',
                            'border-top','border-bottom','border-color','border-width','border-style','border-radius',
                            'flex','flex-basis','flex-grow','flex-shrink','display','object-fit','float','clear','box-sizing','letter-spacing','word-break'], true)) {
                            self::reject('Неподдерживаемое свойство стиля: ' . $property);
                        }
                    }
                }
            }
        }
    }
    /** Verify signatures, not client MIME alone. Never accept SVG/HTML as a course asset. */
    public static function asset_type(string $bytes, string $type): string {
        if (strlen($bytes) < 4 || strlen($bytes) > 25 * 1024 * 1024) { self::reject('Ресурс должен быть до 25 МБ.'); }
        if (str_starts_with($type, 'image/')) {
            $info = @getimagesizefromstring($bytes);
            if (!$info || ($info['mime'] ?? '') !== $type || $info[0] * $info[1] > 80000000) { self::reject('Повреждённое изображение.'); }
        } else {
            $magic = substr($bytes, 0, 4);
            $valid = ['font/woff' => 'wOFF','font/woff2' => 'wOF2','font/otf' => 'OTTO','font/ttf' => "\x00\x01\x00\x00"];
            if (!isset($valid[$type]) || $magic !== $valid[$type] || strlen($bytes) > 20 * 1024 * 1024) {
                self::reject('Неподдерживаемый или повреждённый шрифт.');
            }
        }
        return $type;
    }
}
