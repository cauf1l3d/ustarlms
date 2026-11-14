<?php
namespace local_ustar\connect;

defined('MOODLE_INTERNAL') || die();

/** Personal widget messages share the signed identity, action and message binding. */
final class bot_widgets {
    public static function in_pilot(int $userid, mixed $ids): bool {
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 10) {
            return false;
        }
        foreach ($ids as $id) {
            if (!is_int($id) || $id <= 0) {
                return false;
            }
        }
        return in_array($userid, $ids, true);
    }

    public static function is_intent(string $intent): bool {
        return in_array($intent, [bot_checklist_widget::INTENT, bot_task_widget::INTENT], true);
    }

    public static function update(identity $sender, array $input): array {
        try {
            return identity_service::locked(static function() use ($sender, $input): array {
                global $DB;
                $userid = bot_actor::active($sender);
                if (!$userid) {
                    return ['status' => 'stale'];
                }
                $dialog = bot_store::dialog($sender, $userid, bot_actor::link($sender));
                $action = bot_store::resolve($dialog, $input['callback']);
                if (!self::is_intent($action->intent) || !in_array($action->status, ['issued', 'done'], true)) {
                    return ['status' => 'stale'];
                }
                $task = $action->intent === bot_task_widget::INTENT;
                if ($task ? !bot_task_widget::in_pilot($userid) : !bot_checklist_widget::in_pilot($userid)) {
                    return ['status' => 'stale'];
                }
                $target = json_decode($action->payload, true, 16, JSON_THROW_ON_ERROR);
                if ($input['widget'] === 'bind') {
                    if ($target['event'] !== $input['event']
                            || ($target['message'] !== '' && $target['message'] !== $input['message'])) {
                        return ['status' => 'stale'];
                    }
                    if ($task) {
                        bot_task_widget::verify($userid, $target);
                    }
                    $target['message'] = $input['message'];
                    $DB->set_field('local_ustar_conn_actions', 'payload', bot_store::json($target), ['id' => $action->id]);
                    return ['status' => 'bound'];
                }
                if ($target['message'] !== $input['message']) {
                    return ['status' => 'stale'];
                }
                return bot_actor::run($userid, static function() use ($task, $userid, $action, $target, $input): array {
                    return $task
                        ? bot_task_widget::start($userid, $action, $target, $input['widget'])
                        : bot_checklist_widget::submit($userid, $action, $target, $input['widget']);
                });
            });
        } catch (\invalid_parameter_exception | \required_capability_exception | \dml_missing_record_exception $error) {
            return ['status' => 'stale'];
        } catch (\InvalidArgumentException $error) {
            if ($error->getMessage() === 'identity_busy') {
                return ['status' => 'retry'];
            }
            if (in_array($error->getMessage(), ['expired_bot_action', 'actor_unavailable',
                    'native_auth_required', 'inactive_actor', 'forbidden_scope', 'stale_revision'], true)) {
                return ['status' => 'stale'];
            }
            throw $error;
        }
    }
}
