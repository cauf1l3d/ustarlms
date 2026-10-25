#!/usr/bin/env python3
"""One local, bounded infrastructure survey. Default is offline PLAN_ONLY."""
import argparse
from datetime import datetime, timezone
import grp
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import re
import shlex
import stat
import subprocess
import sys
import time

OBSERVABILITY_DIAGNOSE_SOURCE="#!/usr/bin/env python3\n\"\"\"Read-only comparison of fixed web-log sources and the last collector snapshot.\n\nDoes not request Academy pages, reload Apache, or export raw logs/configuration.\n\"\"\"\nimport json\nimport math\nimport os\nfrom pathlib import Path\nimport stat\nimport time\n\nfrom ustar_console.data import epoch, safe_json, stamp\nfrom ustar_console.web_observability import ACCESS_LOG, collect\n\nREASONS={'measured','source_empty','source_unavailable','source_invalid','source_stale',\n         'source_limit','insufficient_coverage'}\n\n\ndef _summary(value):\n    value=value if isinstance(value,dict) else {}\n    def state(key):return value.get(key) if value.get(key) in ('ok','no_data') else 'no_data'\n    def reason(key):return value.get(key) if value.get(key) in REASONS else 'source_invalid'\n    counters={}\n    for key in ('requests','rps','p95_ms','errors_4xx','errors_5xx','coverage_seconds'):\n        number=value.get(key)\n        counters[key]=number if type(number) in (int,float) and math.isfinite(number) and number>=0 else None\n    return dict(requests=dict(status=state('status'),reason_code=reason('reason_code'),**counters),\n                events=dict(status=state('events_status'),reason_code=reason('events_reason_code'),\n                            categories=len(value['events']) if isinstance(value.get('events'),list) else None))\n\n\ndef diagnose(settings,now=None):\n    now=time.time() if now is None else now\n    result=dict(observed_at=stamp(now),direct=_summary(collect()),\n                snapshot=dict(status='unavailable_or_invalid'),numeric_log=dict(present=False))\n    try:\n        value=safe_json(settings.snapshot,owner=0)\n        at=value.get('observed_at');age=now-epoch(at)\n        valid=math.isfinite(age) and age>=-5\n        result['snapshot']=dict(status='fresh' if valid and age<=45 else 'stale_or_invalid_time',\n                                observed_at=at if valid else None,**_summary(value.get('web_observability')))\n    except (OSError,ValueError,TypeError,KeyError):\n        pass\n    try:\n        info=ACCESS_LOG.lstat()\n        result['numeric_log']=dict(present=True,regular=stat.S_ISREG(info.st_mode),\n            bytes=info.st_size,owner_id=info.st_uid,mode=oct(stat.S_IMODE(info.st_mode)))\n    except OSError:\n        pass\n    return result\n\n\ndef main():\n    if os.geteuid()!=0:raise SystemExit('Run with sudo')\n    from ustar_console.config import load\n    print(json.dumps(diagnose(load()),ensure_ascii=False,indent=2,allow_nan=False))\n\n\nif __name__=='__main__':main()\n"

MAX_OUTPUT=256*1024
ERROR_CODES={'budget_exhausted','unavailable','timeout','command_unavailable','output_limit',
             'command_failed','invalid_json_output','unexpected_json_type','size_or_type','json_unavailable'}

def error_code(value):
    return value if isinstance(value,str) and value in ERROR_CODES else 'producer_error_present'
SSH_FIELDS={'permitrootlogin','passwordauthentication','kbdinteractiveauthentication',
 'pubkeyauthentication','authenticationmethods','permitemptypasswords','usepam',
 'x11forwarding','allowtcpforwarding','maxauthtries','logingracetime','maxsessions',
 'clientaliveinterval','clientalivecountmax','allowusers','allowgroups','denyusers','denygroups'}
UNITS=('cron.service','logrotate.timer','smartmontools.service','certbot.timer','docker.service','apache2.service','caddy.service','ssh.service','sshd.service',
 'ustar-hdd-backup.timer','ustar-hdd-backup.service','ustar-hdd-backup-recover.service',
 'ustar-console.service','ustar-console-ops.service','ustar-console-collect.timer',
 'ustar-console-collect.service','ustar-console-notify.service','ustar-connect-receiver.service',
 'ustar-connect-notify.service','ustar-connect-probe.service','ustar-telegram-egress.service',
 'ustar-autoreport.service','postfix.service','dovecot.service','bind9.service',
 'nut-monitor.service','nut-server.service','apcupsd.service')
