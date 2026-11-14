<?php
namespace local_ustar\event;

defined('MOODLE_INTERNAL') || die();

/** Audit receipt without the invitation, verification code or Telegram identifiers. */
final class connect_invitation_changed extends \core\event\base {
    protected function init(): void {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    public static function record(int $userid, int $reference, string $transition): void {
        self::create(['context' => \context_system::instance(), 'relateduserid' => $userid,
            'other' => ['reference' => $reference, 'transition' => $transition]])->trigger();
    }

    public static function get_name(): string {
        return get_string('eventconnectinvitationchanged', 'local_ustar');
    }

    public function get_description(): string {
        return 'Telegram invitation action ' . $this->other['transition'] . ' for user ' . $this->relateduserid . '.';
    }
}
