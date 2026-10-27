"""Real controlling-terminal regression for the operator password prompt."""
import os
from pathlib import Path
import pty
import select
import subprocess
import sys
import termios
import time
import unittest
import fcntl

ROOT = Path(__file__).resolve().parents[2]
CODE = "import sys; sys.path.insert(0, sys.argv[1]); import postgres_app_handoff as h; value=h.prompt_password(); print('VALUE_OK' if value == 'fixture-secret-42' else 'VALUE_BAD')"

class PasswordTerminal(unittest.TestCase):
    def test_real_terminal_reads_secret_without_echo_and_restores_echo(self):
        master, slave = pty.openpty()
        def terminal():
            os.setsid()
            fcntl.ioctl(slave, termios.TIOCSCTTY, 0)
        proc = subprocess.Popen([sys.executable, '-I', '-B', '-c', CODE, str(ROOT/'scripts/infra')],
                                stdin=slave, stdout=slave, stderr=slave, preexec_fn=terminal)
        output = b''
        sent = False
        deadline = time.monotonic() + 8
        try:
            while time.monotonic() < deadline:
                if select.select([master], [], [], .1)[0]:
                    output += os.read(master, 4096)
                if not sent and b'ustar_app: ' in output:
                    self.assertFalse(termios.tcgetattr(slave)[3] & termios.ECHO)
                    os.write(master, b'fixture-secret-42\n')
                    sent = True
                if proc.poll() is not None:
                    while select.select([master], [], [], 0)[0]:
                        output += os.read(master, 4096)
                    break
            self.assertEqual(proc.poll(), 0, output.decode(errors='replace'))
            self.assertTrue(sent)
            self.assertIn(b'VALUE_OK', output)
            self.assertNotIn(b'fixture-secret-42', output)
            self.assertTrue(termios.tcgetattr(slave)[3] & termios.ECHO)
        finally:
            if proc.poll() is None: proc.kill()
            proc.wait()
            os.close(master)
            os.close(slave)

    def test_no_controlling_terminal_refuses_without_reading_piped_secret(self):
        result = subprocess.run([sys.executable, '-I', '-B', '-c', CODE, str(ROOT/'scripts/infra')],
                                input=b'fixture-secret-42\n', capture_output=True,
                                start_new_session=True, timeout=5)
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn(b'VALUE_OK', result.stdout)
        self.assertNotIn(b'fixture-secret-42', result.stdout + result.stderr)

if __name__ == '__main__': unittest.main()