PROPERTIES=('Id','LoadState','ActiveState','SubState','UnitFileState','Result',
 'ExecMainCode','ExecMainStatus','NRestarts','LastTriggerUSec','NextElapseUSecRealtime')
PRIVATE_ROOTS=('/etc/ustar-backup','/etc/ustar-connect','/etc/ustar-console',
 '/etc/ustar-console-egress','/var/lib/ustar-backup-export','/var/lib/ustar-backup',
 '/var/lib/ustar-code-hardening','/var/lib/ustar-postgres-app-handoff',
 '/srv/ustar-storage/backups')
DATA=Path('/opt/ustar/data/moodle/moodledata')
ACCEPTED=('SSD growth','Moodle5.1.8 upgrade/repeat/SCORM','OS patch/reboot',
 'LAN HTTPS','HDD schedule','Connect2300 ACK/history','ordinary app role handoff',
 'code_hardened and owner reports application works')

class Runner:
    def __init__(self,budget=240):self.deadline=time.monotonic()+budget
    def run(self,argv,*,timeout=10,raw=None,accept_nonzero=False):
        remaining=self.deadline-time.monotonic()
        if remaining<=0:return {'error':'budget_exhausted'}
        try:
            r=subprocess.run(argv,input=raw,stdout=subprocess.PIPE,stderr=subprocess.PIPE,
                timeout=min(timeout,remaining),env={'PATH':'/usr/sbin:/usr/bin:/sbin:/bin','LC_ALL':'C','LANG':'C'})
        except FileNotFoundError:return {'error':'unavailable'}
        except subprocess.TimeoutExpired:return {'error':'timeout'}
        except OSError:return {'error':'command_unavailable'}
        if len(r.stdout)+len(r.stderr)>MAX_OUTPUT:return {'error':'output_limit'}
        if r.returncode and not accept_nonzero:return {'error':'command_failed','returncode':r.returncode}
        return {'stdout':r.stdout.decode('utf-8',errors='replace'),'returncode':r.returncode}
    def parsed(self,argv,**kwargs):
        r=self.run(argv,**kwargs)
        if 'error' in r:return r
        try:
            value=json.loads(r['stdout'])
            return value if isinstance(value,dict) else {'error':'unexpected_json_type'}
        except (ValueError,TypeError):return {'error':'invalid_json_output'}


def metadata(path):
    p=Path(path)
    try:
        s=p.lstat();kind=('symlink' if stat.S_ISLNK(s.st_mode) else 'directory' if stat.S_ISDIR(s.st_mode)
                        else 'file' if stat.S_ISREG(s.st_mode) else 'special')
        result={'path':str(p),'kind':kind,'uid':s.st_uid,'gid':s.st_gid,
                'mode':oct(stat.S_IMODE(s.st_mode)),'size':s.st_size,'links':s.st_nlink,'device':s.st_dev,'inode':s.st_ino,'mtime_ns':s.st_mtime_ns}
        try:result['acl']=[x for x in os.listxattr(p,follow_symlinks=False) if x.startswith('system.posix_acl_')]
        except OSError:result['acl_status']='unavailable'
        return result
    except FileNotFoundError:return {'path':str(p),'absent':True}
    except OSError:return {'path':str(p),'error':'metadata_unavailable'}


def read_json(path,maximum=MAX_OUTPUT):
    p=Path(path)
    try:
        s=p.lstat()
        if not stat.S_ISREG(s.st_mode) or s.st_size>maximum:return {'error':'size_or_type'}
        with os.fdopen(os.open(p,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK),'rb') as f:
            raw=f.read(maximum+1)
        if len(raw)>maximum:return {'error':'size_or_type'}
        return json.loads(raw)
    except FileNotFoundError:return {'absent':True}
    except (OSError,ValueError):return {'error':'json_unavailable'}


def docker_public(data):
    keep=('id','image','running','restarts','oom','health','healthcheck_present','rootfs_read_only','memory_limit','ports')
    result={k:data[k] for k in keep if k in data}
    options={}
    for key,value in (data.get('log_options') or {}).items():
        value=str(value)
        if key in ('max-size','max-buffer-size') and re.fullmatch(r'\d+[kKmMgG]?[bB]?',value):options[key]=value
        elif key=='max-file' and value.isdigit():options[key]=value
        elif key=='compress' and value in ('true','false'):options[key]=value
        elif key=='mode' and value in ('blocking','non-blocking'):options[key]=value
    result.update(log_driver=data.get('log_driver'),log_options=options)
    result['mounts']=[{'source':v.get('Source'),'destination':v.get('Destination'),
                       'rw':v.get('RW'),'type':v.get('Type')} for v in data.get('mounts',[])]
    if data.get('log_path'):result['log_file']=metadata(data['log_path'])
    return result


