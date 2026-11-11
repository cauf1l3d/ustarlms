<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(reward_shop::class)]
final class reward_shop_test extends \advanced_testcase {
    private int $admin;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->preventResetByRollback();
        $this->setAdminUser();
        $this->admin = (int)get_admin()->id;
        accounts::ensure_profile_field();
        global $DB;
        if (!$DB->record_exists('user_info_field', ['shortname'=>'ustar_position'])) {
            $this->getDataGenerator()->create_custom_profile_field(
                ['shortname'=>'ustar_position', 'name'=>'Position', 'datatype'=>'text'],
            );
        }
        $tree = structure::get(structure::NAME_STRUCTURE);
        foreach (['shop_sales', 'shop_warehouse'] as $department) {
            $tree['departments'][] = ['id' => $department, 'name' => $department];
            $tree['positions'][] = ['id' => $department.'_job', 'department' => $department,
                'name' => $department.' fixture', 'level' => 1];
        }
        structure::save(structure::NAME_STRUCTURE, $tree);
    }

    private function employee(string $department = 'shop_sales', int $coins = 20): \stdClass {
        $employee = $this->getDataGenerator()->create_user();
        people::set_position_id($employee->id, $department.'_job');
        if ($coins) {
            economy::post($employee->id, $coins, 'fixture', 'fixture-shop-'.$employee->id, '', '', '', $this->admin);
        }
        return $employee;
    }

    private function open(): void {
        reward_shop::configure($this->admin, true, ['shop_sales'], 0);
    }

    private function offer(int $stock = 2): \stdClass {
        return reward_shop::save_offer($this->admin, 0, 0, 'Термокружка', 'Выдача в офисе', 7, $stock, 'published');
    }

    private function request(\stdClass $employee, \stdClass $offer, ?string $nonce = null): \stdClass {
        return reward_shop::request($employee->id, $offer->id, $offer->revision, $offer->price,
            $nonce ?? bin2hex(random_bytes(16)));
    }

    private function denied(callable $action, string $error): void {
        try {
            $action();
            $this->fail('Expected '.$error);
        } catch (\moodle_exception $failure) {
            $this->assertSame($error, $failure->errorcode);
        }
    }

    public function test_store_is_closed_by_default_and_uses_current_department(): void {
        $offer = $this->offer();
        $employee = $this->employee();
        $this->setUser($employee);
        $this->assertFalse(reward_shop::can_shop($employee->id));
        $this->denied(fn() => $this->request($employee, $offer), 'rewardshop_unavailable');

        $this->setAdminUser();
        $this->open();
        $this->setUser($employee);
        $this->assertTrue(reward_shop::can_shop($employee->id));
        people::set_position_id($employee->id, 'shop_warehouse_job');
        $this->assertFalse(reward_shop::can_shop($employee->id));
        $this->denied(fn() => $this->request($employee, $offer), 'rewardshop_unavailable');
    }

    public function test_retry_reserves_once_and_cannot_overwrite_stock_or_price(): void {
        global $DB;
        $this->open();
        $offer = $this->offer(1);
        $employee = $this->employee();
        $this->setUser($employee);
        $nonce = str_repeat('a', 32);
        $order = $this->request($employee, $offer, $nonce);
        $this->assertSame($order->id, $this->request($employee, $offer, $nonce)->id);
        $this->assertSame('pending', $order->status);
        $this->assertEquals(7, $order->price);
        $this->assertSame(13, economy::balance($employee->id));
        $this->assertEquals(0, $DB->get_field(reward_shop::OFFER, 'stock', ['id' => $offer->id]));
        $this->assertSame(1, $DB->count_records(reward_shop::ORDER));
        $this->assertSame(1, $DB->count_records('local_ustar_coin_ledger', ['userid'=>$employee->id,'txtype'=>'reward_shop']));
        $this->denied(fn() => $this->request($employee, $offer), 'rewardshop_stale');
        $fresh = $DB->get_record(reward_shop::OFFER, ['id'=>$offer->id], '*', MUST_EXIST);
        $this->denied(fn() => $this->request($employee, $fresh), 'rewardshop_outofstock');

        $this->setAdminUser();
        $this->denied(fn() => reward_shop::save_offer($this->admin, $offer->id, $offer->revision,
            'Термокружка', '', 7, 1, 'published'), 'rewardshop_stale');
        $this->assertEquals(0, $DB->get_field(reward_shop::OFFER, 'stock', ['id'=>$offer->id]));
    }

    public function test_rejection_refunds_snapshot_once_even_if_archived_or_paused(): void {
        global $DB;
        $this->open();
        $offer = $this->offer();
        $employee = $this->employee();
        $this->setUser($employee);
        $order = $this->request($employee, $offer);
        $this->denied(fn() => reward_shop::resolve($employee->id, $order->id, 'issued'), 'rewardshop_denied');

        $this->setAdminUser();
        $fresh = $DB->get_record(reward_shop::OFFER, ['id'=>$offer->id], '*', MUST_EXIST);
        reward_shop::save_offer($this->admin, $offer->id, $fresh->revision, 'Кружка', '', 11, 1, 'archived');
        reward_shop::configure($this->admin, false, ['shop_sales'], 1);
        $this->assertSame('rejected', reward_shop::resolve($this->admin, $order->id, 'rejected', 'Нет поставки')->status);
        $this->assertSame('rejected', reward_shop::resolve($this->admin, $order->id, 'rejected', 'Нет поставки')->status);
        $this->assertSame(20, economy::balance($employee->id));
        $this->assertEquals(2, $DB->get_field(reward_shop::OFFER, 'stock', ['id' => $offer->id]));
        $this->assertSame(1, $DB->count_records('local_ustar_coin_ledger', ['reversalofid'=>$order->ledgerid]));
    }

    public function test_issued_order_is_final_and_never_debited_twice(): void {
        $this->open();
        $offer = $this->offer();
        $employee = $this->employee();
        $this->setUser($employee);
        $order = $this->request($employee, $offer);

        $this->setAdminUser();
        $this->assertSame('issued', reward_shop::resolve($this->admin, $order->id, 'issued')->status);
        $this->assertSame('issued', reward_shop::resolve($this->admin, $order->id, 'issued')->status);
        $this->denied(fn() => reward_shop::resolve($this->admin, $order->id, 'rejected', 'Тест'), 'rewardshop_closedorder');
        $this->assertSame(13, economy::balance($employee->id));
    }

    public function test_insufficient_balance_does_not_reserve_stock_or_create_order(): void {
        global $DB;
        $this->open();
        $offer = $this->offer(1);
        $employee = $this->employee('shop_sales', 3);
        $this->setUser($employee);
        $this->denied(fn() => $this->request($employee, $offer), 'rewardshop_insufficient');
        $this->assertSame(0, $DB->count_records(reward_shop::ORDER));
        $this->assertEquals(1, $DB->get_field(reward_shop::OFFER, 'stock', ['id' => $offer->id]));
        $this->assertSame(3, economy::balance($employee->id));
    }

    public function test_erasing_pending_order_returns_stock_and_uscoin_before_anonymizing(): void {
        global $DB;
        $this->open();
        $offer = $this->offer(1);
        $employee = $this->employee();
        $this->setUser($employee);
        $order = $this->request($employee, $offer);
        $this->setAdminUser();
        $context = new \core_privacy\local\request\approved_contextlist(
            $employee, 'local_ustar', [\context_system::instance()->id],
        );
        privacy\provider::delete_data_for_user($context);

        $erased = $DB->get_record(reward_shop::ORDER, ['id'=>$order->id], '*', MUST_EXIST);
        $this->assertEquals(0, $erased->userid);
        $this->assertEquals(0, $erased->ledgerid);
        $this->assertSame('rejected', $erased->status);
        $this->assertSame(20, economy::balance($employee->id));
        $this->assertEquals(1, $DB->get_field(reward_shop::OFFER, 'stock', ['id'=>$offer->id]));
        $this->assertSame(1, $DB->count_records('local_ustar_coin_ledger', ['reversalofid'=>$order->ledgerid]));
    }
}
