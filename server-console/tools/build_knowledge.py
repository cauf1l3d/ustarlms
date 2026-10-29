#!/usr/bin/env python3
"""Build a bounded, versioned knowledge bundle from reviewed repository files."""
import hashlib,json,subprocess
from pathlib import Path
from datetime import datetime,timezone
ROOT=Path(__file__).resolve().parents[2]
METADATA={'backup_restore_test': ('Стенд восстановления',
                         'Бэкапы и восстановление',
                         'Расшифровывает копию, разворачивает изолированные контейнеры и ждёт готовности '
                         'Moodle.',
                         'Создаёт отдельный стенд, расходует диск/CPU; автоматическая остановка по '
                         'длительности.',
                         'phase=ready и ready=true; окончательная проверка входа отдельно.',
                         '/usr/local/sbin/USTAR_BACKUP_RESTORE_TEST_20261014.py',
                         'lab_start'),
 'backup_transfer': ('Переносимый комплект',
                     'Бэкапы и восстановление',
                     'Собирает архив для переноса копии вместе с ключом и скриптами.',
                     'Создаёт секретный комплект на HDD; не отправляет его в Telegram.',
                     'TRANSFER_PACK=PASS; исходный архив не изменён.',
                     '/usr/local/sbin/USTAR_BACKUP_TRANSFER_20261015.py',
                     None),
 'hdd_backup': ('Резервная копия на HDD',
                'Бэкапы и восстановление',
                'Останавливает Moodle на время согласованной копии, сохраняет БД/файлы/образы и возобновляет '
                'Академию.',
                'Временная недоступность Академии; создание зашифрованного архива на HDD.',
                'success=true, phase=complete, readback_verified=true; маркер восстановления отсутствует.',
                '/usr/local/sbin/USTAR_HDD_BACKUP_20261006.py',
                'manual_backup'),
 'hdd_backup_schedule': ('Расписание HDD-бэкапов',
                         'Бэкапы и восстановление',
                         'Управляет фиксированным systemd-расписанием и читает результат последней копии.',
                         'При установке меняет timer/service; --status читает состояние.',
                         'Активный timer и подтверждённый результат последнего запуска.',
                         '/usr/local/sbin/USTAR_HDD_SCHEDULE_20261006.py',
                         None),
 'production_restore': ('Постоянная параллельная копия',
                        'Бэкапы и восстановление',
                        'Восстанавливает постоянную копию Академии на целевом сервере.',
                        'Создание отдельных данных/контейнеров; запускать по инструкции сисадмина.',
                        'Готовность Moodle плюс вход, файлы/SCORM, прогресс и reboot-приёмка.',
                        '/usr/local/sbin/USTAR_PRODUCTION_RESTORE_20261015.py',
                        None),
 'hdd_initialize': ('Подготовка HDD',
                    'Хранение и ОС',
                    'Проверяет устройство и готовит хранилище бэкапов.',
                    'Опасные изменения файловой системы/разметки; повторять принятый этап нельзя.',
                    'Совпадение UUID/устройства, mount и прав; отдельная приёмка.',
                    '',
                    None),
 'host_cold_recovery': ('Холодное восстановление хоста',
                        'Хранение и ОС',
                        'Создаёт и проверяет комплект восстановления системы перед обслуживанием.',
                        'Работа с системной копией; восстановление выполняется отдельным сценарием.',
                        'Контрольные суммы комплекта и подтверждённое восстановление.',
                        '/usr/local/sbin/USTAR_HOST_COLD_RECOVERY_20261013.py',
                        None),
 'host_package_install': ('Обслуживание Ubuntu',
                          'Хранение и ОС',
                          'Проверяет холодную копию и устанавливает согласованные пакеты ОС.',
                          'Меняет пакеты; может требовать reboot. Этап14Oct уже принят.',
                          'Установленные версии и контроль после перезагрузки.',
                          '',
                          None),
 'host_apparmor_repair': ('Восстановление AppArmor',
                          'Хранение и ОС',
                          'Исправляет проверенный конфликт профилей контейнерного окружения.',
                          'Меняет профиль безопасности; принятый этап не повторяется автоматически.',
                          'Проверки профиля и контейнеров после исправления.',
                          '',
                          None),
 'core_preflight': ('Сверка Moodle core',
                    'Диагностика',
                    'Сравнивает установленные файлы с точным upstream Git tree без запуска PHP.',
                    'Чтение файлов и git; не меняет Moodle.',
                    'Отчёт о совпадениях/дополнениях/ошибках; exit0 сам по себе не функциональная приёмка.',
                    '',
                    None),
 'server_facts': ('Факты сервера',
                  'Диагностика',
                  'Собирает ограниченный структурированный инвентарь без сырых журналов и SQL-текстов.',
                  'Чтение разрешённых источников; создаёт комплект facts/errors/manifest.',
                  'Schema/checksum validation, отдельный статус каждого источника.',
                  '',
                  None),
 'resume_restore_lab': ('Возобновление лабораторной копии',
                        'Стенды',
                        'Возобновляет ранее созданную лабораторную копию через проверенный engine.',
                        'Меняет только выделенный стенд; прод не переключает.',
                        'Проверенная готовность лабораторного Moodle.',
                        '/usr/local/sbin/USTAR_LAB_RESUME_20261005.py',
                        None),
 'lab_cold_checkpoint': ('Контрольная копия стенда',
                         'Стенды',
                         'Сохраняет контрольную точку лаборатории перед обновлением.',
                         'Остановка/копирование данных стенда.',
                         'Проверенная контрольная точка и её manifest.',
                         '/usr/local/sbin/USTAR_LAB_COLD_CHECKPOINT_20261005.py',
                         None),
 'lab_patch_preflight': ('Предварительная проверка стенда',
                         'Стенды',
                         'Проверяет условия подготовки лабораторного обновления.',
                         'Читает состояние, отказывает при несовпадении цели.',
                         'Все обязательные preflight checks успешны.',
                         '/usr/local/sbin/USTAR_LAB_PATCH_PREFLIGHT_20261005.py',
                         None),
 'lab_patch_candidate': ('Кандидат Moodle для стенда',
                         'Стенды',
                         'Готовит фиксированный кандидат кода для лаборатории.',
                         'Создаёт отдельное дерево кандидата.',
                         'Точные SHA/manifest/готовность кандидата.',
                         '/usr/local/sbin/USTAR_LAB_PATCH_CANDIDATE_20261012.py',
                         None),
 'lab_patch_readiness': ('Готовность лабораторного обновления',
                         'Стенды',
                         'Проверяет кандидата и совместимость перед обновлением стенда.',
                         'Проверки выделенной копии; возможны CLI-пробы.',
                         'Все проверки readiness без ошибок.',
                         '/usr/local/sbin/USTAR_LAB_PATCH_READINESS_CLI_20261012.py',
                         None),
 'lab_patch_upgrade': ('Обновление лаборатории',
                       'Стенды',
                       'Выполняет проверенное обновление Moodle на стенде.',
                       'Меняет БД и код стенда; требует контрольной копии.',
                       'Успешный upgrade плюс browser/SCORM/rollback-проверки.',
                       '/usr/local/sbin/USTAR_LAB_PATCH_UPGRADE_20261012.py',
                       None),
 'lab_patch_apache_check': ('Проверка HTTP стенда',
                            'Стенды',
                            'Проверяет лабораторную копию через Apache.',
                            'Обращения к стенду; не переключает production.',
                            'HTTP и функциональные проверки лаборатории.',
                            '/usr/local/sbin/USTAR_LAB_PATCH_APACHE_CHECK_20261012.py',
                            None),
 'production_patch_candidate': ('Кандидат production Moodle',
                                'Академия',
                                'Готовит согласованный production-кандидат из проверенного стенда.',
                                'Отдельное дерево кандидата; ещё не переключает работающий Moodle.',
                                'Точные SHA и manifest кандидата.',
                                '/usr/local/sbin/USTAR_PRODUCTION_PATCH_CANDIDATE_20261012.py',
                                None),
 'production_patch_readiness': ('Готовность production-кандидата',
                                'Академия',
                                'Проверяет целевой кандидат перед переключением.',
                                'Чтение/проверки согласованного кандидата.',
                                'Все обязательные readiness checks.',
                                '/usr/local/sbin/USTAR_PRODUCTION_PATCH_READINESS_20261012.py',
                                None),
 'production_patch_switch_preflight': ('Проверка перед переключением',
                                       'Академия',
                                       'Проверяет зависимости, backup и условия production switch.',
                                       'Не выполняет само переключение.',
                                       'Совпали цели, версии и безопасные условия.',
                                       '/usr/local/sbin/USTAR_PRODUCTION_SWITCH_PREFLIGHT_20261012.py',
                                       None),
 'production_patch_upgrade': ('Переключение production Moodle',
                              'Академия',
                              'Выполняет согласованное production-обновление с контрольными проверками.',
                              'Меняет код/БД и кратко останавливает Академию. Не запускать принятый этап '
                              'повторно.',
                              'Успешное переключение, exact version и отдельная приёмка владельцем.',
                              '/usr/local/sbin/USTAR_PRODUCTION_PATCH_UPGRADE_20261012.py',
                              None),
 'prepare_production_copy': ('Подготовка постоянной копии',
                             'Бэкапы и восстановление',
                             'Организует сбор переносимого комплекта из согласованных утилит.',
                             'Создаёт приватный комплект; содержит ключ расшифровки.',
                             'Проверенные SHA исходников и результат pack.',
                             '',
                             None),
 'apply_server_facts': ('Импорт фактов в контекст',
                        'Диагностика',
                        'Проверяет и импортирует полученные факты сервера в репозиторий.',
                        'Обновляет датированный контекст; не устанавливает ПО на сервере.',
                        'Проверки схемы/хешей/контекста без ошибок.',
                        '',
                        None),
 'server_facts_sh': ('Запуск сбора фактов',
                     'Диагностика',
                     'Shell-обёртка ограниченного сборщика фактов сервера.',
                     'Запускает локальный сбор по согласованной инструкции.',
                     'Проверенный комплект facts/errors/manifest.',
                     '',
                     None)}