def ssh_public(text):
    result={}
    for line in text.splitlines():
        key,_,value=line.partition(' ')
        if key in SSH_FIELDS:result[key]=value[:200]
    return result


def ssh_context(text):
    try:
        remote,port,local,lport=text.split()
        ipaddress.ip_address(remote);ipaddress.ip_address(local)
        if not all(p.isdigit() and 0<int(p)<65536 for p in (port,lport)):return None
        return {'addr':remote,'laddr':local,'lport':lport}
    except (ValueError,AttributeError):return None


def backup_public(data):
    result={k:data[k] for k in ('success','staging_retained','timer_unit_present','archive_deletion') if isinstance(data.get(k),bool)}
    if data.get('phase') in ('prepare','snapshot','verify','readiness','complete','failed','error'):
        result['phase']=data['phase']
    for k in ('started_utc','finished_utc','completed_utc'):
        if isinstance(data.get(k),str) and re.fullmatch(r'[0-9TZ:.+\-]{10,40}',data[k]):result[k]=data[k]
    source=data.get('manifest',data);manifest={}
    if isinstance(source,dict):
        digest=source.get('sha256')
        if isinstance(digest,str) and re.fullmatch('[0-9a-f]{64}',digest):manifest['sha256']=digest
        for k in ('bytes','size_bytes','readback_verified'):
            if isinstance(source.get(k),(int,bool)):manifest[k]=source[k]
        archive=source.get('archive')
        if isinstance(archive,str) and archive.startswith('/srv/ustar-storage/') and archive.endswith('.age'):
            manifest['archive']=archive
    result['manifest']=manifest
    for k in ('absent','error'):
        if k=='absent' and isinstance(data.get(k),bool):result[k]=data[k]
        elif k=='error' and k in data:result[k]=error_code(data[k])
    return result


def smart_public(data):
    passed=(data.get('smart_status') or {}).get('passed')
    result={'passed':passed if isinstance(passed,bool) else None}
    code=data.get('smartctl',{}).get('exit_status')
    if isinstance(code,int):result['smartctl_exit_status']=code
    result['ata_counters']={str(v['id']):v.get('raw',{}).get('value')
        for v in data.get('ata_smart_attributes',{}).get('table',[]) if v.get('id') in (5,9,187,188,197,198,199) and isinstance(v.get('raw',{}).get('value'),(int,float))}
    nvme=data.get('nvme_smart_health_information_log',{})
    result['nvme']={k:nvme[k] for k in ('critical_warning','temperature','available_spare',
                       'percentage_used','media_errors','unsafe_shutdowns') if isinstance(nvme.get(k),(int,float))}
    if 'error' in data:result['error']=error_code(data['error'])
    if data.get('power_mode') in ('STANDBY','SLEEP','ACTIVE','IDLE'):result['power_mode']=data['power_mode']
    result['status_established']=result['passed'] is not None or bool(result['ata_counters']) or bool(result['nvme'])
    return result


def permission_survey():
    fixed=('/opt/ustar/.env','/opt/ustar/docker-compose.yml','/etc/ustar-backup/recovery.key',
           '/etc/ustar-backup/recipients.txt','/etc/ustar-console/authority/ca.key',
           '/opt/ustar/data/postgres',str(DATA))+tuple(str(DATA/n) for n in ('cache','localcache','sessions','temp','trashdir'))
    paths=set(fixed)|set(PRIVATE_ROOTS)
    for name in list(paths):paths.update(str(p) for p in Path(name).parents)
    result={'fixed_and_parents':[metadata(p) for p in sorted(paths)],'roots':[]}
    for root in PRIVATE_ROOTS:
        base=Path(root);entries=[];errors=[];truncated=False
        if base.is_dir() and not base.is_symlink():
            device=base.stat().st_dev
            for directory,dirs,files in os.walk(base,followlinks=False,onerror=lambda _:errors.append('walk_error')):
                depth=len(Path(directory).relative_to(base).parts)
                children=dirs[:]+files
                for name in dirs[:]:
                    entry=metadata(Path(directory)/name)
                    if entry.get('kind')!='directory' or depth>=2 or entry.get('device')!=device:dirs.remove(name)
                for name in children:
                    entries.append(metadata(Path(directory)/name))
                    if len(entries)>=120:truncated=True;break
                if truncated:break
        result['roots'].append({'path':root,'entries':entries,'errors':errors,'truncated':truncated,'max_depth':3})
    try:result['www_data_group_members']=grp.getgrgid(33).gr_mem
    except KeyError:result['www_data_group_members']='group_absent'
    result['legacy_exports']=legacy_exports()
    result['ssh_keys']=[metadata(p) for p in (Path('/root/.ssh/id_rsa'),Path('/root/.ssh/id_ed25519'),
                         Path('/home/aduk/.ssh/id_rsa'),Path('/home/aduk/.ssh/id_ed25519'))]
    result['scope']='known USTAR roots and bounded candidate-export metadata; not a full shared-host scan'
    return result


