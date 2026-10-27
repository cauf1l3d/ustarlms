<?php
// Executes production services against real SQLite. Moodle ACL/account classification are fixture boundaries.
namespace {
define('MOODLE_INTERNAL', true);
define('SQL_PARAMS_NAMED', 2);
define('MUST_EXIST', 2);
class context_system { public static function instance(): self { return new self(); } }
class required_capability_exception extends \RuntimeException {
    public function __construct(...$args) { parent::__construct('denied'); }
}
function has_capability(...$args): bool { return $GLOBALS['allowed']; }
function fullname($user): string { return $user->firstname . ' ' . $user->lastname; }
function userdate($at, $format): string { return gmdate('Y-m-d', $at); }
final class batch_test_db {
    public \PDO $pdo;
    public int $reads = 0;
    public int $closed = 0;
    public function __construct() {
        $this->pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE local_ustar_feed_sourceitem (id INTEGER PRIMARY KEY, sourceid INTEGER, enrichstatus TEXT);
            CREATE TABLE local_ustar_competitions (id INTEGER PRIMARY KEY, title TEXT, status TEXT, audiencevalue TEXT, startat INTEGER, endat INTEGER);
            CREATE TABLE local_ustar_comp_rules (id INTEGER PRIMARY KEY, competitionid INTEGER, versionno INTEGER, rulesjson TEXT);
            CREATE TABLE local_ustar_comp_participants (id INTEGER PRIMARY KEY, competitionid INTEGER, userid INTEGER, publiclabel TEXT, status TEXT);
            CREATE TABLE local_ustar_comp_score_events (id INTEGER PRIMARY KEY, competitionid INTEGER, participantid INTEGER, points INTEGER);
            CREATE TABLE local_ustar_comp_results (id INTEGER PRIMARY KEY, competitionid INTEGER, participantid INTEGER, rankno INTEGER, points INTEGER);
            CREATE TABLE user (id INTEGER PRIMARY KEY, firstname TEXT, lastname TEXT, firstnamephonetic TEXT, lastnamephonetic TEXT,
                middlename TEXT, alternatename TEXT, email TEXT, picture INTEGER, imagealt TEXT);');
    }
    private function query($sql, $params = [], $offset = 0, $limit = 0): array {
        $this->reads++;
        $sql = preg_replace('/\{([a-z_]+)\}/', '$1', $sql);
        if ($limit) { $sql .= ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset; }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }
    public function get_records_sql($sql, $params = [], $offset = 0, $limit = 0): array {
        $result = [];
        foreach ($this->query($sql, $params, $offset, $limit) as $row) {
            $result[array_values((array)$row)[0]] = $row; // Moodle's first-selected-column key semantics.
        }
        return $result;
    }
    public function get_recordset_sql($sql, $params = []): \ArrayIterator {
        return new class($this->query($sql, $params), $this) extends \ArrayIterator {
            public function __construct(array $rows, private batch_test_db $db) { parent::__construct($rows); }
            public function close(): void { $this->db->closed++; }
        };
    }
    public function get_records_select($table, $where, $params, $sort = '', $fields = '*'): array {
        return $this->get_records_sql("SELECT $fields FROM {{$table}} WHERE $where" . ($sort ? " ORDER BY $sort" : ''), $params);
    }
    public function get_record($table, $conditions, $fields = '*', $strict = 0) {
        $where = implode(' AND ', array_map(static fn($key) => "$key = :$key", array_keys($conditions)));
        $rows = $this->query("SELECT $fields FROM {{$table}} WHERE $where", $conditions);
        if (!$rows && $strict === MUST_EXIST) { throw new \RuntimeException('missing fixture record'); }
        return $rows[0] ?? false;
    }
    public function get_field($table, $field, $conditions) {
        $row = $this->get_record($table, $conditions, $field);
        return $row ? $row->$field : false;
    }
    public function get_in_or_equal($values, $type = SQL_PARAMS_NAMED, $prefix = 'param'): array {
        if (!$values) { throw new \RuntimeException('Empty IN must not be queried'); }
        $params = [];
        foreach (array_values($values) as $i => $value) { $params[$prefix . $i] = $value; }
        return ['IN (' . implode(',', array_map(static fn($key) => ':' . $key, array_keys($params))) . ')', $params];
    }
}
}
namespace local_ustar {
class view_as { public static function assert_writable(): void {} }
class accounts { public static function participates(int $id): bool { return !in_array($id, $GLOBALS['excluded'], true); } }
class structure {
    public const NAME_STRUCTURE = 'structure';
    public static function get($name): array { return ['departments' => [['id' => 'retail', 'name' => 'Retail']]]; }
}
class ui { public static function initials($first, $last): string { return $first[0] . $last[0]; } }
}
namespace {
require __DIR__ . '/../../moodle/local/ustar/classes/feed_article_resolver.php';
require __DIR__ . '/../../moodle/local/ustar/classes/competition.php';
$DB = new batch_test_db();
$USER = (object)['id' => 99];
$allowed = true;
$excluded = [];
$case = $argv[1] ?? '';
$assert = static function($condition, $message) { if (!$condition) { throw new \RuntimeException($message); } };
if (str_starts_with($case, 'rss')) {
    $DB->pdo->exec("INSERT INTO local_ustar_feed_sourceitem VALUES
        (1,1,'pending'),(2,1,'pending'),(3,1,'done'),(4,1,'disabled'),(5,2,'retry'),(6,2,'done'),(7,9,'done')");
    $assert(method_exists(\local_ustar\feed_article_resolver::class, 'source_stats_batch'), 'RSS batch API missing');
    if ($case === 'rss_empty') {
        $rows = \local_ustar\feed_article_resolver::source_stats_batch([]);
        $assert($rows === [] && $DB->reads === 0, 'Empty source list queried the database');
    } else {
        $rows = \local_ustar\feed_article_resolver::source_stats_batch([1,2,3,1]);
        $assert($DB->reads === 1, 'RSS statistics query count grows with source count');
        $assert($DB->closed === 1, 'RSS recordset not closed');
        $assert(array_keys($rows) === [1,2,3], 'Unexpected source scope or duplicate keys');
        $assert($rows[1] === ['pending'=>2,'retry'=>0,'done'=>1,'failed'=>0,'limited'=>0,'feed'=>0,'disabled'=>1], 'Lost one of the statuses of source1');
        $assert($rows[2] === ['pending'=>0,'retry'=>1,'done'=>1,'failed'=>0,'limited'=>0,'feed'=>0], 'Source statistics mixed across source IDs');
        $assert($rows[3] === ['pending'=>0,'retry'=>0,'done'=>0,'failed'=>0,'limited'=>0,'feed'=>0], 'Empty source not initialized');
        foreach ([1,2,3] as $id) {
            $assert($rows[$id] === \local_ustar\feed_article_resolver::source_stats($id), 'Single-source API changed its result');
        }
    }
} else {
    $status = str_starts_with($case, 'closed') ? 'closed' : 'published';
    $stmt = $DB->pdo->prepare('INSERT INTO local_ustar_competitions VALUES (1,?,?,?,?,?)');
    $stmt->execute(['Fixture',$status,'retail',100,200]);
    $stmt = $DB->pdo->prepare('INSERT INTO local_ustar_comp_rules VALUES (1,1,1,?)');
    $stmt->execute([json_encode(['events'=>['game_mastery'=>['pointsperxp'=>1]],'uscoin'=>['enabled'=>false]])]);
    $count = $case === 'closed_limit' ? 105 : ($case === 'empty' ? 0 : 5);
    for ($i=1; $i<=$count; $i++) {
        $DB->pdo->exec("INSERT INTO user VALUES ($i,'Person$i','Seller','','','','','fixture@example.invalid',0,'');
            INSERT INTO local_ustar_comp_participants VALUES ($i,1,$i,'Private label$i','active');
            INSERT INTO local_ustar_comp_score_events VALUES ($i,1,$i,10);
            INSERT INTO local_ustar_comp_results VALUES ($i,1,$i,1,10);");
    }
    if ($case === 'closed_missing') { $DB->pdo->exec('DELETE FROM user WHERE id=5'); }
    if ($case === 'open_filtered') { $excluded = [5]; }
    if ($case === 'denied') { $allowed = false; }
    try { $result = \local_ustar\competition::operator_season(1,99); }
    catch (\required_capability_exception $error) {
        $assert($case === 'denied' && $DB->reads === 0, 'Operator guard queried or exposed season data');
        echo json_encode(['case'=>$case,'status'=>'passed','queries'=>$DB->reads]) . "\n"; exit;
    }
    $assert($case !== 'denied', 'Unauthorized operator accepted');
    $scores = $result['scores'];
    $expected = $case === 'open_filtered' ? 4 : min($count,100);
    $assert(count($scores) === $expected, 'Changed scoreboard scope/limit');
    $assert($DB->reads <= 4, 'Duplicate participant/user lookups still grow with participant count: ' . $DB->reads);
    foreach ($scores as $i => $row) {
        $assert(is_array($row) === ($status !== 'closed'), 'Changed score row type');
        $row = (array)$row;
        $name = $case === 'closed_missing' && $i===4 ? 'Удалённая учётная запись' : 'Person'.($i+1).' Seller';
        $assert($row['displayname'] === $name && (int)$row['rank'] === 1 && (int)$row['points'] === 10, 'Changed name/rank/points or order');
        if ($status !== 'closed') { $assert(!array_key_exists('userid',$row), 'Exposed private userid in array scoreboard'); }
    }
}
echo json_encode(['case'=>$case,'status'=>'passed','queries'=>$DB->reads]) . "\n";
}
