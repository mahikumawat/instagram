import json
import os
import re
from http.server import BaseHTTPRequestHandler
from urllib.parse import urlparse

from yt_dlp import YoutubeDL

API_TOKEN = os.environ.get("API_TOKEN", "")
SUPPORTED_HOSTS = ("instagram.com", "facebook.com", "fb.watch")


def sanitize_filename(title: str, ext: str) -> str:
    safe = re.sub(r"[^a-zA-Z0-9._-]+", "_", title.strip())
    safe = safe.strip("._") or "reel"
    ext = (ext or "mp4").strip(".")[:5] or "mp4"
    return f"{safe}.{ext}"


def is_supported_url(url: str) -> bool:
    try:
        host = (urlparse(url).hostname or "").lower()
    except Exception:
        return False
    return any(h in host for h in SUPPORTED_HOSTS)


class handler(BaseHTTPRequestHandler):
    def _send(self, status_code: int, payload: dict) -> None:
        self.send_response(status_code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.end_headers()
        self.wfile.write(
            json.dumps(payload, ensure_ascii=False).encode("utf-8")
        )

    def do_GET(self):
        if self.path == "/api/extract":
            return self._send(200, {"ok": True, "service": "reel-extractor"})
        return self._send(404, {"error": "Not found"})

    def do_POST(self):
        if self.path != "/api/extract":
            return self._send(404, {"error": "Not found"})

        auth = self.headers.get("Authorization", "")
        if not API_TOKEN:
            return self._send(500, {"error": "API_TOKEN missing on server"})
        if auth != f"Bearer {API_TOKEN}":
            return self._send(401, {"error": "Unauthorized"})

        length = int(self.headers.get("Content-Length", "0"))
        raw = self.rfile.read(length).decode("utf-8") if length > 0 else "{}"
        try:
            body = json.loads(raw)
        except Exception:
            return self._send(400, {"error": "Invalid JSON body"})

        url = str(body.get("url", "")).strip()
        if not url:
            return self._send(400, {"error": "URL required"})
        if not is_supported_url(url):
            return self._send(
                400, {"error": "Only Instagram/Facebook URLs supported"}
            )

        ydl_opts = {
            "quiet": True,
            "no_warnings": True,
            "skip_download": True,
            "nocheckcertificate": False,
            "noplaylist": True,
        }

        try:
            with YoutubeDL(ydl_opts) as ydl:
                info = ydl.extract_info(url, download=False)
        except Exception:
            return self._send(
                422,
                {
                    "error": (
                        "Video extract failed. Reel may be private or unavailable."
                    )
                },
            )

        if not isinstance(info, dict):
            return self._send(422, {"error": "Extractor response invalid"})

        media_url = info.get("url")
        if not isinstance(media_url, str) or not media_url.startswith("http"):
            return self._send(422, {"error": "No downloadable media URL found"})

        title = str(info.get("title") or "reel")
        ext = str(info.get("ext") or "mp4")
        filename = sanitize_filename(title, ext)

        return self._send(
            200,
            {
                "ok": True,
                "media_url": media_url,
                "filename": filename,
                "source": url,
            },
        )