def legacy_exports():
    result=[]
    for root in (Path('/home/aduk'),Path('/root'),Path('/opt/ustar')):
        found=[];visited=0;errors=[];limited=False
        if root.is_dir() and not root.is_symlink():
            device=root.stat().st_dev
            for directory,dirs,files in os.walk(root,followlinks=False,onerror=lambda _:errors.append('walk_error')):
                depth=len(Path(directory).relative_to(root).parts)
                dirs[:]=[n for n in dirs if not n.startswith('.') and n not in ('data','node_modules','.git')
                         and depth<1 and not (Path(directory)/n).is_symlink()
                         and (Path(directory)/n).lstat().st_dev==device]
                for name in files:
                    visited+=1
                    candidate=(name.lower().endswith(('.sql','.dump','.age','.key')) or name=='.env' or
                        re.match(r'(?i)(ustar|moodle|backup|recovery|snapshot|academy|dr)[-_].*\.(?:zip|tgz|tar|tar\.gz)$',name))
                    if candidate:found.append(metadata(Path(directory)/name))
                    if visited>=2000 or len(found)>=80:limited=True;break
                if limited:break
        result.append({'root':metadata(root),'candidates':found,'visited':visited,'limited':limited,'errors':errors,'max_depth':2})
    return result


def docker_survey(runner):
    fmt=('{"id":{{json .Id}},"image":{{json .Image}},"running":{{json .State.Running}},'
         '"restarts":{{json .RestartCount}},"oom":{{json .State.OOMKilled}},'
         '"health":{{with index .State "Health"}}{{json .Status}}{{else}}null{{end}},'
         '"healthcheck_present":{{if index .Config "Healthcheck"}}true{{else}}false{{end}},'
         '"log_driver":{{json .HostConfig.LogConfig.Type}},"log_options":{{json .HostConfig.LogConfig.Config}},'
         '"log_path":{{json .LogPath}},"mounts":{{json .Mounts}},"ports":{{json .NetworkSettings.Ports}},'
         '"rootfs_read_only":{{json .HostConfig.ReadonlyRootfs}},"memory_limit":{{json .HostConfig.Memory}}}')
    result={}
    for name in ('ustar_moodle','ustar_postgres'):
        raw=runner.parsed(['docker','inspect','--format',fmt,name])
        result[name]=raw if 'error' in raw else docker_public(raw)
        result[name]['_sample_monotonic']=time.monotonic()
    usage=runner.run(['docker','system','df','--format','{{json .}}'],timeout=15)
    if 'stdout' in usage:
        try:result['usage']=[{k:v for k,v in json.loads(line).items() if k in ('Type','TotalCount','Active','Size','Reclaimable')}
                            for line in usage['stdout'].splitlines()]
        except (ValueError,TypeError):result['usage']={'error':'invalid_usage_output'}
    else:result['usage']=usage
    return result


def storage_survey(runner):
    result={'filesystems':[],'sizes':[]}
    for root in ('/','/srv/ustar-storage',str(DATA)):
        try:
            v=os.statvfs(root);result['filesystems'].append({'path':root,'total_bytes':v.f_blocks*v.f_frsize,
                'available_bytes':v.f_bavail*v.f_frsize,'free_inodes':v.f_favail})
        except OSError:result['filesystems'].append({'path':root,'error':'statvfs_unavailable'})
    for root in ('/opt/ustar/data/moodle/public',str(DATA),'/opt/ustar/data/postgres',
                 '/var/lib/docker','/var/log','/var/lib/ustar-restore-lab','/srv/ustar-storage/backups'):
        r=runner.run(['du','-x','-s','-B','1',root],timeout=12)
        if 'stdout' in r and re.match(r'^\d+\s',r['stdout']):result['sizes'].append({'path':root,'bytes':int(r['stdout'].split()[0])})
        else:result['sizes'].append({'path':root,'error':r.get('error','unexpected_du_output')})
    mount=runner.parsed(['findmnt','--json','--target','/srv/ustar-storage','--output','TARGET,SOURCE,FSTYPE,UUID,OPTIONS'])
    if 'error' in mount:result['hdd_mount']=mount
    else:
        result['hdd_mount']=[{**{k:v.get(k) for k in ('target','fstype','uuid')},
            'source':v.get('source') if str(v.get('source','')).startswith('/dev/') else 'non_block_source',
            'flags':[o for o in v.get('options','').split(',') if o in ('rw','ro','nosuid','nodev','noexec','relatime')]}
            for v in mount.get('filesystems',[])]
    r=runner.run(['journalctl','--disk-usage'])
    result['journal_usage']=r['stdout'].strip()[:180] if 'stdout' in r else r
    return result


