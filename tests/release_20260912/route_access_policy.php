<?php
define('MOODLE_INTERNAL', true);
class invalid_parameter_exception extends RuntimeException {}
$file = __DIR__ . '/../../moodle/local/ustar/classes/route_point_access.php';
if (!is_file($file)) { throw new RuntimeException('Missing per-point review access policy'); }
require_once($file);
use local_ustar\route_point_access as access;
function check($ok, $label) { if (!$ok) { throw new RuntimeException($label); } }
$default = access::normalize([]);
check($default === ['advance'=>'auto','repeat'=>'close','seconds'=>0,'finish'=>'next'], 'Legacy defaults');
$policy = access::normalize(['advance'=>'timed','seconds'=>3600,'repeat'=>'close','finish'=>'route']);
$window = (object)['id'=>9,'openedat'=>1000,'deadline'=>4600,'closedat'=>0];
check(access::window_state($policy, $window, 4599)['held'], 'Review remains open before deadline');
check(!access::window_state($policy, $window, 4600)['held'], 'Server deadline releases the point');
$window->closedat = 1300;
check(!access::window_state($policy, $window, 1400)['held'], 'Manual close survives timer');
$manual = access::normalize(['advance'=>'manual','repeat'=>'keep','finish'=>'stay']);
$window->closedat = 0;
check(access::window_state($manual, $window, 9000)['held'], 'Manual mode has no implicit timeout');
foreach ([['advance'=>'bad'],['advance'=>'timed','seconds'=>0],['repeat'=>'bad'],['finish'=>'external']] as $bad) {
    try { access::normalize($bad); throw new RuntimeException('Invalid policy accepted'); }
    catch (invalid_parameter_exception $e) {}
}
echo "PASS route access policy\n";
