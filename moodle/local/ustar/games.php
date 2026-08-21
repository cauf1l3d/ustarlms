<?php
require_once(__DIR__ . '/../../config.php');

require_login();
global $USER;

$context = context_system::instance();
require_capability('local/ustar:use', $context);

$payload = \local_ustar\native_data::games();
$games = [];
foreach (($payload['games'] ?? []) as $game) {
    $game['url'] = (new moodle_url('/local/ustar/game.php', ['gameid' => (int)$game['id']]))->out(false);
    $game['progress'] = !empty($game['questionCount'])
        ? min(100, (int)round(((int)$game['correct'] / max(1, (int)$game['questionCount'])) * 100))
        : 0;
    $game['difficultylabel'] = str_repeat('●', max(1, min(5, (int)($game['difficulty'] ?? 1))));
    $game['icon'] = \local_ustar\ui::icon('game', 'u-game-card__icon');
    $games[] = $game;
}

$data = [
    'games' => $games,
    'hasgames' => !empty($games),
    'totalxp' => (int)($payload['totalGameXp'] ?? 0),
    'gameicon' => \local_ustar\ui::icon('game', 'u-feature-icon'),
    'trophyicon' => \local_ustar\ui::icon('trophy', 'u-feature-icon'),
    'achievementsurl' => (new moodle_url('/local/ustar/achievements.php'))->out(false),
];

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/games.php'));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Игровые задания | USTAR Academy');
$PAGE->set_heading('USTAR Academy');

$output = $PAGE->get_renderer('local_ustar');
echo $output->header();
echo $output->render_from_template('local_ustar/games', $data);
echo $output->footer();