def units_survey(runner):
    discovered=[]
    listed=runner.run(['systemctl','list-unit-files','--no-legend','--no-pager','ustar-*','certbot*','logrotate*','smart*','nut-*'])
    for line in listed.get('stdout','').splitlines():
        fields=line.split()
        if fields and re.fullmatch(r'[A-Za-z0-9_.@:-]+\.(?:service|timer|socket|target)',fields[0]):discovered.append(fields[0])
    names=sorted(set(UNITS)|set(discovered[:80]))
    argv=['systemctl','show',*names,*['--property='+p for p in PROPERTIES]]
    r=runner.run(argv,accept_nonzero=True);result=[]
    if 'error' in r:return r
    for block in r['stdout'].strip().split('\n\n'):
        row={}
        for line in block.splitlines():
            k,_,v=line.partition('=')
            if k in PROPERTIES:row[k]=v[:200]
        if row:result.append(row)
    return result if result else {'error':'unit_metadata_unavailable'}


def iptables_public(text):
    result={'chains':[],'rules':[],'matches_omitted_in_rules':[],'restorable_policy':False}
    values={'-s','-d','-i','-o','-p','-j','-g','--dport','--sport','--dports','--sports',
            '--ctstate','--state','--reject-with','--icmp-type','--uid-owner','--gid-owner','--limit','--limit-burst'}
    modules={'state','conntrack','tcp','udp','limit','recent','owner','addrtype','multiport','icmp','icmp6'}
    for line in text.splitlines():
        if line.startswith(':'):
            fields=line[1:].split()
            if len(fields)>=2:result['chains'].append({'chain':fields[0],'policy':fields[1]})
        if not line.startswith('-A '):continue
        try:tokens=shlex.split(line)
        except ValueError:continue
        output=tokens[:2];i=2;omitted=False
        while i<len(tokens):
            flag=tokens[i];i+=1
            if flag in values and i<len(tokens):output.extend((flag,tokens[i]));i+=1
            elif flag=='-m' and i<len(tokens):
                name=tokens[i];i+=1
                if name in modules:output.extend((flag,name))
                elif name!='comment':omitted=True
            elif flag=='--comment':i+=1
            elif flag in ('!','--syn'):output.append(flag)
            else:
                omitted=True
                if i<len(tokens) and not tokens[i].startswith('-'):i+=1
        result['rules'].append(output)
        if omitted:result['matches_omitted_in_rules'].append(len(result['rules'])-1)
        if len(result['rules'])>=300:result['limited']=True;break
    return result


def access_survey(runner,context):
    result={}
    argv=['sshd','-T'];user=os.environ.get('SUDO_USER','root')
    if context and re.fullmatch('[a-z_][a-z0-9_-]{0,31}',user):
        argv+=['-C',','.join(['user='+user,'host='+context['addr']]+[k+'='+v for k,v in context.items()])]
        result['evaluated_context']={**context,'user':user,'host_matches':'numeric IP; reverse-hostname Match not established'}
    else:result['evaluated_context']='global defaults only; Match scope not established'
    r=runner.run(argv);result['sshd']=ssh_public(r['stdout']) if 'stdout' in r else r
    r=runner.run(['ss','-H','-lntup'])
    result['listeners']=r['stdout'].splitlines()[:100] if 'stdout' in r else r
    r=runner.run(['ufw','status','verbose'])
    result['ufw']=[l.split('#',1)[0].strip() for l in r['stdout'].splitlines()[:100]] if 'stdout' in r else r
    nft=runner.parsed(['nft','-j','list','ruleset'])
    if 'error' not in nft:
        chains=[v['chain'] for v in nft.get('nftables',[]) if 'chain' in v]
        result['nft']={'chains':[{k:v[k] for k in ('family','table','name','type','hook','policy') if k in v} for v in chains],
                       'rules':sum('rule' in v for v in nft.get('nftables',[])), 'full_rule_acceptance':'not established by summary'}
    else:result['nft']=nft
    for tool in ('iptables-save','ip6tables-save'):
        r=runner.run([tool])
        result[tool]=iptables_public(r['stdout']) if 'stdout' in r else r
    return result


