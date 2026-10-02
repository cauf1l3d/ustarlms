<?php
require_once(__DIR__.'/../../config.php');
require_login();require_capability('local/ustar:use',context_system::instance());
if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405);exit; }
try {
    require_sesskey();
    $json=required_param('layout',PARAM_RAW);
    if (strlen($json)>10000) { throw new invalid_parameter_exception('Настройки слишком велики.'); }
    $items=json_decode($json,true);
    if (!is_array($items)) { throw new invalid_parameter_exception('Некорректные настройки.'); }
    $data=\local_ustar\home_layout::save((int)$USER->id,required_param('revision',PARAM_INT),$items);
    $result=['ok'=>true,'revision'=>$data['revision']];
} catch (Throwable $e) { http_response_code(400);$result=['ok'=>false,'error'=>$e->getMessage()]; }
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
echo json_encode($result,JSON_UNESCAPED_UNICODE);
