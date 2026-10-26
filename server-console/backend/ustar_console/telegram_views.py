"""Plain-text operator cards. Only explicitly selected aggregate fields leave the host."""
from datetime import datetime, timedelta, timezone
import math

HEALTH = {'ok':'🟢 В норме','warning':'🟡 Внимание','critical':'🔴 Авария',
          'no_data':'⚪ Нет данных','stopped':'⚫ Остановлено'}
NAMES = {'host':'Сервер', 'collector':'Сборщик показателей', 'containers':'Контейнеры Академии',
    'hdd':'HDD: подключение', 'backup':'Резервная копия', 'cron':'Фоновые задания',
    'recovery':'Возврат Академии после бэкапа', 'http_local':'Локальный HTTP', 'database':'PostgreSQL',
    'ssd_space':'SSD: свободное место', 'ssd_inodes':'SSD: inode', 'hdd_space':'HDD: свободное место',
    'hdd_inodes':'HDD: inode', 'observer':'Независимый наблюдатель', 'http_external':'внешний HTTP',
    'smart':'SMART дисков', 'clock':'Синхронизация времени', 'units':'Системные службы',
    'cpu_sustained':'Длительная нагрузка CPU', 'ram_sustained':'Недостаток RAM',
    'container_oom':'Контейнеру не хватило памяти', 'rules':'Доставка алертов',
    'resource_rules':'Правила нагрузки', 'restore':'Приёмка восстановления'}
BUTTONS = [('📊 Сводка','/status'), ('💻 Ресурсы','/metrics'), ('🎓 Академия','/academy'),
           ('💾 Бэкапы','/backup latest'), ('🧪 Стенды','/labs'), ('🔔 Алерты','/alerts'), ('🖥 Пульт','/panel')]
REASONS = {'not_configured':'источник ещё не подключён', 'disabled':'проверка отключена',
    'stale':'показания устарели', 'source_unavailable':'сборщик не предоставил свежие показания',
    'probe_failed':'проверка не завершилась; это не подтверждает исправность',
    'smart_partial_attributes':'диск не предоставляет часть SMART-атрибутов; это само по себе не поломка',
    'smart_stale':'показания SMART устарели', 'smart_not_collected':'SMART ещё не собран',
    'smart_probe_failed':'не удалось прочитать SMART', 'smart_receipt_invalid':'результат SMART не прошёл проверку',
    'smart_health_failed':'самодиагностика диска сообщает неисправность',
    'smart_sector_error':'SMART сообщает о нестабильных или неисправимых секторах',
    'smart_reallocated':'SMART сообщает о переназначенных секторах',
    'cron_worker_failed':'процесс фоновых заданий завершился с ошибкой',
    'cron_success_overdue':'успешный запуск фоновых заданий просрочен',
    'cron_success_delayed':'успешный запуск фоновых заданий задерживается',
    'cron_new_errors':'в фоновых заданиях появились ошибки',
    'cron_result_incomplete':'результат фоновых заданий неполный',
    'cron_receipt_regressed':'время результата cron стало меньше ранее принятого',
    'recovery_required':'после бэкапа остался маркер возврата Академии',
    'controller_receipt_only':'известно состояние контроллера; доступность стенда не измерялась'}


def number(value, digits=1):
    if type(value) not in (int,float) or not math.isfinite(value): return 'нет данных'
    return f'{value:.{digits}f}'.replace('.',',')


def quantity(value,suffix,digits=1):
    result=number(value,digits)
    return result+suffix if result!='нет данных' else result


def size(value):
    if type(value) not in (int,float) or not math.isfinite(value) or value<0: return 'нет данных'
    for unit in ('Б','КиБ','МиБ','ГиБ','ТиБ','ПиБ'):
        if value<1024 or unit=='ПиБ': return number(value,0 if unit=='Б' else 1)+' '+unit
        value/=1024


def period(value):
    if type(value) not in (int,float) or not math.isfinite(value) or value<0: return 'нет данных'
    value=int(value)
    if value<60: return f'{value} с'
    if value<3600: return f'{value//60} мин'
    return f'{value//3600} ч {value%3600//60} мин'


def when(value):
    try:
        parsed=datetime.fromisoformat(value)
        if parsed.tzinfo is None: return 'нет данных'
        return parsed.astimezone(timezone(timedelta(hours=3))).strftime('%d.%m.%Y %H:%M МСК')
    except (ValueError,TypeError,OverflowError): return 'нет данных'


