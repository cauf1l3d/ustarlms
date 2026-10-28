#!/usr/bin/env python3
"""Bounded passive Academy observation. Default is offline PLAN_ONLY; no application HTTP."""
import argparse
from collections import Counter
from datetime import datetime, timezone
import hashlib
import json
import math
import os
from pathlib import Path
import re
import selectors
import signal
import stat
import subprocess
import time

LOG_BUDGET = 1024 * 1024
REPORT_BUDGET = 32 * 1024 * 1024
COMMAND_BUDGET = 32768
CODE = Path('/opt/ustar/data/moodle/public/public')
ACCESS = Path('/var/lib/ustar-console-collector/requests.log')
COUNTERS = ('numbackends','xact_commit','xact_rollback','blks_read','blks_hit','temp_files','temp_bytes','deadlocks')
CRON = ('raw_scheduled_overdue_10m','scheduled_failed','adhoc_queued','adhoc_failed','latest_task_epoch')


def number(value, nullable=False):
    if value is None and nullable:
        return None
    if type(value) not in (int,float) or not math.isfinite(value) or not 0 <= value <= 2**63-1:
        raise ValueError('invalid_numeric_source')
    return value


def validate_timing(duration, interval):
    if type(duration) is not int or type(interval) is not int or not 60 <= interval <= duration <= 86400:
        raise ValueError('invalid_timing')


def regular_read(path, limit, owner=0):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_uid != owner or info.st_mode & 0o022 or info.st_size > limit:
            raise ValueError('invalid_source_file')
        raw = os.read(fd, limit+1)
        if len(raw) > limit:
            raise ValueError('source_limit')
        return raw
    finally:
        os.close(fd)


def command(argv, raw=b'', timeout=8):
    process = None
    try:
        process = subprocess.Popen(argv, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
            start_new_session=True, env={'PATH':'/usr/sbin:/usr/bin:/sbin:/bin','LC_ALL':'C','LANG':'C'})
        process.stdin.write(raw); process.stdin.close()
        out = bytearray(); deadline = time.monotonic()+timeout
        with selectors.DefaultSelector() as selector:
            selector.register(process.stdout,selectors.EVENT_READ)
            while selector.get_map():
                left = deadline-time.monotonic()
                if left <= 0:
                    return {'error':'timeout'}
                if not selector.select(left):
                    return {'error':'timeout'}
                chunk = os.read(process.stdout.fileno(),4096)
                if not chunk:
                    selector.unregister(process.stdout); break
                out.extend(chunk)
                if len(out) > COMMAND_BUDGET:
                    return {'error':'output_limit'}
        left = deadline-time.monotonic()
        if left <= 0:
            return {'error':'timeout'}
        if process.wait(timeout=left):
            return {'error':'command_failed'}
        return {'stdout':bytes(out)}
    except subprocess.TimeoutExpired:
        return {'error':'timeout'}
    except (OSError,ValueError):
        return {'error':'command_unavailable'}
    finally:
        if process is not None:
            if process.poll() is None:
                try: os.killpg(process.pid,signal.SIGKILL)
                except ProcessLookupError: pass
            process.wait()
            if process.stdout: process.stdout.close()
            if process.stdin and not process.stdin.closed: process.stdin.close()


