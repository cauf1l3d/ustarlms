"""Interactive local LAN setup. Secrets are never command arguments or repository files."""
import argparse
import getpass
import grp
import ipaddress
import json
import os
from pathlib import Path
import pwd
import re
import socket
import subprocess
import tempfile

from .config import Settings, trusted_config

CONFIG=Path('/etc/ustar-console')


def run(argv,**kwargs):
    return subprocess.run(argv,check=True,timeout=kwargs.pop('timeout',120),**kwargs)


def save(path,data,mode=0o600,group=0):
    fd,name=tempfile.mkstemp(prefix='.setup-',dir=path.parent)
    try:
        with os.fdopen(fd,'w') as stream:
            os.fchmod(stream.fileno(),mode);os.fchown(stream.fileno(),0,group)
            stream.write(json.dumps(data,ensure_ascii=False,indent=2)+'\n')
            stream.flush();os.fsync(stream.fileno())
        os.replace(name,path)
    finally:
        if os.path.exists(name): os.unlink(name)


def answer(prompt,default):
    return input(prompt+' ['+default+']: ').strip() or default


def telegram(proxy=None):
    from .alerts import Telegram,DeliveryError
    token=getpass.getpass('Токен отдельного бота (Enter — настроить позже): ').strip()
    if not token: return False
    client=Telegram(token,proxy=proxy)
    try:
        client('getMe',{})
        if client('getWebhookInfo',{}).get('url'):
            raise SystemExit('У этого бота уже есть webhook. Настройка не изменена; нужен отдельный бот.')
    except DeliveryError:
        raise SystemExit('Bot API недоступен или токен отклонён; секрет не сохранён.') from None
    raw=input('Разрешённые numeric user ID через запятую (только личные чаты): ').strip()
    if not re.fullmatch(r'[0-9]+(?:,[0-9]+){0,9}',raw):
        raise SystemExit('Нужны числовые ID без пробелов.')
    ids=[int(i) for i in raw.split(',')]
    if any(not 0<i<2**52 for i in ids): raise SystemExit('Некорректный numeric ID')
    value=dict(token=token,users=ids,chats=ids)
    if proxy: value['proxy']=proxy
    save(CONFIG/'telegram.json',value)
    print('Telegram настроен. Отправьте /start своему боту с разрешённого аккаунта.')
    return True