def console_survey(runner):
    python=Path('/opt/ustar-console/venv/bin/python')
    if not python.exists():return {'error':'console_runtime_absent'}
    for path in (python,*python.parents):
        try:
            for candidate in (path,path.resolve()):
                info=candidate.lstat()
                if info.st_uid!=0 or (not stat.S_ISLNK(info.st_mode) and stat.S_IMODE(info.st_mode)&0o022):
                    return {'error':'untrusted_console_runtime'}
        except OSError:return {'error':'console_runtime_unavailable'}
    packages=list((python.parent.parent/'lib').glob('python*/site-packages/ustar_console'))
    if not packages:return {'error':'console_package_unavailable'}
    entries=0
    for package in packages:
        for directory,dirs,files in os.walk(package,followlinks=False):
            for path in [Path(directory)]+[Path(directory)/n for n in dirs+files]:
                info=path.lstat();entries+=1
                if info.st_uid!=0 or stat.S_ISLNK(info.st_mode) or stat.S_IMODE(info.st_mode)&0o022:
                    return {'error':'untrusted_console_package','blocked_path':str(path)}
                if entries>2000:return {'error':'console_package_limit'}
    result=runner.parsed([str(python),'-I','-B','-c',OBSERVABILITY_DIAGNOSE_SOURCE],timeout=20)
    result['diagnostic_source']='PR103 e5b87818 observability_diagnose.py; installed wheel is reused'
    result['apache_log_files']=[metadata(p) for p in (Path('/var/log/apache2/access.log'),Path('/var/log/apache2/error.log'))]
    return result


def hardware_survey(runner):
    devices=runner.parsed(['lsblk','-J','-d','-o','NAME,PATH,TYPE,SIZE,MODEL,SERIAL,ROTA'])
    if 'error' in devices:return devices
    result=[]
    for item in devices.get('blockdevices',[]):
        if item.get('type')!='disk':continue
        row={k:item.get(k) for k in ('name','path','size','model','serial','rota')}
        path=item.get('path','')
        if re.fullmatch(r'/dev/(?:sd[a-z]+|nvme\d+n\d+|vd[a-z]+)',path):
            data=runner.parsed(['smartctl','-j','-n','standby','-H','-A',path],timeout=15,accept_nonzero=True)
            row['smart']=smart_public(data)
        result.append(row)
    return {'devices':result,'power':'UPS daemon presence is in units; physical power protection requires owner confirmation'}


def backup_survey():
    result={}
    for name in ('last-hdd-success.json','last-hdd-attempt.json','last-hdd-export.json'):
        value=read_json(Path('/var/lib/ustar-backup')/name)
        result[name]=backup_public(value) if isinstance(value,dict) else {'error':'unexpected_manifest_type'}
    result['private_host_state_in_standard_backup']=False
    result['external_clean_host_restore']='waiting_external; accepted archive delivery is not restore proof'
    return result