class NumericLog:
    """Read each new numeric record once, never export request paths, addresses or raw lines."""
    def __init__(self, path=ACCESS, owner=0):
        self.path=Path(path); self.owner=owner; self.identity=None; self.offset=0; self.pending=b''; self.started=None

    def sample(self, now):
        fd=None
        try:
            fd=os.open(self.path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
            info=os.fstat(fd)
            if not stat.S_ISREG(info.st_mode) or info.st_uid != self.owner or info.st_mode & 0o022:
                raise ValueError('invalid_numeric_file')
            identity=(info.st_dev,info.st_ino)
            if self.identity is None:
                self.identity=identity; self.offset=info.st_size; self.started=now
                return {'status':'no_data','reason':'starting_now','coverage_gap':True}
            gap=identity!=self.identity or info.st_size<self.offset
            if gap:
                self.offset=0; self.pending=b''; self.identity=identity
            if info.st_size-self.offset > LOG_BUDGET:
                self.offset=info.st_size;self.pending=b''
                return {'status':'no_data','reason':'source_limit','coverage_gap':True}
            os.lseek(fd,self.offset,os.SEEK_SET)
            raw=os.read(fd,min(LOG_BUDGET,info.st_size-self.offset));self.offset+=len(raw)
            lines=(self.pending+raw).split(b'\n'); self.pending=lines.pop()
            if len(self.pending)>256:
                self.pending=b'';raise ValueError('invalid_numeric_record')
            histogram=Counter();errors4=errors5=0
            for line in lines:
                match=re.fullmatch(rb'([0-9]{1,12}) ([1-5][0-9]{2}) ([0-9]{1,9})',line)
                if not match:
                    raise ValueError('invalid_numeric_record')
                at,statuscode,duration=map(int,match.groups())
                if at<self.started or at>now+5 or duration>600_000_000:
                    raise ValueError('invalid_numeric_record')
                histogram[(duration+999)//1000]+=1
                errors4+=400<=statuscode<500;errors5+=statuscode>=500
            if not histogram:
                return {'status':'no_data','reason':'no_new_requests','coverage_gap':gap}
            return {'status':'ok','histogram':sorted([k,v] for k,v in histogram.items()),
                    'errors_4xx':errors4,'errors_5xx':errors5,'coverage_gap':gap}
        except (OSError,ValueError):
            self.pending=b''
            return {'status':'no_data','reason':'source_unavailable_or_invalid','coverage_gap':True}
        finally:
            if fd is not None:os.close(fd)


def database_sql(prefix):
    if not isinstance(prefix,str) or not re.fullmatch(r'[a-zA-Z][a-zA-Z0-9_]{0,39}_',prefix):
        raise ValueError('invalid_prefix')
    return "BEGIN READ ONLY; SET LOCAL statement_timeout='2500ms'; SET LOCAL lock_timeout='500ms';\n" + f"""
SELECT json_build_object(
 'local_ustar',(SELECT value FROM {prefix}config_plugins WHERE plugin='local_ustar' AND name='version'),
 'theme_ustar',(SELECT value FROM {prefix}config_plugins WHERE plugin='theme_ustar' AND name='version'),
 'server_started_at',EXTRACT(EPOCH FROM pg_postmaster_start_time()),
 'stats_reset',EXTRACT(EPOCH FROM d.stats_reset),
 'counters',json_build_object({','.join(repr(k)+',d.'+k for k in COUNTERS)}),
 'cron',json_build_object(
   'raw_scheduled_overdue_10m',(SELECT count(*) FROM {prefix}task_scheduled WHERE disabled=0 AND nextruntime<EXTRACT(EPOCH FROM now())-600),
   'scheduled_failed',(SELECT count(*) FROM {prefix}task_scheduled WHERE disabled=0 AND faildelay>0),
   'adhoc_queued',(SELECT count(*) FROM {prefix}task_adhoc),
   'adhoc_failed',(SELECT count(*) FROM {prefix}task_adhoc WHERE faildelay>0),
   'latest_task_epoch',(SELECT max(timeend) FROM {prefix}task_log)),
 'pg_stat_statements_installed',EXISTS(SELECT 1 FROM pg_extension WHERE extname='pg_stat_statements'))
FROM pg_stat_database d WHERE d.datname=current_database();
ROLLBACK;
"""


def database_shape(value):
    if not isinstance(value,dict) or str(value.get('local_ustar')) not in ('2026102802','2026102803') \
            or str(value.get('theme_ustar'))!='2026102700':
        raise ValueError('unexpected_application_version')
    counters={k:number(v) for k,v in value['counters'].items() if k in COUNTERS}
    cron={k:number(v,nullable=k=='latest_task_epoch') for k,v in value['cron'].items() if k in CRON}
    return {'status':'ok','epoch':[number(value['server_started_at']),number(value['stats_reset'],nullable=True),
                                  str(value['local_ustar'])],
            'versions':{'local_ustar':str(value['local_ustar']),'theme_ustar':'2026102700'},
            'counters':counters,'cron':cron,
            'pg_stat_statements_installed':value.get('pg_stat_statements_installed') is True}


def database_sample(prefix):
    shell="export PGOPTIONS='-c default_transaction_read_only=on -c statement_timeout=2500 -c lock_timeout=500'; " \
          'exec psql -X -w -qAt -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB"'
    result=command(['/usr/bin/docker','exec','-i','ustar_postgres','sh','-c',shell],database_sql(prefix).encode())
    if 'error' in result:
        return {'status':'no_data','reason':result['error']}
    try:return database_shape(json.loads(result['stdout']))
    except (ValueError,KeyError,TypeError):return {'status':'no_data','reason':'invalid_database_source'}


def host_sample():
    try:
        memory=dict(re.findall(r'^(MemTotal|MemAvailable|SwapTotal|SwapFree):\s+(\d+) kB$',
                               Path('/proc/meminfo').read_text(),re.M))
        cpu=list(map(int,Path('/proc/stat').read_text().splitlines()[0].split()[1:]))
        return {'status':'ok','load_average':list(os.getloadavg()),
                'memory_kib':{k:int(v) for k,v in memory.items()},'cpu_jiffies':cpu}
    except (OSError,ValueError):return {'status':'no_data','reason':'host_source_unavailable'}


def summarize(rows, interval):
    histogram=Counter();errors4=errors5=0;gaps=0;db=[]
    for row in rows:
        number(row['at'])
        web=row.get('web',{})
        gaps+=web.get('status')!='ok' or bool(web.get('coverage_gap'))
        if web.get('status')=='ok':
            for ms,count in web['histogram']:
                if type(ms) is not int or not 0<=ms<=600000 or type(count) is not int or not 1<=count<=10**8:
                    raise ValueError('invalid_histogram')
                histogram[ms]+=count
            errors4+=number(web.get('errors_4xx',0));errors5+=number(web.get('errors_5xx',0))
    total=sum(histogram.values())
    def percentile(q):
        if not total:return None
        rank=math.ceil(q*total);accumulated=0
        for ms,count in sorted(histogram.items()):
            accumulated+=count
            if accumulated>=rank:return ms
    for before,after in zip(rows,rows[1:]):
        a=before.get('database',{});b=after.get('database',{});elapsed=after['at']-before['at']
        good=a.get('status')==b.get('status')=='ok' and a['epoch']==b['epoch'] and 0<elapsed<=interval*2
        rates=None
        if good:
            common=(a['counters'].keys() & b['counters'].keys())-{'numbackends'}
            good=all(number(b['counters'][k])>=number(a['counters'][k]) for k in common)
            if good:rates={k:(b['counters'][k]-a['counters'][k])/elapsed for k in sorted(common)}
        db.append({'start':before['at'],'end':after['at'],'rates_per_second':rates,
                   'reason':'same_epoch' if rates is not None else 'gap_or_epoch_change_or_counter_reset'})
    return {'schema':1,'samples':len(rows),'web':{'status':'ok' if total else 'no_data',
            'requests':total if total else None,'p50_ms':percentile(.5),'p95_ms':percentile(.95),'p99_ms':percentile(.99),
            'errors_4xx':errors4 if total else None,'errors_5xx':errors5 if total else None,
            'gap_samples':gaps,'resolution_ms':1,
            'scope':'Apache processing including assets; not authenticated page latency'},
            'database_intervals':db,'database_observer_transactions_included':True,
            'workday_SLO_accepted':False,
            'not_measured':['authenticated_page_timings','SQL_per_page','Web_SAPI_OPcache','MUC_sessions',
                            'effective_component_task_policy','agreed_concurrency_SLO']}


def private_output(path):
    path=Path(path)
    if not path.is_absolute() or '..' in path.parts:
        raise ValueError('invalid_output_path')
    for parent in [*reversed(path.parents)]:
        info=parent.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid!=0 or info.st_mode&0o022:
            raise ValueError('unsafe_output_parent')
    try:path.mkdir(mode=0o700)
    except FileExistsError:raise ValueError('output_exists') from None
    info=path.lstat()
    if info.st_uid!=0 or stat.S_IMODE(info.st_mode)!=0o700:
        raise ValueError('unsafe_output_directory')


def collect(duration, interval, output):
    validate_timing(duration,interval)
    if os.geteuid()!=0:raise ValueError('root_required')
    # Read only the literal prefix; never execute Moodle/config.php or export its content.
    config=regular_read(CODE/'config.php',131072).decode()
    match=re.search(r"\$CFG->prefix\s*=\s*(['\"])([a-zA-Z][a-zA-Z0-9_]{0,39}_)\1\s*;",config)
    if not match:raise ValueError('prefix_unavailable')
    prefix=match[2]
    initial=database_sample(prefix)
    if initial['status']!='ok':raise ValueError('initial_database_unavailable')
    private_output(output);os.umask(0o077)
    output=Path(output);rows=[];size=0;log=NumericLog()
    started=time.monotonic();deadline=started+duration;completed=False
    try:
        with (output/'samples.jsonl').open('xb') as stream:
            while True:
                at=time.time()
                row={'at':at,'host':host_sample(),'database':initial if not rows else database_sample(prefix),
                     'web':log.sample(at)}
                raw=(json.dumps(row,sort_keys=True,allow_nan=False)+'\n').encode();size+=len(raw)
                if size>REPORT_BUDGET:raise ValueError('report_limit')
                stream.write(raw);stream.flush();os.fsync(stream.fileno());rows.append(row)
                if time.monotonic()>=deadline:completed=True;break
                next_at=min(deadline,started+len(rows)*interval)
                time.sleep(max(0,next_at-time.monotonic()))
    finally:
        summary=summarize(rows,interval)
        summary.update(requested_duration_seconds=duration,completed_requested_duration=completed,
                       observed_duration_seconds=time.monotonic()-started)
        raw=(json.dumps(summary,indent=2,sort_keys=True,allow_nan=False)+'\n').encode()
        with (output/'summary.json').open('xb') as stream:stream.write(raw)
        hashes=''.join(hashlib.sha256((output/n).read_bytes()).hexdigest()+'  '+n+'\n'
                       for n in ('samples.jsonl','summary.json'))
        with (output/'SHA256SUMS').open('x') as stream:stream.write(hashes)
    return {'phase':'observation_complete','samples':len(rows),'completed_requested_duration':completed}


def main(argv=None):
    p=argparse.ArgumentParser(description=__doc__);g=p.add_mutually_exclusive_group()
    g.add_argument('--collect',action='store_true');g.add_argument('--summarize',type=Path)
    p.add_argument('--duration-seconds',type=int,default=28800);p.add_argument('--interval-seconds',type=int,default=60)
    p.add_argument('--output-dir',type=Path)
    args=p.parse_args(argv)
    if not args.collect and args.summarize is None:
        print(json.dumps({'phase':'PLAN_ONLY','host_access':False,'network_used':False}));return 0
    try:
        if args.collect:
            if args.output_dir is None:p.error('--output-dir required')
            result=collect(args.duration_seconds,args.interval_seconds,args.output_dir)
        else:
            raw=regular_read(args.summarize,REPORT_BUDGET)
            rows=[json.loads(line) for line in raw.splitlines()]
            if len(rows)>1441:raise ValueError('report_limit')
            result=summarize(rows,args.interval_seconds)
        print(json.dumps(result,sort_keys=True,allow_nan=False));return 0
    except (OSError,ValueError,KeyError,TypeError,KeyboardInterrupt):
        print(json.dumps({'phase':'refused','reason':'observation_incomplete_or_source_invalid'}));return 2


if __name__=='__main__':raise SystemExit(main())
