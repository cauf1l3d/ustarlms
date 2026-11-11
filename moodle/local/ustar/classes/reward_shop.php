<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Employee reward redemptions using the canonical USCOIN ledger. */
final class reward_shop {
    public const OFFER = 'local_ustar_reward_shop_offer';
    public const ORDER = 'local_ustar_reward_shop_order';
    public const LOCK = 'reward-shop';
    private const SETTING = 'reward_shop_settings';

    public static function departments(): array {
        return array_column(structure::get(structure::NAME_STRUCTURE)['departments'] ?? [], 'name', 'id');
    }

    public static function settings(): array {
        global $DB;
        $raw = $DB->get_field('config_plugins', 'value', ['plugin'=>'local_ustar', 'name'=>self::SETTING]);
        $saved = json_decode((string)$raw, true);
        $departments = $saved['departments'] ?? [];
        $known = self::departments();
        return [
            'enabled' => ($saved['enabled'] ?? false) === true,
            'departments' => is_array($departments) && array_is_list($departments)
                ? array_values(array_filter($departments, static fn($id) => is_string($id) && isset($known[$id])))
                : [],
            'revision' => max(0, (int)($saved['revision'] ?? 0)),
        ];
    }

    private static function real_actor(int $userid): bool {
        global $USER;
        return $userid > 0 && (int)($USER->id ?? 0) === $userid && isloggedin() && !isguestuser()
            && !\core\session\manager::is_loggedinas() && !view_as::active();
    }

    private static function employee(int $userid): bool {
        return accounts::is_business_account($userid) && capabilities::has($userid, capabilities::LEARNING_USE);
    }

    public static function can_manage(int $userid): bool {
        return self::real_actor($userid) && (is_siteadmin($userid)
            || (self::employee($userid) && has_capability('local/ustar:admin', \context_system::instance(), $userid, false)));
    }

    public static function can_shop(int $userid): bool {
        if (!self::real_actor($userid) || !self::employee($userid)) { return false; }
        $settings = self::settings();
        return $settings['enabled'] && in_array(
            organization_identity::resolve($userid)['departmentid'], $settings['departments'], true,
        );
    }

    public static function can_view_history(int $userid): bool {
        return self::real_actor($userid) && self::employee($userid);
    }

    private static function fail(string $code): never {
        throw new \moodle_exception('rewardshop_'.$code, 'local_ustar');
    }

    private static function require_manager(int $userid): void {
        if (!self::can_manage($userid)) { self::fail('denied'); }
    }