def postgres_survey(runner,prefix='mdl_'):
    if not re.fullmatch('[a-z][a-z0-9_]{0,30}',prefix):return {'error':'invalid_task_prefix'}
    tables=(prefix+'task_scheduled',prefix+'task_adhoc')
    sql="""BEGIN READ ONLY; SET LOCAL statement_timeout='8s'; SET LOCAL lock_timeout='2s'; SET LOCAL search_path=pg_catalog;
SELECT json_build_object('database_bytes',pg_database_size(current_database()),
 'stats',(SELECT row_to_json(v) FROM (SELECT numbackends,xact_commit,xact_rollback,blks_read,blks_hit,
 temp_bytes,deadlocks,blk_read_time,blk_write_time,stats_reset FROM pg_stat_database WHERE datname=current_database())v),
 'activity',(SELECT json_agg(v) FROM (SELECT state,wait_event_type,count(*) AS n FROM pg_stat_activity
 WHERE datname=current_database() GROUP BY state,wait_event_type)v),
 'task_columns',(SELECT json_agg(v) FROM (SELECT c.relname,a.attname FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
 JOIN pg_attribute a ON a.attrelid=c.oid AND a.attnum>0 AND NOT a.attisdropped WHERE n.nspname='public'
 AND c.relname IN ('mdl_task_scheduled','mdl_task_adhoc') AND a.attname IN ('lastruntime','nextruntime','faildelay','disabled'))v));
ROLLBACK;"""
    sql=sql.replace('mdl_task_scheduled',tables[0]).replace('mdl_task_adhoc',tables[1])
    argv=['docker','exec','-i','ustar_postgres','sh','-c',
          'exec psql -X -w -qAt -P pager=off -v ON_ERROR_STOP=1 -U "${POSTGRES_USER:?}" -d "${POSTGRES_DB:?}"']
    result=runner.parsed(argv,raw=sql.encode(),timeout=15)
    if 'error' in result:return result
    columns={}
    for v in result.pop('task_columns',[]) or []:columns.setdefault(v['relname'],set()).add(v['attname'])
    selects=[]
    for table in tables:
        if {'nextruntime','faildelay'}<=columns.get(table,set()):
            enabled="COALESCE(disabled::text,'0') NOT IN ('1','true','t')" if 'disabled' in columns[table] else 'true'
            selects.append("'"+table+"',(SELECT json_build_object('count',count(*),"+
                           "'enabled',count(*) FILTER(WHERE "+enabled+"),"+
                           "'retry_backoff_enabled',count(*) FILTER(WHERE faildelay>0 AND "+enabled+"),"+
                           "'overdue_enabled_10m',count(*) FILTER(WHERE nextruntime>0 AND nextruntime<extract(epoch from now())-600 AND "+enabled+")) FROM public."+table+")")
    if selects:
        query="BEGIN READ ONLY; SET LOCAL statement_timeout='8s'; SET LOCAL search_path=pg_catalog; SET LOCAL ROLE ustar_app; SELECT json_build_object("+','.join(selects)+"); ROLLBACK;"
        result['tasks']=runner.parsed(argv,raw=query.encode(),timeout=15)
    return result


def status_public(data):
    counts={'exit_code','setup_version','elapsed_seconds','scheduled_failed','scheduled_overdue_10m',
            'adhoc_queued','adhoc_failed','ustar_failed','ustar_error_lines','ustar_warning_lines'}
    flags={'installed','schedule_present','containers_match_installation','ready','active','success'}
    groups={'installation','versions','last_run','last_tick','last_scheduled','last_scheduled_run',
            'last_error','runtime','db','tasks','schedule','last_success','last_attempt','status'}
    result=[]
    def visit(value,context='',depth=0):
        if not isinstance(value,dict) or depth>5 or len(result)>=80:return
        for key,v in value.items():
            accepted=(key in counts and type(v) in (int,float) or key in flags and isinstance(v,bool))
            if key in ('local_ustar','theme_ustar') and isinstance(v,str) and re.fullmatch(r'\d{8,12}',v):accepted=True
            if (key.endswith('_utc') or key.endswith('_at')) and isinstance(v,str) and re.fullmatch(r'\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?(?:Z|[+-]\d\d:\d\d)',v):accepted=True
            if accepted:result.append({'context':context,'field':key,'value':v})
            if isinstance(v,dict):visit(v,key if key in groups else 'other',depth+1)
    visit(data);return result


def cron_status(runner):
    script=Path('/usr/local/sbin/USTAR_CRON_SETUP_20261005.py')
    try:
        for p in (script,*script.parents):
            st=p.lstat()
            if st.st_uid!=0 or stat.S_ISLNK(st.st_mode) or stat.S_IMODE(st.st_mode)&0o022:return {'error':'untrusted_status_helper'}
        if not script.is_file() or script.stat().st_size>8*1024**2:return {'error':'untrusted_status_helper'}
    except OSError:return {'error':'status_helper_absent'}
    value=runner.parsed(['/usr/bin/python3','-I','-B',str(script),'--status'],timeout=35)
    return value if 'error' in value else {'filtered_existing_readonly_status':status_public(value)}


