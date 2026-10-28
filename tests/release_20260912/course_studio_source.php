<?php
// Pure source adapter: text/media preservation and inert imported HTML, no Moodle/DB calls.
define('MOODLE_INTERNAL', true);
class invalid_parameter_exception extends Exception {}
require __DIR__ . '/../../moodle/local/ustar/classes/course_studio_project.php';
$file = __DIR__ . '/../../moodle/local/ustar/classes/course_studio_source.php';
if (is_file($file)) { require $file; }
if (!class_exists('\local_ustar\course_studio_source')) { throw new Exception('Editable material source codec is required'); }
function verify(bool $condition, string $reason): void { if (!$condition) { throw new Exception($reason); } }
function refused(callable $call): void { try { $call(); } catch (invalid_parameter_exception $e) { return; } throw new Exception('Unsafe source accepted'); }
$asset = static function(string $src): array {
    if ($src !== 'data:image/png;base64,fixture') { throw new invalid_parameter_exception('Источник изображения недоступен'); }
    return ['path' => 'assets/imported.png', 'asset' => (object)['type'=>'image/png','hash'=>hash('sha256',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jZLkAAAAASUVORK5CYII=')),'size'=>strlen(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jZLkAAAAASUVORK5CYII='))]];
};
$pages = [['title'=>'Первый экран','body'=>'<p onclick="bad()" style="color:#282727;position:fixed">Текст <strong>курса</strong></p><script>window.bad=1</script><a href="javascript:alert(1)">Ссылка</a>',
    'image'=>'data:image/png;base64,fixture'], ['title'=>'Таблица','body'=>'<table><tr><td>Товар</td><td>Цена</td></tr></table>']];
$p = \local_ustar\course_studio_source::from_pages('Обучение', 'MATERIAL-7', $pages, $asset);
verify($p->data->title === 'Обучение' && count($p->data->pages) === 2, 'screens/title lost');
verify(str_contains($p->data->pages[0]->html, '<strong>курса</strong>'), 'rich text lost');
verify(!preg_match('/onclick|javascript:|window.bad|position:/', $p->data->pages[0]->html), 'active markup survived');
verify(str_contains($p->data->pages[0]->html, 'assets/imported.png') && isset($p->assets->{'assets/imported.png'}), 'image lost');
verify(str_contains($p->data->pages[1]->html, '<table>') && str_contains($p->data->pages[1]->html, 'Цена'), 'table lost');
\local_ustar\course_studio_project::validate($p);
refused(fn()=>\local_ustar\course_studio_source::from_pages('x','X',array_fill(0,501,['title'=>'x','body'=>'x']),$asset));
refused(fn()=>\local_ustar\course_studio_source::from_pages('x','X',[['title'=>'x','body'=>'<img src="https://external.example/private.png">']],$asset));
refused(fn()=>\local_ustar\course_studio_source::from_pages('x','X',[['title'=>'x','body'=>'<script>private()</script>']],$asset));
if (getenv('USTAR_SOURCE_JSON') === '1') { echo json_encode(['course'=>$p,'fingerprint'=>\local_ustar\course_studio_project::fingerprint($p)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return; }
echo "COURSE_STUDIO_SOURCE_OK rich text tables images inert HTML bounded unsupported refusal\n";
