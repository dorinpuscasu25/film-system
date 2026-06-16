#!/usr/bin/env python3
"""
Local test server for the FILMOTECA TV web app.

Serves the static files AND proxies /api/v1/* to the real backend so the browser
sees same-origin requests (no CORS). This lets you test on a TV over the LAN
without touching the production backend's CORS settings.

Usage:
    python3 serve.py            # http://<this-machine-ip>:8080
    python3 serve.py 9000       # custom port
"""
import sys
import urllib.request
import urllib.error
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer

API_TARGET = "https://filmmd-api.veezify.com"   # path (/api/v1/...) is appended
PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8080


class Handler(SimpleHTTPRequestHandler):
    def end_headers(self):
        # Never let the TV browser cache the app files (so reloads pick up edits).
        self.send_header("Cache-Control", "no-store, no-cache, must-revalidate")
        super().end_headers()

    def _is_api(self):
        return self.path.startswith("/api/")

    def do_OPTIONS(self):
        # Same-origin requests don't need this, but be permissive just in case.
        self.send_response(204)
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, PUT, PATCH, DELETE, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type, Authorization, Accept")
        self.end_headers()

    def do_GET(self):
        if self._is_api():
            return self._proxy("GET")
        return super().do_GET()

    def do_POST(self):
        return self._proxy("POST") if self._is_api() else self._not_found()

    def do_PUT(self):
        return self._proxy("PUT") if self._is_api() else self._not_found()

    def do_PATCH(self):
        return self._proxy("PATCH") if self._is_api() else self._not_found()

    def do_DELETE(self):
        return self._proxy("DELETE") if self._is_api() else self._not_found()

    def _not_found(self):
        self.send_error(404)

    def _proxy(self, method):
        target = API_TARGET + self.path
        length = int(self.headers.get("Content-Length", 0) or 0)
        body = self.rfile.read(length) if length else None

        req = urllib.request.Request(target, data=body, method=method)
        for h in ("Authorization", "Content-Type", "Accept"):
            if self.headers.get(h):
                req.add_header(h, self.headers.get(h))
        # Cloudflare blocks the default python-urllib UA (error 1010); look like a browser.
        req.add_header(
            "User-Agent",
            self.headers.get("User-Agent")
            or "Mozilla/5.0 (SMART-TV; Linux; Tizen) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36",
        )
        req.add_header("Origin", "https://film.veezify.com")
        req.add_header("Referer", "https://film.veezify.com/")

        try:
            with urllib.request.urlopen(req, timeout=30) as resp:
                self._relay(resp.status, resp.headers.get("Content-Type"), resp.read())
        except urllib.error.HTTPError as e:
            # Forward the API's error status + body (e.g. 202 pending, 403, 410).
            self._relay(e.code, e.headers.get("Content-Type"), e.read())
        except Exception as e:  # noqa: BLE001
            self._relay(502, "application/json",
                        ('{"error":"proxy_failed","message":"%s"}' % e).encode())

    def _relay(self, status, content_type, payload):
        self.send_response(status)
        self.send_header("Content-Type", content_type or "application/json")
        self.send_header("Content-Length", str(len(payload)))
        self.send_header("Access-Control-Allow-Origin", "*")
        self.end_headers()
        self.wfile.write(payload)

    def log_message(self, fmt, *args):
        sys.stderr.write("%s\n" % (fmt % args))


if __name__ == "__main__":
    httpd = ThreadingHTTPServer(("0.0.0.0", PORT), Handler)
    print("FILMOTECA TV web — serving on http://0.0.0.0:%d  (API proxied to %s)" % (PORT, API_TARGET))
    httpd.serve_forever()
