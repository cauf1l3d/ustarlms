<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Single source of truth for the org structure:
 * departments -> positions (career ladder) -> skills -> courses.
 *
 * Stored as JSON in local_ustar_structure (name = 'structure').
 * Shared skills are deduplicated by skill id: if the same skill id is
 * required by positions from different departments, users of BOTH
 * departments get access to the linked courses.
 */
class structure {

    const NAME_STRUCTURE = 'structure';
    const NAME_BRANDING  = 'branding';

    /** Default branding = USTAR palette, iSpring-like soft aesthetics. */
    public static function default_branding(): array {
        return [
            'brandName'   => 'USTAR',
            'tagline'     => 'Академия',
            'primary'     => '#2B2B2B',   // graphite
            'accent'      => '#EBC500',   // ustar yellow
            'accentSoft'  => '#FFF7DA',
            'bg'          => '#F6F5F2',   // soft ivory (ispring-like)
            'surface'     => '#FFFFFF',
            'text'        => '#2B2B2B',
            'muted'       => '#8A8A8A',
            'success'     => '#3BB273',
            'warning'     => '#F2994A',
            'radius'      => 16,
            'logoUrl'     => '/brand/hozmagia-wordmark.png',
            'sidebarHeroUrl' => '/brand/ustar-banner.jpg',
            'sidebarHeroFit' => 'cover',
            'sidebarHeroPosition' => 'center',
            'sidebarHeroHeight' => 108,
            'sidebarHeroOverlay' => 8,
            'loginHeroUrl' => '/brand/ustar-banner.jpg',
            'loginHeroFit' => 'contain',
            'loginHeroPosition' => 'center top',
            'loginHeroOverlay' => 18,
            'loginEyebrow' => 'Корпоративная академия',
            'loginTitle' => 'USTAR АКАДЕМИЯ',
            'loginSubtitle' => 'Обучение, карьерные ступени, навыки, игры и развитие команды — в одном рабочем пространстве.',
        ];
    }

    /** Seed structure — replace via superadmin panel or CLI import. */
    public static function default_structure(): array {
        return [
            'departments' => [
                ['id' => 'corp',   'name' => 'Корпоративный отдел', 'cohort' => 'dept_corp'],
                ['id' => 'opt',    'name' => 'Оптовый отдел',       'cohort' => 'dept_opt'],
                ['id' => 'retail', 'name' => 'Розничный отдел',     'cohort' => 'dept_retail'],
                ['id' => 'hr',     'name' => 'HR и обучение',       'cohort' => 'dept_hr'],
            ],
            // Career ladder: positions with level (1 = junior).
            'positions' => [
                ['id' => 'corp_manager',   'department' => 'corp',   'name' => 'Менеджер корп. продаж',  'level' => 1, 'next' => 'corp_senior'],
                ['id' => 'corp_senior',    'department' => 'corp',   'name' => 'Старший менеджер',        'level' => 2, 'next' => 'corp_head'],
                ['id' => 'corp_head',      'department' => 'corp',   'name' => 'Руководитель корп. отдела','level' => 3, 'next' => null, 'ishead' => true],
                ['id' => 'opt_manager',    'department' => 'opt',    'name' => 'Менеджер оптовых продаж', 'level' => 1, 'next' => 'opt_senior'],
                ['id' => 'opt_senior',     'department' => 'opt',    'name' => 'Старший менеджер',        'level' => 2, 'next' => 'opt_head'],
                ['id' => 'opt_head',       'department' => 'opt',    'name' => 'Руководитель опт. отдела','level' => 3, 'next' => null, 'ishead' => true],
                ['id' => 'retail_seller',  'department' => 'retail', 'name' => 'Продавец-консультант',    'level' => 1, 'next' => 'retail_senior'],
                ['id' => 'retail_senior',  'department' => 'retail', 'name' => 'Старший продавец',        'level' => 2, 'next' => 'retail_head'],
                ['id' => 'retail_head',    'department' => 'retail', 'name' => 'Руководитель магазина',   'level' => 3, 'next' => null, 'ishead' => true],
            ],
            // Skills. `courses` = Moodle course idnumbers (see admin panel).
            // Shared skills (sales_basics etc.) appear in several positions:
            // that automatically grants shared course visibility.
            'skills' => [
                ['id' => 'sales_basics',   'name' => 'Основы продаж',            'category' => 'Продажи',   'courses' => ['C-SALES-101']],
                ['id' => 'negotiation',    'name' => 'Переговоры',               'category' => 'Продажи',   'courses' => ['C-NEGO-201']],
                ['id' => 'product_know',   'name' => 'Знание ассортимента',      'category' => 'Продукт',   'courses' => ['C-PROD-101']],
                ['id' => 'crm',            'name' => 'Работа в CRM',             'category' => 'Инструменты','courses' => ['C-CRM-101']],
                ['id' => 'client_service', 'name' => 'Стандарты обслуживания',   'category' => 'Сервис',    'courses' => ['C-SERV-101']],
                ['id' => 'b2b_docs',       'name' => 'Документооборот B2B',      'category' => 'Процессы',  'courses' => ['C-B2B-DOC']],
                ['id' => 'leadership',     'name' => 'Управление командой',      'category' => 'Менеджмент','courses' => ['C-LEAD-301']],
                ['id' => 'analytics',      'name' => 'Аналитика продаж',         'category' => 'Менеджмент','courses' => ['C-ANLT-201']],
            ],
            // Matrix: position -> skill -> required level (1..3).
            'matrix' => [
                'corp_manager'  => ['sales_basics' => 2, 'negotiation' => 2, 'product_know' => 2, 'crm' => 2, 'b2b_docs' => 1],
                'corp_senior'   => ['sales_basics' => 3, 'negotiation' => 3, 'product_know' => 2, 'crm' => 2, 'b2b_docs' => 2, 'analytics' => 1],
                'corp_head'     => ['negotiation' => 3, 'analytics' => 2, 'leadership' => 3, 'b2b_docs' => 2],
                'opt_manager'   => ['sales_basics' => 2, 'product_know' => 2, 'crm' => 2, 'b2b_docs' => 2],
                'opt_senior'    => ['sales_basics' => 3, 'negotiation' => 2, 'product_know' => 3, 'crm' => 2, 'analytics' => 1],
                'opt_head'      => ['analytics' => 2, 'leadership' => 3, 'b2b_docs' => 2],
                'retail_seller' => ['sales_basics' => 1, 'product_know' => 2, 'client_service' => 2],
                'retail_senior' => ['sales_basics' => 2, 'product_know' => 3, 'client_service' => 3, 'crm' => 1],
                'retail_head'   => ['client_service' => 3, 'leadership' => 2, 'analytics' => 1],
            ],
        ];
    }

