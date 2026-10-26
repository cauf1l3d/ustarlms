<?php
// Local authoring API. Moodle session + existing author capability on every request.
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    if (!isloggedin() || isguestuser()) { http_response_code(401); echo json_encode(['error'=>'Войдите в Академию.']); exit; }
    $actor=(int)$USER->id;
    \local_ustar\course_studio::require_author($actor);
    $route=optional_param('route','session',PARAM_RAW_TRIMMED);
    $method=$_SERVER['REQUEST_METHOD'];$write=$method!=='GET';
    $requestkey=$_SERVER['HTTP_X_MOODLE_SESSKEY'] ?? '';
    // Moodle treats an empty argument as a request-param lookup, which can throw.
    if ($write && (!is_string($requestkey) || empty($requestkey) || !confirm_sesskey($requestkey))) {
        http_response_code(403); echo json_encode(['code'=>'session','error'=>'Сессия истекла. Обновите страницу.']); exit;
    }
    if ($write) { \local_ustar\course_studio::require_author($actor,true); }
    $owner=$_SERVER['HTTP_X_RC_OWNER'] ?? '';
    if ($owner!=='' && !hash_equals((string)$actor,$owner)) {
        http_response_code(409); echo json_encode(['code'=>'account_changed','error'=>'Аккаунт Академии изменился. Перезагрузите страницу.']); exit;
    }
    \core\session\manager::write_close();
    if ($method==='GET' && $route==='session') {
        $result=['authenticated'=>true,'userId'=>(string)$actor];
    } else if ($method==='GET' && $route==='courses') {
        $result=['courses'=>\local_ustar\course_studio::listing($actor)];
    } else if (preg_match('~^courses/([a-zA-Z0-9_-]{1,60})$~D',$route,$match)) {
        if ($method==='GET') { $result=\local_ustar\course_studio::get($match[1],$actor); }
        else if ($method==='POST') {
            $raw=file_get_contents('php://input',false,null,0,5*1024*1024+1);
            if (strlen($raw)>5*1024*1024) { throw new invalid_parameter_exception('Проект превышает 5 МБ без ресурсов.'); }
            $input=json_decode($raw,false,64,JSON_THROW_ON_ERROR);
            if (($input->course->data->code ?? '')!==$match[1]) { throw new invalid_parameter_exception('Код проекта не совпал.'); }
            $result=\local_ustar\course_studio::save($input->course,(int)($input->baseRevision ?? -1),
                (int)($input->contentmodified ?? 0),(string)($input->mutationId ?? ''),$actor);
        } else { throw new invalid_parameter_exception('Неподдерживаемый метод.'); }
    } else if (preg_match('~^assets/([a-f0-9]{64})$~D',$route,$match)) {
        if ($method==='GET') {
            $file=\local_ustar\course_studio::asset($match[1],$actor);
            if (!$file) { http_response_code(404); echo json_encode(['error'=>'Ресурс не найден.']);exit; }
            header('Content-Type: application/octet-stream');
            header("Content-Security-Policy: sandbox; default-src 'none'");
            send_stored_file($file,0,0,true);exit;
        } else if ($method==='POST') {
            $bytes=file_get_contents('php://input',false,null,0,25*1024*1024+1);
            $result=\local_ustar\course_studio::put_asset($match[1],$bytes,strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE'] ?? '')[0])),$actor);
        } else { throw new invalid_parameter_exception('Неподдерживаемый метод.'); }
    } else if ($method==='POST' && $route==='prepare') {
        $input=json_decode(file_get_contents('php://input',false,null,0,8192),false,16,JSON_THROW_ON_ERROR);
        $result=\local_ustar\course_studio::prepare((string)($input->code ?? ''),(int)($input->revision ?? 0),
            (int)($input->contentmodified ?? 0),$actor);
    } else { throw new invalid_parameter_exception('Неизвестная команда студии.'); }
    echo \local_ustar\course_studio_project::encode($result);
} catch (\local_ustar\course_studio_conflict $e) {
    http_response_code(409); echo json_encode(['code'=>'conflict','error'=>$e->getMessage()]);
} catch (required_capability_exception $e) {
    http_response_code(403); echo json_encode(['code'=>'forbidden','error'=>'Нет доступа к студии.']);
} catch (\Throwable $e) {
    // Log locations only: exception messages/arguments can contain SQL or user content.
    if (!($e instanceof invalid_parameter_exception) && !($e instanceof \JsonException)) {
        $locations = array_map(static function(array $frame): string {
            return basename($frame['file'] ?? '') . ':' . ($frame['line'] ?? 0)
                . ' ' . ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
        }, $e->getTrace());
        error_log('USTAR_STUDIO_FAILURE ' . get_class($e) . ' '
            . ($e instanceof moodle_exception ? $e->errorcode : '') . ' '
            . basename($e->getFile()) . ':' . $e->getLine() . ' ' . implode(' <- ', $locations));
    }
    http_response_code($e instanceof invalid_parameter_exception || $e instanceof \JsonException ? 400 : 500);
    // Do not expose stack traces, SQL or private filesystem paths in API responses.
    $message=$e instanceof invalid_parameter_exception ? ($e->debuginfo ?: 'Некорректные данные проекта.') : 'Операция не выполнена. Сохраните проект и повторите запрос.';
    echo json_encode(['error'=>$message],JSON_UNESCAPED_UNICODE);
}