def clean(value, limit=160):
    return ''.join(c for c in value if c.isprintable())[:limit] if isinstance(value,str) else 'нет данных'


def find(data,key):
    return next((c for c in data.get('checks',[]) if c['id']==key),{})


def source(data,key):
    row=find(data,key)
    if data.get('stale') or row.get('status')=='no_data': return {}
    value=data.get(key,{})
    return value if isinstance(value,dict) else {}


def explanation(check):
    return REASONS.get(check.get('reason_code'),'свежих проверенных показаний нет')


def updated(data):
    if data.get('stale'): return '⚪ Данные устарели. Последний снимок: '+when(data.get('observed_at'))
    return 'Обновлено: '+when(data.get('observed_at'))+' · '+period(data.get('age_seconds'))+' назад'


def disk_line(label,value):
    return (f'{label}: свободно {size(value.get("available_bytes"))} из {size(value.get("total_bytes"))}'
            f'\n   Свободные inode: {quantity(value.get("free_inode_percent"),"%") }') if value else label+': нет данных'


def details(key,data):
    host=source(data,'host');memory=host.get('memory',{})
    if key=='cpu_sustained': return 'CPU сейчас: '+quantity(host.get('cpu_percent'),'%')
    if key=='ram_sustained':
        total,available=memory.get('total_bytes'),memory.get('available_bytes')
        free=100*available/total if type(total) in (int,float) and total>0 and type(available) in (int,float) else None
        return f'Свободно RAM: {size(available)} из {size(total)} ({quantity(free,"%")})'
    if key.startswith(('ssd_','hdd_')):
        return disk_line('SSD' if key.startswith('ssd_') else 'HDD',
                         host.get('disk',{}) if key.startswith('ssd_') else source(data,'hdd'))
    if key=='backup':
        value=source(data,'backup')
        phases={'failed':'ошибка','complete':'завершена','prepare':'подготовка','capture':'сбор',
                'verify':'проверка','readiness':'проверка готовности'}
        return ('Последняя завершённая: '+when(value.get('completed_at'))+'\nВозраст: '+period(value.get('age_seconds'))+
                '\nПоследняя попытка: '+phases.get(value.get('attempt_phase'),'нет данных'))
    if key=='cron':
        value=source(data,'cron')
        return (f'Последний успех: {when(value.get("last_success_at"))} ({period(value.get("success_age_seconds"))} назад)'
                f'\nОшибки новых задач: {number(value.get("new_task_failures"),0)}; USTAR: {number(value.get("ustar_error_lines"),0)}'
                f'\nОчередь разовых задач: {number(value.get("adhoc_queued"),0)}')
    if key=='database':
        value=source(data,'database')
        return (f'Подключения: {number(value.get("connections"),0)} / {number(value.get("max_connections"),0)}'
                f'\nАктивные: {number(value.get("active"),0)}; ожидают блокировку: {number(value.get("waiting"),0)}'
                f'\nСтарейшая транзакция: {period(value.get("oldest_transaction_seconds"))}'
                f'\nРазмер БД: {size(value.get("size_bytes"))}')
    if key in ('http_local','http_external','observer'):
        value=source(data,key) or (find(data,key).get('value',{}) if not data.get('stale') and find(data,key).get('status')!='no_data' else {})
        code=number(value.get('code'),0)
        return f'HTTP {code} · {quantity(value.get("total_ms",value.get("ms"))," мс",0)}'
    if key in ('containers','container_oom'):
        row=find(data,'containers')
        if data.get('stale') or (row.get('status')=='no_data' and row.get('reason_code')!='measured'):
            return 'Контейнеры: нет свежих данных'
        states={'running':'работает','restarting':'перезапускается','exited':'остановлен','paused':'приостановлен',
                'created':'создан','dead':'не работает','removing':'удаляется'}
        rows=[]
        for row in data.get('containers',[])[:2]:
            rows.append(clean(row.get('name'))+': '+states.get(row.get('state'),'нет данных')+
                        (' · OOM: да' if row.get('oom') is True else '')+
                        ' · перезапусков: '+number(row.get('restarts'),0))
        return '\n'.join(rows) or 'Контейнеры: нет данных'
    if key=='smart':
        value=source(data,'smart');rows=[]
        for disk in value.get('disks',[])[:2]:
            health={True:'самодиагностика пройдена',False:'самодиагностика НЕ пройдена'}.get(disk.get('health_passed'),'самодиагностика: нет данных')
            row=clean(disk.get('device'))+': '+health+' · '+quantity(disk.get('temperature_c'),' °C',0)
            missing=disk.get('unavailable_attributes',[])
            if missing: row+='\n   Недоступны атрибуты: '+', '.join(clean(str(n),5) for n in missing[:5])+'; это не подтверждает поломку'
            counts=disk.get('sectors',{})
            if counts: row+='\n   Секторы: '+', '.join(k+'='+number(counts[k],0) for k in ('5','197','198','199') if k in counts)
            rows.append(row)
        return '\n'.join(rows) or 'SMART: нет данных'
    if key=='clock':
        value=source(data,'clock')
        return 'Отклонение NTP: '+quantity(value.get('offset_ms'),' мс')
    if key=='recovery':
        value=source(data,'recovery')
        return {True:'Маркер возврата Академии присутствует',False:'Маркер возврата Академии отсутствует'}.get(
            value.get('marker_present'),'Маркер возврата: нет данных')
    if key=='units':
        states={'active':'активна','inactive':'неактивна','failed':'ошибка','activating':'запускается'}
        return '\n'.join(clean(r.get('name'))+': '+states.get(r.get('state'),'нет данных')
                         for r in data.get('units',[])[:3]) if not data.get('stale') and find(data,'units').get('status')!='no_data' else 'Службы: нет свежих данных'
    return explanation(find(data,key)) if find(data,key).get('status')=='no_data' else 'Подробности — в пульте'