    public static function get(string $name): array {
        global $DB;
        $rec = $DB->get_record('local_ustar_structure', ['name' => $name]);
        if ($rec) {
            $data = json_decode($rec->jsondata, true);
            if (is_array($data)) {
                if ($name === self::NAME_BRANDING) {
                    // Forward-compatible defaults: newly introduced visual settings
                    // appear immediately without overwriting previously saved branding.
                    return array_replace(self::default_branding(), $data);
                }
                return $data;
            }
        }
        return $name === self::NAME_BRANDING
            ? self::default_branding()
            : self::default_structure();
    }

    public static function save(string $name, array $data): void {
        global $DB, $USER;
        $rec = $DB->get_record('local_ustar_structure', ['name' => $name]);
        $now = time();
        if ($rec) {
            $rec->jsondata = json_encode($data, JSON_UNESCAPED_UNICODE);
            $rec->version++;
            $rec->usermodified = $USER->id;
            $rec->timemodified = $now;
            $DB->update_record('local_ustar_structure', $rec);
        } else {
            $DB->insert_record('local_ustar_structure', (object)[
                'name' => $name,
                'jsondata' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'version' => 1,
                'usermodified' => $USER->id,
                'timemodified' => $now,
            ]);
        }
    }

    /**
     * Resolve the workspace role and position of a Moodle user.
     * Priority: capability admin > head position (via profile field) > employee.
     *
     * Position comes from custom profile field shortname 'ustar_position'
     * (value = position id from the structure). Department is derived.
     */
    public static function resolve_user(int $userid): array {
        global $DB;

        $structure = self::get(self::NAME_STRUCTURE);

        $positionid = '';
        if (class_exists('\\local_ustar\\view_as') && isset($GLOBALS['USER']) && (int)$GLOBALS['USER']->id === $userid && view_as::active()) {
            $positionid = view_as::position_id();
        } else {
            $sql = "SELECT d.data FROM {user_info_data} d JOIN {user_info_field} f ON f.id = d.fieldid WHERE d.userid = :uid AND f.shortname = 'ustar_position'";
            if ($rec = $DB->get_record_sql($sql, ['uid' => $userid])) { $positionid = trim($rec->data); }
        }

        $position = null;
        foreach ($structure['positions'] as $p) {
            if ($p['id'] === $positionid) {
                $position = $p;
                break;
            }
        }

        $context = \context_system::instance();
        if (class_exists('\\local_ustar\\view_as') && isset($GLOBALS['USER']) && (int)$GLOBALS['USER']->id === $userid && view_as::active()) {
            $role = ($position && !empty($position['ishead'])) ? 'head' : 'employee';
        } else if (has_capability('local/ustar:admin', $context, $userid)) {
            $role = 'superadmin';
        } else if ($position && !empty($position['ishead'])) {
            $role = 'head';
        } else {
            $role = 'employee';
        }

        $department = null;
        if ($position) {
            foreach ($structure['departments'] as $d) {
                if ($d['id'] === $position['department']) {
                    $department = $d;
                    break;
                }
            }
        }

        return [
            'role'       => $role,
            'position'   => $position,
            'department' => $department,
            'structure'  => $structure,
        ];
    }

    /** Skill ids required for a position (from the matrix). */
    public static function skills_for_position(array $structure, string $positionid): array {
        return array_keys($structure['matrix'][$positionid] ?? []);
    }

    /** Course idnumbers linked to a set of skill ids (deduplicated). */
    public static function courses_for_skills(array $structure, array $skillids): array {
        $idnumbers = [];
        foreach ($structure['skills'] as $skill) {
            if (in_array($skill['id'], $skillids, true)) {
                foreach ($skill['courses'] as $idn) {
                    $idnumbers[$idn] = true;
                }
            }
        }
        return array_keys($idnumbers);
    }
}
