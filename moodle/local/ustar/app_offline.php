<?php
define('NO_MOODLE_COOKIES', true);
require_once(__DIR__ . '/../../config.php');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=3600');
$home = (new moodle_url('/local/ustar/home.php'))->out();
$icon = (new moodle_url('/local/ustar/app_icon.php', ['size' => 192, 'v' => \local_ustar\mobile_app::VERSION]))->out();
?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>USTAR · Нет подключения</title>
<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f4f2ec;color:#2b2b2b;font:16px/1.6 system-ui,sans-serif}main{padding:28px;text-align:center;max-width:440px}img{width:96px;height:96px;border-radius:22px}h1{font-size:26px}a{display:inline-block;padding:12px 24px;background:#ebc500;color:#2b2b2b;border-radius:12px;text-decoration:none;font-weight:700}</style></head>
<body><main><img src="<?= $icon ?>" alt="USTAR"><h1>Нет подключения</h1><p>Для обучения и общения нужен доступ к Академии. Проверьте интернет или подключение к сети компании, затем откройте главную.</p><a href="<?= $home ?>">Открыть Академию</a></main></body></html>