    /** Shop writes serialize through commit. Wallet locking remains inside economy. */
    private static function mutate(callable $operation): mixed {
        global $DB;
        if ($DB->is_transaction_started()) {
            throw new \coding_exception('Reward shop must own its transaction');
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')->get_lock(self::LOCK, 10);
        if (!$lock) { self::fail('busy'); }
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $result = $operation();
                $transaction->allow_commit();
                return $result;
            } catch (\Throwable $error) {
                $transaction->rollback($error);
            }
        } finally {
            $lock->release();
        }
    }

    public static function configure(int $actor, bool $enabled, array $departments, int $revision): void {
        self::require_manager($actor);
        self::mutate(static function() use ($actor, $enabled, $departments, $revision): void {
            self::require_manager($actor);
            if (self::settings()['revision'] !== $revision) { self::fail('stale'); }
            $known = self::departments();
            foreach ($departments as $id) {
                if (!is_string($id) || !isset($known[$id])) { self::fail('invalid'); }
            }
            $ids = array_values(array_unique($departments));
            if ($enabled && !$ids) { self::fail('invalid'); }
            set_config(self::SETTING, json_encode([
                'enabled'=>$enabled, 'departments'=>$ids, 'revision'=>$revision + 1,
            ], JSON_THROW_ON_ERROR), 'local_ustar');
        });
    }

    public static function save_offer(int $actor, int $id, int $revision, string $title,
            string $description, int $price, int $stock, string $status): \stdClass {
        self::require_manager($actor);
        $title = trim($title);
        $description = trim($description);
        if ($id < 0 || $title === '' || \core_text::strlen($title) > 120 || \core_text::strlen($description) > 1500
                || $price < 1 || $price > 1000000 || $stock < 0 || $stock > 1000000
                || !in_array($status, ['draft', 'published', 'archived'], true)) {
            self::fail('invalid');
        }
        return self::mutate(static function() use ($actor, $id, $revision, $title, $description, $price, $stock, $status) {
            global $DB;
            self::require_manager($actor);
            $old = $id ? $DB->get_record(self::OFFER, ['id'=>$id]) : false;
            if (($id && !$old) || (int)($old->revision ?? 0) !== $revision) { self::fail('stale'); }
            $now = time();
            $record = (object)[
                'title'=>$title, 'description'=>$description, 'price'=>$price, 'stock'=>$stock,
                'status'=>$status, 'revision'=>$revision + 1, 'timecreated'=>$old->timecreated ?? $now,
                'timemodified'=>$now, 'usermodified'=>$actor,
            ];
            if ($id) {
                $record->id = $id;
                $DB->update_record(self::OFFER, $record);
            } else {
                $record->id = $DB->insert_record(self::OFFER, $record);
            }
            return $record;
        });
    }

    public static function request(int $userid, int $offerid, int $revision, int $price, string $nonce): \stdClass {
        if (!self::can_view_history($userid)) { self::fail('unavailable'); }
        if ($offerid <= 0 || $revision <= 0 || $price <= 0 || !preg_match('/\A[0-9a-f]{32}\z/', $nonce)) {
            self::fail('invalid');
        }
        $requestkey = hash('sha256', $userid.':'.$nonce);
        return self::mutate(static function() use ($userid, $offerid, $revision, $price, $requestkey) {
            global $DB;
            if (!self::can_view_history($userid)) { self::fail('unavailable'); }
            $previous = $DB->get_record(self::ORDER, ['requestkey'=>$requestkey, 'userid'=>$userid]);
            if ($previous) {
                if ((int)$previous->offerid !== $offerid || (int)$previous->offerrevision !== $revision
                        || (int)$previous->price !== $price) { self::fail('invalid'); }
                return $previous;
            }
            if (!self::can_shop($userid) || !economy::available()) { self::fail('unavailable'); }
            $offer = $DB->get_record(self::OFFER, ['id'=>$offerid, 'status'=>'published']);
            if (!$offer || (int)$offer->revision !== $revision || (int)$offer->price !== $price) {
                self::fail('stale');
            }
            if ((int)$offer->stock < 1) { self::fail('outofstock'); }
            $balance = economy::balance($userid);
            if ($balance < $price) { self::fail('insufficient'); }

            $now = time();
            $orderid = $DB->insert_record(self::ORDER, (object)[
                'offerid'=>$offerid, 'userid'=>$userid, 'title'=>$offer->title, 'price'=>$price,
                'offerrevision'=>$revision, 'requestkey'=>$requestkey, 'status'=>'pending',
                'timecreated'=>$now,
            ]);
            $ledgerkey = 'reward-shop-order:'.$orderid;
            if (!economy::spend($userid, $price, 'reward_shop', $ledgerkey, 'reward_shop_order',
                    (string)$orderid, 'Магазин наград: '.$offer->title, $userid, $balance)) {
                self::fail('stale');
            }
            $ledgerid = $DB->get_field('local_ustar_coin_ledger', 'id', ['idempotencykey'=>$ledgerkey], MUST_EXIST);
            $DB->set_field(self::ORDER, 'ledgerid', $ledgerid, ['id'=>$orderid]);
            $offer->stock = (int)$offer->stock - 1;
            $offer->revision = $revision + 1;
            $offer->timemodified = $now;
            $DB->update_record(self::OFFER, $offer);
            return $DB->get_record(self::ORDER, ['id'=>$orderid], '*', MUST_EXIST);
        });
    }

    public static function resolve(int $actor, int $orderid, string $status, string $reason = ''): \stdClass {
        self::require_manager($actor);
        $reason = trim($reason);
        if (!in_array($status, ['issued', 'rejected'], true) || ($status === 'rejected' && $reason === '')
                || \core_text::strlen($reason) > 240) { self::fail('invalid'); }
        return self::mutate(static function() use ($actor, $orderid, $status, $reason) {
            global $DB;
            self::require_manager($actor);
            $order = $DB->get_record(self::ORDER, ['id'=>$orderid]);
            if (!$order) { self::fail('invalid'); }
            if ($order->status === $status) { return $order; }
            if ($order->status !== 'pending') { self::fail('closedorder'); }
            if ($status === 'issued') {
                if (!self::employee((int)$order->userid)) { self::fail('inactive'); }
            } else {
                self::refund($order, $actor, 'Магазин наград: заявка отклонена');
            }
            $order->status = $status;
            $order->reason = $reason;
            $order->resolvedby = $actor;
            $order->resolvedat = time();
            $DB->update_record(self::ORDER, $order);
            return $order;
        });
    }

    /** INTERNAL: Moodle Privacy holds the shop lock and owns the transaction. */
    public static function erase_in_transaction(?array $userids): void {
        global $DB;
        if (!$DB->is_transaction_started()) {
            throw new \coding_exception('Reward shop erasure must run inside a transaction');
        }
        if ($userids === []) { return; }
        [$matches, $params] = $userids === null
            ? ['> 0', []] : $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $orders = $DB->get_records_select(self::ORDER, 'userid '.$matches, $params, 'id ASC');
        foreach ($orders as $order) {
            if ($order->status === 'pending') {
                self::refund($order, (int)get_admin()->id, 'Магазин наград: заявка закрыта при удалении профиля');
                $order->status = 'rejected';
                $order->resolvedat = time();
            }
            $order->userid = 0;
            $order->resolvedby = 0;
            $order->reason = null;
            $order->ledgerid = 0;
            $order->requestkey = hash('sha256', 'erased-reward-shop-order:'.$order->id);
            $DB->update_record(self::ORDER, $order);
        }
        $DB->set_field_select(self::ORDER, 'reason', null, 'resolvedby '.$matches, $params);
        $DB->set_field_select(self::ORDER, 'resolvedby', 0, 'resolvedby '.$matches, $params);
        $DB->set_field_select(self::OFFER, 'usermodified', 0, 'usermodified '.$matches, $params);
    }

    /** Caller holds the shop lock and owns the surrounding transaction. */
    private static function refund(\stdClass $order, int $actor, string $comment): void {
        global $DB;
        $ledger = $DB->get_record('local_ustar_coin_ledger', [
            'id'=>$order->ledgerid, 'userid'=>$order->userid, 'txtype'=>'reward_shop',
            'sourcekind'=>'reward_shop_order', 'sourceid'=>(string)$order->id,
        ]);
        if (!$ledger || (int)$ledger->amount !== -(int)$order->price
                || !economy::reverse((int)$ledger->id, 'reward-shop-refund:'.$order->id, $comment, $actor)) {
            self::fail('inconsistent');
        }
        $offer = $DB->get_record(self::OFFER, ['id'=>$order->offerid], '*', MUST_EXIST);
        $offer->stock = (int)$offer->stock + 1;
        $offer->revision = (int)$offer->revision + 1;
        $offer->timemodified = time();
        $DB->update_record(self::OFFER, $offer);
    }
}