def keyboard(web_app_url=None):
    rows=[[dict(text=BUTTONS[i][0]),dict(text=BUTTONS[i+1][0])] for i in (0,2,4)]
    panel=dict(text=BUTTONS[6][0])
    if web_app_url: panel['web_app']={'url':web_app_url}
    return dict(keyboard=rows+[[panel]],resize_keyboard=True,is_persistent=True)


def canonical(text):
    text=text.strip()
    aliases=dict(BUTTONS)
    if text in aliases: return aliases[text]
    parts=text.split(maxsplit=1)
    if not parts: return ''
    command=parts[0].split('@',1)[0].lower()
    return command+(' '+parts[1].strip() if len(parts)>1 else '')


def reply(text,data,origin):
    command=canonical(text)
    if command in ('/start','/help'):
        return ('USTAR · Управление сервером\n\nВыберите раздел кнопками под сообщением.\n'
            '📊 /status — состояние и проблемы\n💻 /metrics — CPU, RAM, SSD, HDD, SMART\n'
            '🎓 /academy — HTTP, PostgreSQL, cron\n💾 /backup latest — последняя копия\n'
            '🧪 /labs — тестовый стенд\n🔔 /alerts — правила и текущие отклонения\n🖥 /panel — открыть пульт\n\n'
            'Важные события приходят автоматически, пока работают сервер, бот и связь с Telegram. '
            'Операции выполняются в пульте после входа и подтверждения.')
    if command=='/status':
        lines=['USTAR · Сводка','',updated(data)]
        priority={'critical':4,'no_data':3,'warning':2,'ok':1,'stopped':0}
        for key,label in [('academy','Академия'),('server','Сервер'),('data','Данные и бэкапы'),('monitoring','Мониторинг')]:
            rows=[c for c in data.get('checks',[]) if c.get('domain')==key and c.get('reason_code') not in ('not_configured','disabled')]
            state='no_data' if data.get('stale') else max((c['status'] for c in rows),key=priority.get,default='no_data')
            lines.append(label+': '+HEALTH[state])
        issues=[c for c in data.get('checks',[]) if c['status'] in ('critical','warning','no_data')
                and c.get('reason_code') not in ('not_configured','disabled','controller_receipt_only')]
        if issues:
            lines+=['','Требует внимания:']+[HEALTH[c['status']]+' · '+NAMES.get(c['id'],clean(c.get('label')))+
                    (' — '+explanation(c) if c['status']=='no_data' or c.get('reason_code') in REASONS else '') for c in issues[:5]]
        missing=[NAMES.get(c['id'],clean(c.get('label'))) for c in data.get('checks',[]) if c.get('reason_code') in ('not_configured','disabled')]
        if missing: lines+=['','Не подключено / отключено: '+', '.join(missing)+'. Контроль неполный.']
        return ('\n'.join(lines)+'\n\n'+origin)[:2900]
    if command=='/metrics':
        host=source(data,'host');memory=host.get('memory',{})
        ssd={} if find(data,'ssd_space').get('status')=='no_data' else host.get('disk',{})
        lines=['USTAR · Ресурсы','',updated(data),
            'CPU: '+quantity(host.get('cpu_percent'),'%')+' · ядер: '+number(host.get('cores'),0),
            'RAM: свободно '+size(memory.get('available_bytes'))+' из '+size(memory.get('total_bytes')),
            'Swap занят: '+size(memory.get('swap_used_bytes')), 'Без перезагрузки: '+period(host.get('uptime_seconds')),
            '',disk_line('SSD',ssd),disk_line('HDD',source(data,'hdd')),'',
            'SMART: '+HEALTH.get(find(data,'smart').get('status'),'⚪ Нет данных'),details('smart',data),
            '',details('clock',data)]
        return ('\n'.join(lines)+'\n\n'+origin)[:2900]
    if command=='/academy':
        lines=['USTAR · Академия','',updated(data)]
        for key in ('containers','http_local','database','cron'):
            row=find(data,key);state='no_data' if data.get('stale') else row.get('status','no_data')
            lines+=['',NAMES[key]+': '+HEALTH[state],details(key,data)]
            if state=='no_data': lines.append(explanation(row))
        lines+=['','Внешняя доступность: '+HEALTH.get(find(data,'http_external').get('status'),'⚪ Нет данных')]
        return ('\n'.join(lines)+'\n\n'+origin)[:2900]
    if command in ('/backup','/backup latest'):
        value=source(data,'backup')
        if not value.get('file'): return 'USTAR · Бэкапы\n⚪ Нет свежих проверенных показаний последней завершённой копии.\n'+origin+'/#backups'
        return ('USTAR · Последний бэкап\n'+HEALTH.get(value.get('status'),'⚪ Нет данных')+'\n\n'+
            'Завершён: '+when(value.get('completed_at'))+'\nВозраст: '+period(value.get('age_seconds'))+
            '\nРазмер: '+size(value.get('bytes'))+'\nФайл: '+clean(value.get('file'),220)+
            '\nSHA256: '+clean(value.get('sha256'),64)+
            '\n\nПроверка: подтверждение при создании; повторный SHA здесь не вычислялся.'+
            '\nВосстановление: ещё не принято.\nЗашифрованный архив — после входа оператора:\n'+origin+'/#backups'+
            '\nКлюч передаётся отдельно.')
    if command=='/labs':
        value=source(data,'lab')
        # The lab check deliberately says no_data: its receipt is not an availability probe.
        if not data.get('stale') and find(data,'lab').get('reason_code') in (None,'controller_receipt_only'):
            value=data.get('lab',{})
        phase=value.get('phase');names={'extracting':'Распаковывается','restoring_database':'Восстанавливается БД',
            'ready':'Готов по данным контроллера','stopped':'Остановлен','failed':'Ошибка запуска',
            'failed_stopped':'Ошибка; остановлен','cleanup_required':'Требуется очистка'}
        expiry=('Автоостановка: не действует (стенд остановлен)' if phase in ('stopped','failed_stopped') else
                'Без автоостановки' if value.get('unlimited') is True else 'Автоостановка: '+when(value.get('expires_at')))
        return ('USTAR · Тестовый стенд\n'+names.get(phase,'Нет свежих данных')+'\n'+expiry+
            '\n\nЭто состояние контроллера. Вход в Moodle и HTTP стенда проверяются отдельно.\n'+origin+'/#labs')
    if command=='/alerts':
        incidents=[c for c in data.get('checks',[]) if c['status'] in ('critical','warning','no_data') and
                   c.get('reason_code') not in ('not_configured','disabled','controller_receipt_only')]
        lines=['USTAR · Автоалерты','', 'Текущие отклонения:']
        lines += [HEALTH[c['status']]+' · '+NAMES.get(c['id'],clean(c.get('label'))) for c in incidents[:6]] or ['По свежим подключённым проверкам отклонений нет.' if not data.get('stale') else 'Нет свежих данных.']
        notifications=data.get('notifications',{})
        if notifications:
            lines+=['','В очереди доставки: '+number(notifications.get('pending'),0),
                    'Со сбоями доставки: '+number(notifications.get('delivery_failures'),0)]
        lines+=['','Пороги и выдержка:', '• CPU >85%: 10 мин.', '• Свободная RAM <15%: 5 мин; <8%: 3 мин.',
            '• SSD/HDD свободно <20%: 10 мин; <10%: 3 мин. Для SSD также <8 / <4 ГиБ.',
            '• Свободные inode <10%: 10 мин; <5%: 3 мин.',
            '• Бэкап старше 26 ч: внимание; старше 48 ч: авария. Ошибка последней попытки тоже учитывается.',
            '• HTTP локально: 30 с; внешний HTTP: 60 с, если подключён.',
            '• Контейнеры/OOM, HDD, cron, БД, SMART и время — по результатам проверок.',
            '• Потеря показаний: 60 с. Восстановление: 30 с (внешние проверки: 60 с).',
            '', 'Повторы: авария — 30 мин; внимание/нет данных — 4 ч. Плановые backup/restart подавляют только HTTP/cron/контейнеры до 45 мин.',
            '', 'Если сервер или его интернет недоступны, этот бот сам об этом не сообщит. Нужен независимый наблюдатель на другом узле.']
        return '\n'.join(lines)[:2900]
    if command=='/panel':
        return ('USTAR · Пульт\n'+origin+'\n\nТелефон должен иметь доступ к сети сервера и доверять HTTPS-сертификату. '
            'Вход — учётной записью пульта. Telegram-прокси обеспечивает связь бота, но не доступ к этому адресу. '
            'Нативная кнопка Mini App включается отдельно после проверки доступа и HTTPS на телефоне.')
    return None