# Explicit reviewed sources; never turn a new file into an executable UI action.
SOURCE_ONLY = frozenset(['build_code_hardening', 'build_connect_academy_installer', 'build_connect_academy_release', 'build_connect_bridge_activation', 'build_connect_chat_link_update', 'build_connect_con02_update', 'build_connect_notification_pilot', 'build_connect_private_bridge', 'build_connect_readiness', 'build_connect_telegram_update', 'connect_con02_application', 'connect_con02_contract', 'connect_con02_profiles', 'connect_con02_runtime', 'connect_con02_state'])
SOURCE_ONLY = SOURCE_ONLY | {'course_studio_update', 'build_course_studio_update'}
SOURCE_ONLY = SOURCE_ONLY | {'studio_materials_update', 'build_studio_materials_update'}
SOURCE_ONLY = SOURCE_ONLY | {'studio_colors_update', 'build_studio_colors_update'}
SOURCE_ONLY = SOURCE_ONLY | {'studio_viewport_update', 'build_studio_viewport_update'}
SOURCE_ONLY = SOURCE_ONLY | {'perf_query_update', 'build_perf_query_update'}
SOURCE_ONLY = SOURCE_ONLY | {'feed_media_update', 'build_feed_media_update', 'workday_observer'}
SOURCE_ONLY = SOURCE_ONLY | {'connect_role_bot_update', 'build_connect_role_bot_update'}
METADATA.update({'academy_https': ('Владелец HTTPS Академии',
                   'Исходники проверенных поставок',
                   'Справка об исходнике; конкретная версия и режим указаны в dated runtime-квитанции.',
                   'Не является новой операцией UI. Apply/recovery выполняет владелец по проверенной '
                   'инструкции.',
                   'Использовать ожидаемые фазу/хэш из соответствующей квитанции; наличие исходника не '
                   'доказывает установку.',
                   '',
                   None),
 'academy_tls_probe': ('Проверка TLS Академии',
                       'Исходники проверенных поставок',
                       'Справка об исходнике; конкретная версия и режим указаны в dated '
                       'runtime-квитанции.',
                       'Не является новой операцией UI. Apply/recovery выполняет владелец по '
                       'проверенной инструкции.',
                       'Использовать ожидаемые фазу/хэш из соответствующей квитанции; наличие исходника '
                       'не доказывает установку.',
                       '',
                       None),
 'backup_https_prepare': ('Подготовка backup и HTTPS',
                          'Исходники проверенных поставок',
                          'Справка об исходнике; конкретная версия и режим указаны в dated '
                          'runtime-квитанции.',
                          'Не является новой операцией UI. Apply/recovery выполняет владелец по '
                          'проверенной инструкции.',
                          'Использовать ожидаемые фазу/хэш из соответствующей квитанции; наличие '
                          'исходника не доказывает установку.',
                          '',
                          None),
 'code_hardening': ('Защита кода Академии',
                    'Исходники проверенных поставок',
                    'Справка об исходнике; конкретная версия и режим указаны в dated runtime-квитанции.',
                    'Не является новой операцией UI. Apply/recovery выполняет владелец по проверенной '
                    'инструкции.',
                    'Использовать ожидаемые фазу/хэш из соответствующей квитанции; наличие исходника не '
                    'доказывает установку.',
                    '',
                    None),
 'compose_source_privacy': ('Права исходника Compose',
                            'Исходники проверенных поставок',
                            'Справка об исходнике; конкретная версия и режим указаны в dated '
                            'runtime-квитанции.',
                            'Не является новой операцией UI. Apply/recovery выполняет владелец по '
                            'проверенной инструкции.',
                            'Использовать ожидаемые фазу/хэш из соответствующей квитанции; наличие '
                            'исходника не доказывает установку.',
                            '',
                            None),
 'connect_academy_installer': ('Установка Academy Connect',
                               'Исходники проверенных поставок',
                               'Справка об исходнике; конкретная версия и режим указаны в dated '
                               'runtime-квитанции.',
                               'Не является новой операцией UI. Apply/recovery выполняет владелец по '
                               'проверенной инструкции.',
                               'Использовать ожидаемые фазу/хэш из соответствующей квитанции; наличие '
                               'исходника не доказывает установку.',
                               '',
                               None),
 'connect_bridge_activation': ('Активация private bridge',
                               'Исходники проверенных поставок',
                               'Справка об исходнике; конкретная версия и режим указаны в dated '
                               'runtime-квитанции.',
                               'Не является новой операцией UI. Apply/recovery выполняет владелец по '
                               'проверенной инструкции.',
                               'Использовать ожидаемые фазу/хэш из соответствующей квитанции; наличие '
                               'исходника не доказывает установку.',
                               '',
                               None),
 'connect_chat_link_update': ('Обновление ссылок Connect',
                              'Исходники проверенных поставок',
                              'Справка об исходнике; конкретная версия и режим указаны в dated '
                              'runtime-квитанции.',
                              'Не является новой операцией UI. Apply/recovery выполняет владелец по '
                              'проверенной инструкции.',
                              'Использовать ожидаемые фазу/хэш из соответствующей квитанции; наличие '
                              'исходника не доказывает установку.',
                              '',
                              None),
 'connect_con02_update': ('Coordinated обновление Connect',
                          'Исходники проверенных поставок',
                          'Справка об исходнике; конкретная версия и режим указаны в dated '
                          'runtime-квитанции.',
                          'Не является новой операцией UI. Apply/recovery выполняет владелец по '
                          'проверенной инструкции.',
                          'Использовать ожидаемые фазу/хэш из соответствующей квитанции; наличие '
                          'исходника не доказывает установку.',
                          '',
                          None),
 'connect_delivery_readiness': ('Готовность поставки Connect',
                                'Исходники проверенных поставок',
                                'Справка об исходнике; конкретная версия и режим указаны в dated '
                                'runtime-квитанции.',
                                'Не является новой операцией UI. Apply/recovery выполняет владелец по '
                                'проверенной инструкции.',
                                'Использовать ожидаемые фазу/хэш из соответствующей квитанции; наличие '
                                'исходника не доказывает установку.',
                                '',
                                None),
 'connect_egress_refresh': ('Обновление транспорта Connect',
                            'Исходники проверенных поставок',
                            'Справка об исходнике; конкретная версия и режим указаны в dated '
                            'runtime-квитанции.',
                            'Не является новой операцией UI. Apply/recovery выполняет владелец по '
                            'проверенной инструкции.',
                            'Использовать ожидаемые фазу/хэш из соответствующей квитанции; наличие '
                            'исходника не доказывает установку.',
                            '',
                            None),
 'connect_https_readiness': ('Готовность HTTPS Connect',
                             'Исходники проверенных поставок',
                             'Справка об исходнике; конкретная версия и режим указаны в dated '
                             'runtime-квитанции.',
                             'Не является новой операцией UI. Apply/recovery выполняет владелец по '
                             'проверенной инструкции.',
                             'Использовать ожидаемые фазу/хэш из соответствующей квитанции; наличие '
                             'исходника не доказывает установку.',
                             '',
                             None),
 'connect_notification_pilot': ('Ограниченный pilot уведомлений',
                                'Исходники проверенных поставок',
                                'Справка об исходнике; конкретная версия и режим указаны в dated '
                                'runtime-квитанции.',
                                'Не является новой операцией UI. Apply/recovery выполняет владелец по '
                                'проверенной инструкции.',
                                'Использовать ожидаемые фазу/хэш из соответствующей квитанции; наличие '
                                'исходника не доказывает установку.',
                                '',
                                None),
 'connect_private_bridge': ('Private bridge Connect',
                            'Исходники проверенных поставок',
                            'Справка об исходнике; конкретная версия и режим указаны в dated '
                            'runtime-квитанции.',
                            'Не является новой операцией UI. Apply/recovery выполняет владелец по '
                            'проверенной инструкции.',
                            'Использовать ожидаемые фазу/хэш из соответствующей квитанции; наличие '
                            'исходника не доказывает установку.',
                            '',
                            None),
 'connect_readiness': ('Готовность Academy Connect',
                       'Исходники проверенных поставок',
                       'Справка об исходнике; конкретная версия и режим указаны в dated '
                       'runtime-квитанции.',
                       'Не является новой операцией UI. Apply/recovery выполняет владелец по '
                       'проверенной инструкции.',
                       'Использовать ожидаемые фазу/хэш из соответствующей квитанции; наличие исходника '
                       'не доказывает установку.',
                       '',
                       None),
 'connect_telegram_update': ('Обновление Telegram Connect',
                             'Исходники проверенных поставок',
                             'Справка об исходнике; конкретная версия и режим указаны в dated '
                             'runtime-квитанции.',
                             'Не является новой операцией UI. Apply/recovery выполняет владелец по '
                             'проверенной инструкции.',
                             'Использовать ожидаемые фазу/хэш из соответствующей квитанции; наличие '
                             'исходника не доказывает установку.',
                             '',
                             None),
 'cron_release_update': ('Привязка cron к версии',
                         'Исходники проверенных поставок',
                         'Справка об исходнике; конкретная версия и режим указаны в dated '
                         'runtime-квитанции.',
                         'Не является новой операцией UI. Apply/recovery выполняет владелец по '
                         'проверенной инструкции.',
                         'Использовать ожидаемые фазу/хэш из соответствующей квитанции; наличие '
                         'исходника не доказывает установку.',
                         '',
                         None),
 'infra_one_pass': ('Scoped инфраструктурный пакет',
                    'Исходники проверенных поставок',
                    'Справка об исходнике; конкретная версия и режим указаны в dated runtime-квитанции.',
                    'Не является новой операцией UI. Apply/recovery выполняет владелец по проверенной '
                    'инструкции.',
                    'Использовать ожидаемые фазу/хэш из соответствующей квитанции; наличие исходника не '
                    'доказывает установку.',
                    '',
                    None),
 'postgres_app_handoff': ('Переход на роль приложения PostgreSQL',
                          'Исходники проверенных поставок',
                          'Справка об исходнике; конкретная версия и режим указаны в dated '
                          'runtime-квитанции.',
                          'Не является новой операцией UI. Apply/recovery выполняет владелец по '
                          'проверенной инструкции.',
                          'Использовать ожидаемые фазу/хэш из соответствующей квитанции; наличие '
                          'исходника не доказывает установку.',
                          '',
                          None),
 'readonly_baseline_20261023': ('Read-only baseline23Oct',
                                'Исходники проверенных поставок',
                                'Справка об исходнике; конкретная версия и режим указаны в dated '
                                'runtime-квитанции.',
                                'Не является новой операцией UI. Apply/recovery выполняет владелец по '
                                'проверенной инструкции.',
                                'Использовать ожидаемые фазу/хэш из соответствующей квитанции; наличие '
                                'исходника не доказывает установку.',
                                '',
                                None),
 'remaining_survey': ('Оставшиеся инфраструктурные проверки',
                      'Исходники проверенных поставок',
                      'Справка об исходнике; конкретная версия и режим указаны в dated '
                      'runtime-квитанции.',
                      'Не является новой операцией UI. Apply/recovery выполняет владелец по проверенной '
                      'инструкции.',
                      'Использовать ожидаемые фазу/хэш из соответствующей квитанции; наличие исходника '
                      'не доказывает установку.',
                      '',
                      None)})

