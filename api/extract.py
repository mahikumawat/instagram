import json
import os
import re
from http.server import BaseHTTPRequestHandler
from urllib.parse import urlparse, urlunparse

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


def build_candidate_urls(url: str) -> list[str]:
    candidates: list[str] = []

    def add(value: str) -> None:
        value = value.strip()
        if value and value not in candidates:
            candidates.append(value)

    add(url)
    parsed = urlparse(url)
    host = (parsed.hostname or "").lower()

    # Retry once without tracking query/hash from share links.
    if parsed.scheme and parsed.netloc:
        add(urlunparse((parsed.scheme, parsed.netloc, parsed.path, "", "", "")))

    # Canonicalize Instagram reel URLs to reduce extractor failures.
    if "instagram.com" in host:
        match = re.search(r"/(reel|reels|p)/([A-Za-z0-9_-]+)", parsed.path or "")
        if match:
            add(f"https://www.instagram.com/{match.group(1)}/{match.group(2)}/")

    return candidates


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
            "http_headers": {
                "User-Agent": (
                    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
                    "AppleWebKit/537.36 (KHTML, like Gecko) "
                    "Chrome/124.0.0.0 Safari/537.36"
                ),
                "Referer": "https://www.instagram.com/",
            },
        }

        info = None
        last_error = ""
        for candidate_url in build_candidate_urls(url):
            try:
                with YoutubeDL(ydl_opts) as ydl:
                    info = ydl.extract_info(candidate_url, download=False)
                if isinstance(info, dict):
                    break
            except Exception as exc:
                last_error = str(exc)

        if not isinstance(info, dict):
            payload = {
                "error": "Video extract failed. Reel may be restricted or rate-limited."
            }
            if last_error:
                payload["detail"] = last_error[:220]
            return self._send(422, payload)

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