def incident(check,data,now):
    key,status=check['id'],check['status']
    title={'critical':'🔴 АВАРИЯ','warning':'🟡 ВНИМАНИЕ','no_data':'⚪ НЕТ ДАННЫХ',
           'ok':'🟢 ВОССТАНОВЛЕНО','stopped':'⚫ ОСТАНОВЛЕНО'}[status]
    thresholds={'cpu_sustained':'CPU >85% непрерывно 10 мин', 'ram_sustained':'RAM <15% 5 мин; <8% 3 мин',
        'ssd_space':'свободно <20% или <8 ГиБ 10 мин; <10% или <4 ГиБ 3 мин',
        'hdd_space':'свободно <20% 10 мин; <10% 3 мин',
        'ssd_inodes':'свободные inode <10% 10 мин; <5% 3 мин',
        'hdd_inodes':'свободные inode <10% 10 мин; <5% 3 мин',
        'backup':'возраст >26 ч / >48 ч, ошибка попытки или маркер возврата',
        'database':'подключения ≥75% / ≥90%; транзакция ≥120 / ≥600 с; ожидание блокировки',
        'cron':'нет успеха >3 / >10 мин, ошибки задач или процесса',
        'clock':'отклонение NTP более 5000 мс'}
    actions={'cpu_sustained':'проверьте процессы и фоновые задания в пульте.',
        'ram_sustained':'проверьте потребление памяти и OOM; не перезапускайте Академию вслепую.',
        'backup':'проверьте последнюю попытку и доступность HDD в пульте; не удаляйте последнюю копию.',
        'recovery':'срочно проверьте возврат Академии после бэкапа; маркер вручную не удаляйте.',
        'smart':'сверьте SMART каждого диска в пульте; отсутствие атрибута не означает поломку.',
        'clock':'проверьте источник NTP и отклонение времени.',
        'database':'проверьте подключения, блокировки и длительные транзакции в пульте.',
        'cron':'проверьте последний запуск и ошибки фоновых заданий в пульте.'}
    if status=='no_data': action='проверьте сборщик и свежесть источника; исправность пока не подтверждена.'
    elif status=='ok': action='убедитесь, что работа восстановилась; уведомление основано на проверках, а не на полном функциональном тесте.'
    else: action=actions.get(key,'проверьте этот раздел пульта и соответствующие службы; не выполняйте изменения вслепую.')
    lines=['USTAR · '+title,NAMES.get(key,clean(check.get('label',key))), '',details(key,data)]
    if check.get('reason_code') in REASONS: lines+=['Причина: '+explanation(check)]
    if key in thresholds: lines+=['Правило: '+thresholds[key]]
    lines+=['','Что делать: '+action, '','Время события: '+when(datetime.fromtimestamp(now,timezone.utc).isoformat()),
            'Показания снимка: '+when(data.get('observed_at'))]
    return '\n'.join(lines)[:2900]
