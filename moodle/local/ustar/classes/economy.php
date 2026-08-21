<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Internal USTAR gamification economy. USCOIN is not money/crypto. */
final class economy {
    public static function available(): bool {
        global $DB;
        return $DB->get_manager()->table_exists(new \xmldb_table('local_ustar_coin_ledger'));
    }

    public static function post(int $userid, int $amount, string $type, string $idempotencykey,
            string $sourcekind = '', string $sourceid = '', string $comment = '', ?int $actorid = null): bool {
        global $DB, $USER;
        if (!self::available() || $userid <= 0 || $amount === 0 || trim($idempotencykey) === '') {
            return false;
        }
        $idempotencykey = substr($idempotencykey, 0, 128);
        if ($DB->record_exists('local_ustar_coin_ledger', ['idempotencykey' => $idempotencykey])) {
            return false;
        }
        try {
            $DB->insert_record('local_ustar_coin_ledger', (object)[
                'userid' => $userid,
                'amount' => $amount,
                'txtype' => clean_param($type, PARAM_ALPHANUMEXT),
                'sourcekind' => clean_param($sourcekind, PARAM_ALPHANUMEXT),
                'sourceid' => clean_param($sourceid, PARAM_TEXT),
                'idempotencykey' => $idempotencykey,
                'comment' => $comment,
                'actorid' => $actorid ?? ((int)($USER->id ?? 0) ?: null),
                'timecreated' => time(),
            ]);
            return true;
        } catch (\dml_write_exception $e) {
            // A concurrent delivery of the same event may win the unique-key race.
            if ($DB->record_exists('local_ustar_coin_ledger', ['idempotencykey' => $idempotencykey])) {
                return false;
            }
            throw $e;
        }
    }

    public static function balance(int $userid): int {
        global $DB;
        if (!self::available()) return 0;
        return (int)$DB->get_field_sql(
            'SELECT COALESCE(SUM(amount),0) FROM {local_ustar_coin_ledger} WHERE userid = :uid', ['uid' => $userid]
        );
    }

    public static function totals(int $userid): array {
        global $DB;
        if (!self::available()) return ['balance'=>0,'earned'=>0,'spent'=>0];
        $r = $DB->get_record_sql(
            'SELECT COALESCE(SUM(amount),0) balance, COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END),0) earned, '
            . 'COALESCE(SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END),0) spent '
            . 'FROM {local_ustar_coin_ledger} WHERE userid = :uid', ['uid'=>$userid]
        );
        return ['balance'=>(int)$r->balance,'earned'=>(int)$r->earned,'spent'=>(int)$r->spent];
    }

    public static function history(int $userid, int $limit = 20): array {
        global $DB;
        if (!self::available()) return [];
        $rows=[];
        foreach ($DB->get_records('local_ustar_coin_ledger',['userid'=>$userid],'timecreated DESC','*',0,$limit) as $r) {
            $rows[] = [
                'amount'=>(int)$r->amount,
                'positive'=>(int)$r->amount > 0,
                'type'=>(string)$r->txtype,
                'comment'=>(string)$r->comment,
                'date'=>userdate((int)$r->timecreated,'%d.%m.%Y %H:%M'),
            ];
        }
        return $rows;
    }

    /** Deterministic engagement score. Does not use spendable coin balance. */
    public static function leaderboard(int $viewerid, int $limit = 50, bool $monthly = false): array {
        global $DB;
        $since = $monthly ? strtotime('first day of this month 00:00:00') : 0;
        $users = $DB->get_records_select('user','deleted = 0 AND suspended = 0 AND id > 1', [], '', 'id,firstname,lastname');
        $positionmap = people::position_map(structure::get(structure::NAME_STRUCTURE));
        $rows=[];
        foreach ($users as $u) {
            if (!accounts::participates((int)$u->id)) continue;
            $where = $since ? ' AND timemodified >= :since' : '';
            $cm = (int)$DB->get_field_sql(
                'SELECT COUNT(1) FROM {course_modules_completion} WHERE userid=:uid AND completionstate IN (1,2)'.$where,
                ['uid'=>(int)$u->id] + ($since ? ['since'=>$since] : [])
            );
            $coursewhere = $since ? ' AND timecompleted >= :since' : '';
            $courses = (int)$DB->get_field_sql(
                'SELECT COUNT(1) FROM {course_completions} WHERE userid=:uid AND timecompleted IS NOT NULL'.$coursewhere,
                ['uid'=>(int)$u->id] + ($since ? ['since'=>$since] : [])
            );
            $game = 0;
            if ($DB->get_manager()->table_exists(new \xmldb_table('local_ustar_game_mastery'))) {
                $gamewhere = $since ? ' AND timecreated >= :since' : '';
                $game=(int)$DB->get_field_sql('SELECT COALESCE(SUM(xpearned),0) FROM {local_ustar_game_mastery} WHERE userid=:uid'.$gamewhere,
                    ['uid'=>(int)$u->id] + ($since ? ['since'=>$since] : []));
            }
            $xp = $courses*100 + $cm*10 + $game;
            $pid=people::position_id((int)$u->id);
            $coin=self::totals((int)$u->id);
            $rows[]=[
                'userid'=>(int)$u->id,
                'fullname'=>fullname($u),
                'position'=>(string)($positionmap[$pid]['name'] ?? 'Без должности'),
                'xp'=>$xp,
                'coin'=>$coin['balance'],
                'earned'=>$coin['earned'],
                'current'=>(int)$u->id === $viewerid,
            ];
        }
        usort($rows, fn($a,$b)=>($b['xp'] <=> $a['xp']) ?: strcasecmp($a['fullname'],$b['fullname']));
        $current=null; $out=[];
        foreach ($rows as $i=>&$r) {
            $r['rank']=$i+1;
            $r['initials']=ui::initials(...self::split_name($r['fullname']));
            if ($r['current']) $current=$r;
            if ($i < $limit) $out[]=$r;
        }
        unset($r);
        return ['rows'=>$out,'current'=>$current,'total'=>count($rows)];
    }

    private static function split_name(string $name): array {
        $p=preg_split('/\s+/u',trim($name),-1,PREG_SPLIT_NO_EMPTY) ?: [];
        return [(string)($p[0]??''),(string)($p[count($p)-1]??'')];
    }
}