def maintenance_survey(runner):
    result={'cron_schedule':metadata('/etc/cron.d/ustar_moodle'),'cron_status':cron_status(runner),'certificates':[],
            'smtp_delivery':'not tested; requires approved recipient or existing delivery receipt'}
    certs=[Path('/etc/ustar-console/authority/ca.crt')];active=set()
    for conf in Path('/etc/apache2/sites-enabled').glob('ustar*.conf'):
        try:
            if conf.stat().st_size>128*1024:continue
            raw=conf.read_text()
            if not re.search(r'(?im)^\s*ServerName\s+ustar[.]local\s*$',raw):continue
            for name in re.findall(r'(?im)^\s*SSLCertificateFile\s+[\"\']?([^\s\"\']+)',raw):
                if name.startswith('/') and '$' not in name:active.add(name);certs.append(Path(name))
        except (OSError,ValueError):continue
    root=Path('/var/lib/ustar-academy-https')
    if root.is_dir() and not root.is_symlink():
        for directory,dirs,files in os.walk(root,followlinks=False):
            dirs[:]=[d for d in dirs if not (Path(directory)/d).is_symlink()
                     and len((Path(directory)/d).relative_to(root).parts)<=3]
            certs.extend(Path(directory)/n for n in files if n=='server.crt')
            if len(certs)>=8:break
    certs=list(dict.fromkeys(certs))
    for p in certs[:10]:
        if not p.is_file() or p.is_symlink():continue
        r=runner.run(['openssl','x509','-in',str(p),'-noout','-enddate','-fingerprint','-sha256'])
        result['certificates'].append({'path':str(p),'active_academy_vhost':str(p) in active,'public_metadata':r['stdout'].strip()[:300] if 'stdout' in r else r})
    result['certificate_scope']='known CA, matching enabled ustar.local vhost and generation certificates; renewal delivery still needs acceptance'
    return result


def resources():
    result={'cpu_count':os.cpu_count(),'load_average':list(os.getloadavg()),'memory_kib':{}}
    try:
        for line in Path('/proc/meminfo').read_text().splitlines():
            k,_,v=line.partition(':')
            if k in ('MemTotal','MemAvailable','SwapTotal','SwapFree'):result['memory_kib'][k]=int(v.split()[0])
    except (OSError,ValueError):result['memory_error']='unavailable'
    return result


def collect(runner,context=None):
    started=datetime.now(timezone.utc).isoformat();sections={}
    probes=(('permissions',permission_survey),('containers_storage',lambda:{'docker':docker_survey(runner),'storage':storage_survey(runner)}),
      ('access',lambda:access_survey(runner,context)),('backup_restore',backup_survey),
      ('observation',lambda:{'units':units_survey(runner),'independent_host_loss_observer':'not established by a same-host survey',
                            'provider_delivery_tested':False,'console':console_survey(runner)}),('hardware_power',lambda:hardware_survey(runner)),
      ('maintenance',lambda:maintenance_survey(runner)),('performance_acceptance',lambda:{'resources':resources(),
             'postgres':postgres_survey(runner),'active_load_test':False,'domain_security_regression':'reuse accepted CI; not executed on live business data'}))
    for name,probe in probes:
        try:sections[name]=probe()
        except Exception as error:sections[name]={'error':'probe_failed','exception_type':type(error).__name__}
    for row in sections.get('containers_storage',{}).get('docker',{}).values():
        if not isinstance(row,dict):continue
        start=row.pop('_sample_monotonic',None);before=row.get('log_file',{})
        if start is not None and before.get('kind')=='file' and 'size' in before:
            after=metadata(before['path']);elapsed=time.monotonic()-start
            if 'size' in after:
                changed=(after.get('inode')!=before.get('inode') or after['size']<before['size'])
                delta=after['size']-before['size']
                row['log_growth']={'window_seconds':round(elapsed,3),'bytes_change':delta,
                    'rotated_or_truncated':changed,'bytes_per_second':None if changed or elapsed<1 else round(delta/elapsed,3)}
    def has_error(value):
        if isinstance(value,dict):return bool(value.get('error')) or any(has_error(v) for v in value.values())
        if isinstance(value,list):return any(has_error(v) for v in value)
        return False
    partial=has_error(sections)
    return {'schema':1,'collector_sha256':hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
            'host':{'hostname':os.uname().nodename,'kernel':os.uname().release,'architecture':os.uname().machine},
            'phase':'survey_partial' if partial else 'survey_complete','started_utc':started,'finished_utc':datetime.now(timezone.utc).isoformat(),
            'configuration_changes':False,'provider_called':False,'accepted_without_repeating':ACCEPTED,
            'sections':sections,'closure':'evidence collection only; no stage is closed merely by this report'}


def main(argv=None):
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('--collect',action='store_true');p.add_argument('--ssh-context',default='')
    args=p.parse_args(argv)
    if not args.collect:
        print(json.dumps({'phase':'PLAN_ONLY','configuration_changes':False,'provider_called':False}));return 0
    if os.geteuid()!=0:
        print(json.dumps({'phase':'refused','reason':'root_required'}));return 2
    print(json.dumps(collect(Runner(),ssh_context(args.ssh_context)),ensure_ascii=False,sort_keys=True));return 0

if __name__=='__main__':sys.exit(main())
