<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Checklist definitions live in versioned USTAR JSON; executions live in relational audit tables. */
class checklists {
    public const NAME = 'checklists';

    public static function defaults(): array {
        return [
            'version' => 1,
            'items' => [
                [
                    'id' => 'technical_daily',
                    'title' => 'Ежедневные обязанности технического персонала',
                    'description' => 'Оцифровано из файла «Техничка чек лист xlsx.xlsx». HR может изменить назначение и формулировки.',
                    'active' => true,
                    'recurrence' => 'daily',
                    'positionIds' => ['retail_cleaner', 'dc_cleaner'],
                    'sections' => [
                        ['id' => 'entrance', 'title' => 'Входная зона', 'items' => [
                            ['id' => 'entrance_area', 'title' => 'Прилегающая к магазину территория убрана'],
                            ['id' => 'entrance_wet', 'title' => 'Подмести входную зону, лестницу и двор; провести влажную уборку'],
                            ['id' => 'mat', 'title' => 'Вытряхнуть коврик'],
                            ['id' => 'doors', 'title' => 'Протереть входные двери и стекла от пятен; контроль 2 раза в день'],
                            ['id' => 'meeting', 'title' => 'Помыть пол и протереть пыль в переговорной и прилегающих кабинетах до 09:00'],
                        ]],
                        ['id' => 'hall', 'title' => 'Зал', 'items' => [
                            ['id' => 'hall_floor', 'title' => 'Подмести зал и помыть полы чистой водой со средством; сменить воду 2 раза'],
                            ['id' => 'hall_spots', 'title' => 'Удалить доступные для удаления пятна с пола'],
                            ['id' => 'sofa', 'title' => 'Протереть поверхность кожаного дивана и маленький стол влажной тряпкой'],
                            ['id' => 'section_clean', 'title' => 'Ежедневно выбирать один отсек/отдел и убирать с перемещением доступных предметов'],
                            ['id' => 'trash', 'title' => 'Вынести мусор'],
                        ]],
                        ['id' => 'sanitary', 'title' => 'Туалеты и молельная', 'items' => [
                            ['id' => 'toilet', 'title' => 'Промыть унитаз внутри и снаружи дезинфицирующим средством'],
                            ['id' => 'sink', 'title' => 'Вымыть раковину внутри и снаружи'],
                            ['id' => 'bin', 'title' => 'Почистить и продезинфицировать мусорку; вынести мусор'],
                            ['id' => 'soap', 'title' => 'Проверить наличие жидкого мыла и заменить при необходимости'],
                            ['id' => 'walls', 'title' => 'Протереть стены и удалить потеки'],
                            ['id' => 'ablution', 'title' => 'Помыть ванну/зону омовения'],
                            ['id' => 'carpets', 'title' => 'Подмести ковры; два раза в неделю мыть под ними'],
                            ['id' => 'mirror', 'title' => 'Протирать зеркало средством для зеркал ежедневно'],
                            ['id' => 'freshener', 'title' => 'Проверить наличие освежителя'],
                            ['id' => 'frames', 'title' => 'Протереть двери и рамы от пятен и грязи'],
                            ['id' => 'inner_yard', 'title' => 'Проверить прилегающую территорию во внутреннем дворе'],
                        ]],
                        ['id' => 'weekly', 'title' => 'Периодические задачи', 'items' => [
                            ['id' => 'warehouse', 'title' => 'Прибрать на складе (2 раза в неделю)'],
                        ]],
                    ],
                ],
                [
                    'id' => 'retail_morning',
                    'title' => 'Утренний контроль торгового зала',
                    'description' => 'Оцифровано из файла «Чек лист утренний.xlsx». По умолчанию назначено администратору торгового зала; HR может переназначить.',
                    'active' => true,
                    'recurrence' => 'daily',
                    'positionIds' => ['retail_admin'],
                    'sections' => [[
                        'id' => 'checks', 'title' => 'Ежедневные проверки', 'items' => [
                            ['id' => 'biotime', 'title' => 'Сотрудники отметились в BioTime'],
                            ['id' => 'appearance', 'title' => 'Внешний вид соответствует правилам'],
                            ['id' => 'badges', 'title' => 'У всех сотрудников есть бейджи'],
                            ['id' => 'order', 'title' => 'Чистота и порядок в зоне каждого сотрудника'],
                            ['id' => 'shelves', 'title' => 'Нет пустых полок'],
                        ],
                    ]],
                ],
            ],
        ];
    }

    public static function get(): array {
        global $DB;
        $rec = $DB->get_record('local_ustar_structure', ['name' => self::NAME]);
        if (!$rec) {
            return self::defaults();
        }
        $data = json_decode($rec->jsondata, true);
        return is_array($data) ? $data : self::defaults();
    }

    public static function save(array $data): void {
        structure::save(self::NAME, $data);
    }

    public static function flat_items(array $checklist): array {
        $items = [];
        foreach (($checklist['sections'] ?? []) as $section) {
            foreach (($section['items'] ?? []) as $item) {
                if (!empty($item['id']) && !empty($item['title'])) {
                    $items[$item['id']] = $item + ['section' => $section['title'] ?? ''];
                }
            }
        }
        return $items;
    }

    public static function find(string $id): ?array {
        foreach ((self::get()['items'] ?? []) as $checklist) {
            if (($checklist['id'] ?? '') === $id) {
                return $checklist;
            }
        }
        return null;
    }

    public static function applies_to(array $checklist, string $positionid): bool {
        if (empty($checklist['active'])) {
            return false;
        }
        $positions = array_values(array_filter(array_map('strval', $checklist['positionIds'] ?? [])));
        return !$positions || in_array($positionid, $positions, true);
    }
}