def certificate(address,group):
    authority=CONFIG/'authority';tls=CONFIG/'tls'
    for path,mode in ((authority,0o700),(tls,0o750)):
        path.mkdir(mode=mode,exist_ok=True)
        if path.is_symlink() or path.stat().st_uid!=0 or path.stat().st_mode&0o022:
            raise SystemExit('Недоверенный каталог сертификатов')
        os.chown(path,0,0 if path==authority else group);os.chmod(path,mode)
    ca=authority/'ca.crt';key=authority/'ca.key'
    paths=(ca,key,tls/'server.crt',tls/'server.key')
    if any(path.exists() or path.is_symlink() for path in paths):
        if not all(path.is_file() and not path.is_symlink() and path.stat().st_uid==0 and not path.stat().st_mode&0o022 for path in paths):
            raise SystemExit('Неполный набор сертификатов; проверьте локально, автоматической замены CA нет.')
        run(['openssl','verify','-CAfile',str(ca),'-verify_ip',address,str(tls/'server.crt')])
        return
    quiet=dict(stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    run(['openssl','req','-x509','-newkey','rsa:3072','-nodes','-sha256','-days','3650',
         '-keyout',str(key),'-out',str(ca),'-subj','/CN=USTAR Console LAN Root CA',
         '-addext','basicConstraints=critical,CA:TRUE,pathlen:0',
         '-addext','keyUsage=critical,keyCertSign,cRLSign'],**quiet)
    request=authority/'server.csr';extensions=authority/'server.ext'
    extensions.write_text('basicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=IP:'+address+'\n')
    run(['openssl','req','-new','-newkey','rsa:3072','-nodes','-sha256','-keyout',str(tls/'server.key'),
         '-out',str(request),'-subj','/CN=USTAR Console LAN'],**quiet)
    run(['openssl','x509','-req','-in',str(request),'-CA',str(ca),'-CAkey',str(key),'-CAcreateserial',
         '-out',str(tls/'server.crt'),'-days','365','-sha256','-extfile',str(extensions)],**quiet)
    for path in authority.iterdir(): os.chmod(path,0o600)
    for path in tls.iterdir(): os.chown(path,0,group);os.chmod(path,0o640)
    public=CONFIG/'ustar-console-ca.crt'
    public.write_bytes(ca.read_bytes());os.chmod(public,0o644)
    run(['openssl','verify','-CAfile',str(ca),'-verify_ip',address,str(tls/'server.crt')])
    print('Отпечаток CA для сверки при импорте на рабочем компьютере:')
    run(['openssl','x509','-in',str(ca),'-noout','-fingerprint','-sha256'])


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--telegram-only',action='store_true')
    parser.add_argument('--telegram-proxy',action='store_true',help='Use the fixed loopback Telegram proxy')
    args=parser.parse_args()
    if args.telegram_proxy and not args.telegram_only:
        parser.error('--telegram-proxy requires --telegram-only')
    if os.geteuid()!=0 or not os.isatty(0):
        raise SystemExit('Запустите sudo из интерактивного SSH-терминала.')
    os.umask(0o077)
    trusted_config(CONFIG/'config.json')
    if args.telegram_only:
        from .telegram_transport import PROXY
        if telegram(PROXY if args.telegram_proxy else None):
            run(['systemctl','enable','ustar-console-notify.service'])
            # Reload LoadCredential even when the notifier was already active.
            run(['systemctl','restart','ustar-console-notify.service'])
        return
    active=subprocess.run(['systemctl','is-active','--quiet','ustar-console.service'],timeout=5).returncode==0
    if active:
        raise SystemExit('Пульт уже запущен. Для Telegram: ustar-console-setup --telegram-only. Приёмка: ustar-console-accept.')
    addresses=json.loads(subprocess.check_output(['ip','-j','-4','address','show'],timeout=5))
    local={entry['local'] for interface in addresses for entry in interface.get('addr_info',[])
           if entry.get('family')=='inet' and entry.get('scope')=='global'}
    candidates=sorted(a for a in local if ipaddress.ip_address(a).is_private and not ipaddress.ip_address(a).is_loopback)
    print('IPv4 этого сервера: '+', '.join(candidates))
    default='192.168.1.60' if '192.168.1.60' in candidates else candidates[0] if candidates else ''
    address=answer('Внутренний IPv4 пульта',default)
    if address not in candidates: raise SystemExit('Выбранный адрес не назначен этому серверу.')
    port=8443
    settings=Settings(origin=f'https://{address}:{port}',listen=address,port=port,tls=True,native_history=True,
                      database_probe=True,local_http=True)
    with socket.socket() as listener:
        try: listener.bind((address,port))
        except OSError: raise SystemExit('Порт 8443 занят. Существующая служба не изменена.') from None
    print('Настройка отдельного LAN HTTPS; существующие Academy/Apache/Caddy/firewall не меняются.')
    print('Мониторинг: HEAD /login/index.php каждые 15с; возможны штатные записи access log и Moodle session.')
    print('Однократное создание monitor-роли PostgreSQL. Обычный сбор использует только эту роль.')
    sql=CONFIG/'database-monitor.sql'
    # One-time schema/grants require the existing local database administrator.
    with sql.open('rb') as source:
        run(['docker','exec','-i','ustar_postgres','psql','-X','-w','-U','moodle','-d','moodle','-v','ON_ERROR_STOP=1'],stdin=source,
            stdout=subprocess.DEVNULL)
    from .database import database
    database()
    group=grp.getgrnam('ustar-console').gr_gid
    certificate(address,group)
    save(CONFIG/'config.json',dict(origin=settings.origin,listen=address,port=port,tls=True,
        prometheus=None,native_history=True,database_probe=True,local_http=True),0o640,group)
    username=answer('Логин владельца пульта','owner')
    if not re.fullmatch(r'[a-z][a-z0-9_.-]{0,47}',username): raise SystemExit('Некорректный логин')
    run(['runuser','-u','ustar-console','--','/opt/ustar-console/venv/bin/ustar-console-user',username,'--role','owner'],timeout=900)
    if answer('Подключить TOTP для владельца? да/нет','да').lower() in ('да','yes','y'):
        run(['runuser','-u','ustar-console','--','/opt/ustar-console/venv/bin/ustar-console-user',username,'--totp','enroll'],timeout=900)
    notified=telegram()
    run(['systemd-analyze','verify','/etc/systemd/system/ustar-console.service',
         '/etc/systemd/system/ustar-console-collect.service','/etc/systemd/system/ustar-console-ops.service',
         '/etc/systemd/system/ustar-console-notify.service'])
    run(['systemctl','enable','--now','ustar-console-ops.service','ustar-console-collect.timer','ustar-console-smart.timer','ustar-console.service'])
    if notified: run(['systemctl','enable','--now','ustar-console-notify.service'])
    print('Адрес пульта: '+settings.origin)
    print('Публичный CA для переноса на рабочий компьютер: /etc/ustar-console/ustar-console-ca.crt')
    print('Проверка установки: sudo /opt/ustar-console/venv/bin/ustar-console-accept')
