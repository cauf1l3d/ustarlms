<?php
require_once(__DIR__ . '/../../config.php');
require_login();
\local_ustar\view_as::assert_writable();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
require_sesskey();
require_capability('local/ustar:use', context_system::instance());
header('Content-Type: application/json; charset=utf-8');
$preset=required_param('preset',PARAM_ALPHANUMEXT);
$allowed=['yellow','graphite','ocean','forest','berry','sand','lavender','mint','coral','indigo','rose','ice'];
if (!in_array($preset,$allowed,true)) throw new invalid_parameter_exception('Неизвестное оформление');
set_user_preference('local_ustar_preset',$preset,$USER->id);
echo json_encode(['ok'=>true,'preset'=>$preset]);
