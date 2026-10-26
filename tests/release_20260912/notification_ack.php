<?php
// Standalone interleaving fixture: real production PHP and SQLite SQL, not native Moodle/PG evidence.
define('MOODLE_INTERNAL', true);
define('MUST_EXIST', 2);
class xmldb_table { public function __construct(public string $name) {} }
final class ack_test_db {
    public PDO $pdo;
    public $afterread = null;
    public function __construct() {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE local_ustar_notifications
            (id INTEGER PRIMARY KEY, userid INTEGER, status TEXT, ackat INTEGER,
             timemodified INTEGER, subject TEXT, message TEXT)');
        $this->pdo->exec("INSERT INTO local_ustar_notifications VALUES (1,7,'unread',NULL,100,'Original','Unchanged')");
    }
    public function get_manager(): self { return $this; }
    public function table_exists($table): bool { return true; }
    public function get_record($table, $where, $fields = '*', $strict = 0) {
        $clauses = []; $params = [];
        foreach ($where as $key => $value) { $clauses[] = "$key = ?"; $params[] = $value; }
        $stmt = $this->pdo->prepare("SELECT $fields FROM $table WHERE " . implode(' AND ', $clauses));
        $stmt->execute($params); $record = $stmt->fetch(PDO::FETCH_OBJ);
        if (!$record && $strict === MUST_EXIST) { throw new RuntimeException('missing record'); }
        if ($record && $this->afterread) {
            $hook = $this->afterread; $this->afterread = null; $hook($this->pdo);
        }
        return $record;
    }
    public function update_record($table, $record): void {
        $values = (array)$record; $id = $values['id']; unset($values['id']);
        $parts = array_map(static fn($k) => "$k = :$k", array_keys($values));
        $this->execute("UPDATE $table SET " . implode(',', $parts) . ' WHERE id = :id', $values + ['id' => $id]);
    }
    public function execute($sql, $params = []): void {
        $sql = preg_replace('/\{([a-z_]+)\}/', '$1', $sql);
        $this->pdo->prepare($sql)->execute($params);
    }
    public function set_field_select($table, $field, $value, $where, $params): void {
        $this->execute("UPDATE $table SET $field = :newvalue WHERE $where", ['newvalue' => $value] + $params);
    }
}
// Bulk core read is external to this local notification interleaving fixture.
eval('namespace core_message; class api { public static function mark_all_notifications_as_read($id) {} }');
require __DIR__ . '/../../moodle/local/ustar/classes/target_core.php';
require __DIR__ . '/../../moodle/local/ustar/classes/communication.php';
$DB = new ack_test_db();
$case = $argv[1] ?? '';
$assert = static function($condition, $reason) { if (!$condition) { throw new RuntimeException($reason); } };
if ($case === 'stale_read' || $case === 'stale_ack') {
    // The first request has selected unread. Another committed ACK arrives before its write.
    $DB->afterread = static fn($pdo) => $pdo->exec("UPDATE local_ustar_notifications
        SET status='acknowledged', ackat=1234, timemodified=1234 WHERE id=1");
    if ($case === 'stale_read') { \local_ustar\communication::mark_notification(7, 1); }
    else { \local_ustar\target_core::acknowledge_notification(1, 7); }
    $row = $DB->get_record('local_ustar_notifications', ['id' => 1]);
    $assert($row->status === 'acknowledged', 'Concurrent read erased acknowledgement');
    $assert((int)$row->ackat === 1234, 'Concurrent request replaced the first acknowledgement time');
} else if ($case === 'repeat') {
    \local_ustar\target_core::acknowledge_notification(1, 7);
    $first = $DB->get_record('local_ustar_notifications', ['id' => 1]);
    \local_ustar\communication::mark_notification(7, 1);
    \local_ustar\target_core::acknowledge_notification(1, 7);
    $row = $DB->get_record('local_ustar_notifications', ['id' => 1]);
    $assert($row->ackat === $first->ackat && $row->status === 'acknowledged', 'Repeated acknowledgement changed receipt');
} else if ($case === 'all_read') {
    $DB->execute("INSERT INTO local_ustar_notifications VALUES (2,7,'acknowledged',1234,1234,'Other','Unchanged')");
    \local_ustar\communication::mark_all_notifications(7);
    $row = $DB->get_record('local_ustar_notifications', ['id' => 1]);
    $assert($row->status === 'read' && $row->ackat === null, 'Bulk read invented acknowledgement');
    $row = $DB->get_record('local_ustar_notifications', ['id' => 2]);
    $assert($row->status === 'acknowledged' && (int)$row->ackat === 1234, 'Bulk read changed receipt');
} else { throw new RuntimeException('Unknown fixture case'); }
$row = $DB->get_record('local_ustar_notifications', ['id' => 1]);
$assert($row->subject === 'Original' && $row->message === 'Unchanged', 'Acknowledgement changed content');
echo json_encode(['case' => $case, 'status' => 'passed']) . "\n";