def build(root=ROOT):
    commit=subprocess.check_output(['git','-C',str(root),'rev-parse','HEAD'],text=True).strip()
    scripts=[]
    for path in sorted((root/'scripts/infra').glob('*')):
        if path.suffix not in ('.py','.sh'):continue
        name=path.stem if path.name!='server_facts.sh' else 'server_facts_sh'
        if name in SOURCE_ONLY:continue
        if name not in METADATA:raise ValueError('Missing operator description: '+path.name)
        title,category,purpose,effects,success,target,action=METADATA[name]
        row=dict(id=name.replace('_','-'),title=title,category=category,purpose=purpose,effects=effects,success=success,server_path=target,source_path=str(path.relative_to(root)),sha256=hashlib.sha256(path.read_bytes()).hexdigest())
        if action:row['action']=action
        scripts.append(row)
    for ident,title,purpose,success,action in (
        ('academy-restart','Перезапуск Академии','Фиксированная операция брокера: перезапускает только согласованный контейнер Moodle.','Задание complete и отдельная проверка ответа Академии.','academy_restart'),
        ('lab-stop','Остановка стенда','Фиксированная операция брокера: штатно останавливает изолированный стенд.','Задание complete и остановленный контроллер стенда.','lab_stop'),
    ):
        path=root/'server-console/backend/ustar_console/operations.py'
        scripts.append(dict(id=ident,title=title,category='Операции пульта',purpose=purpose,
            effects='Изменение состояния выбранной цели; план, роль и повторный ввод пароля обязательны.',
            success=success,server_path='',source_path=str(path.relative_to(root)),sha256=hashlib.sha256(path.read_bytes()).hexdigest(),action=action))
    docs=[]
    groups={
        'Пульт и эксплуатация':list((root/'server-console').glob('*.md')),
        'Архитектура и код':list((root/'context/architecture').glob('*.md')),
        'Production и восстановление':[root/'context/runtime'/name for name in ('server_map_20261016.md','production_copy_20261015.md','production_transfer_20261015.md','recovery_contract_20261005.md','production_snapshot.md','CI_SCOPE_20261016.md')],
        'Проект и текущие задачи':[root/name for name in ('START_HERE.md','AGENTS.md','context/tasks/ACTIVE.md','context/roadmap/README.md')],
    }
    for category,paths in groups.items():
        for path in sorted(paths):
            content=path.read_text()
            if len(content.encode())>150000:raise ValueError('Document limit: '+str(path))
            title=next((line.lstrip('# ').strip() for line in content.splitlines() if line.startswith('# ')),path.stem)
            docs.append(dict(id='doc-'+str(len(docs)),title=title,category=category,summary=title,source_path=str(path.relative_to(root)),sha256=hashlib.sha256(path.read_bytes()).hexdigest(),content=content))
    value=dict(source_commit=commit,generated_at=datetime.now(timezone.utc).isoformat(),scripts=scripts,documents=docs)
    target=root/'server-console/backend/ustar_console/knowledge/catalog.json'
    target.parent.mkdir(exist_ok=True)
    raw=json.dumps(value,ensure_ascii=False,indent=2)+'\n'
    if len(raw.encode())>3000000:raise ValueError('Knowledge bundle too large')
    target.write_text(raw)
    print(f'KNOWLEDGE=PASS; scripts={len(scripts)}; documents={len(docs)}')

if __name__=='__main__':build()
