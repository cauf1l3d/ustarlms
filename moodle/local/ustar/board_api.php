<?php
require_once(__DIR__ . '/../../config.php'); require_login(); require_sesskey(); global $USER;
require_capability('local/ustar:use',context_system::instance()); header('Content-Type: application/json; charset=utf-8');
$id=required_param('id',PARAM_INT); $version=required_param('version',PARAM_INT); $json=required_param('json',PARAM_RAW);
try { $new=\local_ustar\boards::save($id,(int)$USER->id,$json,$version); echo json_encode(['ok'=>true,'version'=>$new]); }
catch(\invalid_parameter_exception $e){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Некорректные данные доски'],JSON_UNESCAPED_UNICODE); }
catch(\dml_missing_record_exception $e){ http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Доска не найдена или недоступна'],JSON_UNESCAPED_UNICODE); }
catch(Throwable $e){ http_response_code(409); echo json_encode(['ok'=>false,'error'=>'Доска была изменена или сохранение временно недоступно'],JSON_UNESCAPED_UNICODE); }
