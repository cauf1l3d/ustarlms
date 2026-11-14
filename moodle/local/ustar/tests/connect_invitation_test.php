<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

use local_ustar\connect\bot_invitation;
use local_ustar\connect\bot_service;
use local_ustar\connect\entry_service;
use local_ustar\connect\identity;
use local_ustar\connect\identity_service;
use local_ustar\connect\bot_confirmation;
use local_ustar\connect\opaque_token;
use tool_policy\api;
use tool_policy\policy_version;

#[\PHPUnit\Framework\Attributes\CoversClass(bot_invitation::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_ustar\connect\bot_agreement::class)]
final class connect_invitation_test extends \advanced_testcase {
    private int $sequence = 100;
    private \stdClass $person;
    private ?string $https;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->preventResetByRollback();
        global $CFG;
        $this->setAdminUser();
        $CFG->wwwroot = 'https://www.example.com/moodle';
        $this->https = $_SERVER['HTTPS'] ?? null;
        $_SERVER['HTTPS'] = 'on';
        $CFG->ustar_connect_bot_enabled = true;
        $CFG->ustar_connect_bot_invitations_enabled = true;
        $CFG->ustar_connect_channels = ['telegram' => ['tenant' => '123456', 'username' => 'ustarcorp_bot']];
        set_config('sitepolicyhandler', 'tool_policy');
        $this->person = $this->getDataGenerator()->create_user(['auth' => 'manual']);
        $role = $this->getDataGenerator()->create_role();
        $context = \context_system::instance();
        assign_capability('local/ustar:use', CAP_ALLOW, $role, $context->id);
        assign_capability('tool/policy:accept', CAP_ALLOW, $role, $context->id);
        role_assign($role, $this->person->id, $context->id);
        set_user_preference('auth_forcepasswordchange', 1, $this->person->id);
    }

    protected function tearDown(): void {
        if ($this->https === null) {
            unset($_SERVER['HTTPS']);
        } else {
            $_SERVER['HTTPS'] = $this->https;
        }
        parent::tearDown();
    }

    private function publish(string $name, string $text): int {
        $form = api::form_policydoc_data(new policy_version(0));
        $form->name = $name;
        $form->audience = policy_version::AUDIENCE_LOGGEDIN;
        $form->optional = policy_version::AGREEMENT_COMPULSORY;
        $form->content_editor['text'] = $text;
        $form->content_editor['format'] = FORMAT_HTML;
        $id = (int)api::form_policydoc_add($form)->get('id');
        api::make_current($id);
        return $id;
    }

    private function send(string $subject, string $text = '', string $callback = ''): array {
        $this->setGuestUser();
        return bot_service::update(new identity('telegram', '123456', $subject), [
            'auth_date' => time(), 'callback' => $callback, 'event' => (string)++$this->sequence,
            'media' => '', 'message' => '51', 'provider' => 'telegram',
            'subject' => $subject, 'tenant' => '123456', 'text' => $text,
        ]);
    }

    private function click(array $view, string $label, string $subject = '555'): array {
        foreach ($view['buttons'] as $button) {
            if ($button['label'] === $label) {
                return $this->send($subject, '', $button['callback']);
            }
        }
        $this->fail('Button missing: ' . $label);
    }

    public function test_verified_invitation_requires_agreement_then_opens_menu_without_web_password_reset(): void {
        global $DB, $USER;
        $version = $this->publish('Соглашение Академии', '<p>Полный текст для сотрудника.</p>');
        $invite = bot_invitation::issue((int)$this->person->id, '555');
        $this->assertStringContainsString('?start=ui1_', $invite['url']);
        $this->assertStringContainsString('недействительно', $this->send('556', '/start ' . $invite['token'])['text']);
        $this->assertFalse($DB->record_exists('local_ustar_conn_links', ['userid' => $this->person->id]));
        $view = $this->send('555', '/start ' . $invite['token']);
        $this->assertSame('Соглашение Академии', $view['title']);
        $this->assertTrue($view['plain']);
        $this->assertStringContainsString('Полный текст', $view['text']);
        $view = $this->send('555', '/tasks');
        $this->assertSame('Соглашение Академии', $view['title']);
        try {
            entry_service::create_launch(new identity('telegram', '123456', '555'), 'fixture');
            $this->fail('A bot invitation must not open the Mini App');
        } catch (\InvalidArgumentException $error) {
            $this->assertSame('native_auth_required', $error->getMessage());
        }
        $menu = $this->click($view, 'Принять соглашение');
        $this->assertSame('USTAR · Главное меню', $menu['title']);
        $this->assertContains('📋 Мои задачи', array_column($menu['buttons'], 'label'));
        $this->assertTrue(api::is_user_version_accepted($this->person->id, $version));
        $this->assertSame((int)$this->person->id, (int)api::get_user_version_acceptance(
            $this->person->id, $version)->usermodified);
        $this->assertTrue(isguestuser($USER));
        $this->assertEquals(1, get_user_preferences('auth_forcepasswordchange', 0, $this->person->id));
        $this->assertStringContainsString('недействительно', $this->send('556', $invite['token'])['text']);

        $this->setAdminUser();
        $new = $this->publish('Новые правила', '<p>Дополнительный обязательный документ.</p>');
        $this->assertSame('Новые правила', $this->click($menu, '📋 Мои задачи')['title']);
        $this->assertNull(api::is_user_version_accepted($this->person->id, $new));

        $this->setAdminUser();
        $link = $DB->get_record('local_ustar_conn_links', ['userid' => $this->person->id], '*', MUST_EXIST);
        bot_invitation::revoke((int)$link->id);
        $this->assertSame('USTAR · Добро пожаловать', $this->send('555', '/start')['title']);
    }

    public function test_unverified_invitation_waits_for_operator_and_reuses_existing_acceptance(): void {
        global $DB;
        $version = $this->publish('Соглашение Академии', '<p>Текст документа.</p>');
        $this->setUser($this->person);
        api::accept_policies($version);
        $this->setAdminUser();
        $invite = bot_invitation::issue((int)$this->person->id, '');
        $view = $this->send('555', $invite['token']);
        $this->assertSame('Подтверждение подключения', $view['title']);
        preg_match('/\b[A-F0-9]{10}\b/', $view['text'], $matches);
        $this->assertNotEmpty($matches);
        $this->assertFalse($DB->record_exists('local_ustar_conn_links', ['userid' => $this->person->id]));
        $receipt = $DB->get_field('local_ustar_conn_updates', 'responsejson',
            ['identityhash' => (new identity('telegram', '123456', '555'))->key()]);
        $this->assertStringNotContainsString($matches[0], $receipt);
        $challenge = $DB->get_record('local_ustar_conn_challenges',
            ['codehash' => opaque_token::digest($invite['token'], 'ui1')], '*', MUST_EXIST);
        $this->setAdminUser();
        bot_invitation::confirm((int)$challenge->id, $matches[0]);
        $this->assertSame('USTAR · Главное меню', $this->click($view, 'Проверить подключение')['title']);
    }

    public function test_old_accept_button_cannot_accept_a_replacement_version(): void {
        global $DB;
        $old = $this->publish('Соглашение Академии', '<p>Первый текст.</p>');
        $invite = bot_invitation::issue((int)$this->person->id, '555');
        $view = $this->send('555', $invite['token']);

        $this->setAdminUser();
        $policyid = $DB->get_field('tool_policy_versions', 'policyid', ['id' => $old], MUST_EXIST);
        $form = api::form_policydoc_data(new policy_version(0));
        $form->policyid = $policyid;
        $form->name = 'Соглашение Академии';
        $form->audience = policy_version::AUDIENCE_LOGGEDIN;
        $form->content_editor['text'] = '<p>Новый текст.</p>';
        $form->content_editor['format'] = FORMAT_HTML;
        $new = (int)api::form_policydoc_update_new($form)->get('id');
        api::make_current($new);

        $replacement = $this->click($view, 'Принять соглашение');
        $this->assertStringContainsString('Новый текст.', $replacement['text']);
        $this->assertNull(api::is_user_version_accepted($this->person->id, $new));
        $this->assertSame('USTAR · Главное меню', $this->click($replacement, 'Принять соглашение')['title']);
        $this->assertTrue(api::is_user_version_accepted($this->person->id, $new));
    }

    public function test_operator_prepares_invitation_and_receives_secret_only_in_native_confirmation(): void {
        global $DB;
        $version = $this->publish('Соглашение Академии', '<p>Текст для сотрудника.</p>');
        $operator = $this->getDataGenerator()->create_user(['auth' => 'manual']);
        $role = $DB->get_field('role', 'id', ['shortname' => 'ustar_superadmin'], MUST_EXIST);
        role_assign($role, $operator->id, \context_system::instance()->id);
        $this->setUser($operator);
        api::accept_policies($version);
        $sender = new identity('telegram', '123456', '555');
        $native = identity_service::issue('telegram', '123456');
        identity_service::claim_verified($native['code'], $sender, hash('sha256', 'operator-proof'));
        identity_service::confirm($native['code']);

        $form = $this->click($this->send('555', '/start'), '🎟 Приглашения Telegram');
        $this->assertStringContainsString('Логин', $form['text']);
        $this->send('555', $this->person->username);
        $preview = $this->send('555', '556');
        $prepared = $this->click($preview, '✓ Подтвердить');
        $this->assertStringNotContainsString('ui1_', json_encode($prepared, JSON_THROW_ON_ERROR));
        $filter = ['userid' => $this->person->id, 'issuedby' => $operator->id];
        $this->assertFalse($DB->record_exists('local_ustar_conn_challenges', $filter));
        $url = array_values(array_filter($prepared['buttons'], static fn(array $b): bool => isset($b['url'])))[0]['url'];
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $this->setUser($operator);
        [$action] = bot_confirmation::lookup($params['action'], (int)$operator->id);
        $this->assertSame('confirmation', $action->status);
        try {
            bot_confirmation::confirm($params['action'], (int)$operator->id);
            $this->fail('Invitation issued without a browser session');
        } catch (\InvalidArgumentException $error) {
            $this->assertSame('native_auth_required', $error->getMessage());
        }
        $this->assertFalse($DB->record_exists('local_ustar_conn_challenges', $filter));
        role_unassign($role, $operator->id, \context_system::instance()->id);
        $this->assertNotContains('🎟 Приглашения Telegram', array_column($this->send('555', '/start')['buttons'], 'label'));
        $this->setUser($operator);
        $this->expectException(\InvalidArgumentException::class);
        bot_confirmation::lookup($params['action'], (int)$operator->id);
    }
}
