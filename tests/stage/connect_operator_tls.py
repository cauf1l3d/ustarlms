"""Loopback TLS termination for the isolated Moodle browser-session test."""
from http.client import HTTPConnection
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import ssl
import sys


class Proxy(BaseHTTPRequestHandler):
    def request_moodle(self):
        length = int(self.headers.get('Content-Length', '0'))
        if length > 262144:
            self.send_error(413)
            return
        upstream = HTTPConnection('127.0.0.1', 18089, timeout=20)
        try:
            headers = {k: v for k, v in self.headers.items() if k.lower() not in ('connection', 'transfer-encoding')}
            headers['X-Forwarded-Proto'] = 'https'
            upstream.request(self.command, self.path, self.rfile.read(length) if length else None, headers)
            response = upstream.getresponse()
            body = response.read()
            self.send_response(response.status)
            for key, value in response.getheaders():
                if key.lower() not in ('connection', 'transfer-encoding', 'content-length'):
                    self.send_header(key, value)
            self.send_header('Content-Length', str(len(body)))
            self.end_headers()
            self.wfile.write(body)
        finally:
            upstream.close()

    def log_message(self, *_args):
        pass

    do_GET = request_moodle
    do_POST = request_moodle


if __name__ == '__main__':
    server = ThreadingHTTPServer(('127.0.0.1', 18088), Proxy)
    tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    tls.load_cert_chain(sys.argv[1], sys.argv[2])
    server.socket = tls.wrap_socket(server.socket, server_side=True)
    server.serve_forever()
