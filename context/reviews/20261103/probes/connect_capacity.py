"""Actual receiver Journal on private synthetic SQLite; no credentials/network/server access."""
from pathlib import Path
import sys, tempfile, json
root=Path(__file__).resolve().parents[4]
sys.path.insert(0,str(root/'connect-worker'))
from ustar_connect.receiver_journal import Journal, JournalError
now=2000000000
with tempfile.TemporaryDirectory() as directory:
    journal=Journal(Path(directory), '12345', clock=lambda:now)
    try:
        with journal._db:
            journal._db.executemany("INSERT INTO events(update_id,kind,state,createdat) VALUES(?,'bot','sent',?)",[(i,now-60) for i in range(1,10001)])
        journal.prepare_poll()
        before=journal.health()['states']
        try: journal.begin(10001,None,'bot')
        except JournalError as error: failure=str(error)
        else: raise AssertionError('Expected journal_full')
        assert failure=='journal_full'
        now+=604801
        journal.prepare_poll()
        after=journal.health()['states']
        assert journal.begin(10001,None,'bot') is True
        print(json.dumps({'scope':'actual Journal SQLite on synthetic 10000 terminal events; runtime exit behavior inspected, not executed','before_7_days':before,'new_event':failure,'after_7_days_one_cleanup':after,'new_event_after_cleanup':'accepted','service_contract':'JournalError -> exit4; RestartPreventExitStatus includes4'},indent=2))
    finally: journal.close()
