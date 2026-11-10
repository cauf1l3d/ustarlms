"""The native HTTP smoke must send curl's session cookie, never accept a login page."""
import importlib.util
from pathlib import Path
import tempfile
import unittest
from urllib.request import Request

MODULE=Path(__file__).resolve().parents[1]/'stage'/'route_editor_http.py'
spec=importlib.util.spec_from_file_location('route_editor_http',MODULE)
http=importlib.util.module_from_spec(spec)
spec.loader.exec_module(http)

class RouteEditorCookieTest(unittest.TestCase):
    def test_curl_session_cookie_is_sent_to_local_native_server(self):
        with tempfile.TemporaryDirectory() as directory:
            path=Path(directory)/'cookies.txt'
            path.write_text('# Netscape HTTP Cookie File\n#HttpOnly_127.0.0.1\tFALSE\t/\tFALSE\t0\tMoodleSession\tsynthetic\n')
            request=Request('http://127.0.0.1:8087/local/ustar/route_studio.php')
            http.load_curl_cookie_jar(path).add_cookie_header(request)
            self.assertEqual('MoodleSession=synthetic',request.get_header('Cookie'))

    def test_real_expired_cookie_is_not_revived(self):
        with tempfile.TemporaryDirectory() as directory:
            path=Path(directory)/'cookies.txt'
            path.write_text('# Netscape HTTP Cookie File\n127.0.0.1\tFALSE\t/\tFALSE\t1\tExpired\tsynthetic\n')
            request=Request('http://127.0.0.1:8087/local/ustar/route_studio.php')
            http.load_curl_cookie_jar(path).add_cookie_header(request)
            self.assertIsNone(request.get_header('Cookie'))
