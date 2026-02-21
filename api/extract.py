import json
import os
import re
from http.server import BaseHTTPRequestHandler
from urllib.parse import urlparse, urlunparse
from urllib.request import Request, urlopen
import html
import tempfile
import time

from yt_dlp import YoutubeDL

API_TOKEN = os.environ.get("API_TOKEN", "")
SUPPORTED_HOSTS = ("instagram.com", "facebook.com", "fb.watch")
INSTAGRAM_SESSIONID = os.environ.get("INSTAGRAM_SESSIONID", "").strip()
INSTAGRAM_COOKIES = os.environ.get("INSTAGRAM_COOKIES", "").strip()


def sanitize_filename(title: str, ext: str) -> str:
    safe = re.sub(r"[^a-zA-Z0-9._-]+", "_", title.strip())
    safe = safe.strip("._") or "reel"
    ext = (ext or "mp4").strip(".")[:5] or "mp4"
    return f"{safe}.{ext}"


def build_cookie_header() -> str:
    if INSTAGRAM_COOKIES:
        return INSTAGRAM_COOKIES
    if INSTAGRAM_SESSIONID:
        return f"sessionid={INSTAGRAM_SESSIONID}"
    return ""


def build_cookiefile() -> str:
    cookie_header = build_cookie_header()
    if not cookie_header:
        return ""

    entries: list[tuple[str, str]] = []
    for chunk in cookie_header.split(";"):
        part = chunk.strip()
        if "=" not in part:
            continue
        name, value = part.split("=", 1)
        name = name.strip()
        value = value.strip()
        if not name or not value:
            continue
        entries.append((name, value))

    if not entries:
        return ""

    expires = str(int(time.time()) + 30 * 24 * 60 * 60)
    lines = ["# Netscape HTTP Cookie File"]
    for name, value in entries:
        lines.append(
            "\t".join(
                [".instagram.com", "TRUE", "/", "TRUE", expires, name, value]
            )
        )

    handle = tempfile.NamedTemporaryFile(
        mode="w", prefix="ig_cookie_", suffix=".txt", delete=False
    )
    with handle:
        handle.write("\n".join(lines) + "\n")
    return handle.name


def decode_escaped_url(value: str) -> str:
    value = html.unescape(value.strip())
    value = value.replace("\\/", "/").replace("\\u0026", "&")
    if value.startswith("//"):
        value = "https:" + value
    return value


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
            add(f"https://www.instagram.com/{match.group(1)}/{match.group(2)}/embed/captioned/")

    return candidates


def fetch_html(url: str, cookie_header: str = "") -> str:
    headers = {
        "User-Agent": (
            "Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) "
            "AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 "
            "Mobile/15E148 Safari/604.1"
        ),
        "Accept-Language": "en-US,en;q=0.9",
        "Referer": "https://www.instagram.com/",
    }
    if cookie_header:
        headers["Cookie"] = cookie_header

    request = Request(
        url,
        headers=headers,
    )
    with urlopen(request, timeout=25) as response:
        return response.read().decode("utf-8", errors="ignore")


def extract_from_public_html(url: str, cookie_header: str = "") -> dict | None:
    page = fetch_html(url, cookie_header)
    media_patterns = [
        r'<meta[^>]+property=["\']og:video(?::secure_url)?["\'][^>]+content=["\']([^"\']+)["\']',
        r'"video_url":"(https:[^"]+)"',
        r'"playback_url":"(https:[^"]+)"',
    ]

    media_url = ""
    for pattern in media_patterns:
        match = re.search(pattern, page, flags=re.IGNORECASE)
        if match:
            media_url = decode_escaped_url(match.group(1))
            break

    if not media_url.startswith("http"):
        return None

    title = "reel"
    title_match = re.search(
        r'<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']',
        page,
        flags=re.IGNORECASE,
    )
    if title_match:
        title = html.unescape(title_match.group(1)).strip() or "reel"

    ext = "mp4"
    path = urlparse(media_url).path
    guessed_ext = (path.rsplit(".", 1)[-1].lower() if "." in path else "").strip()
    if guessed_ext and len(guessed_ext) <= 5:
        ext = guessed_ext

    return {"url": media_url, "title": title, "ext": ext}


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
        cookie_header = build_cookie_header()
        cookiefile = build_cookiefile()
        if cookie_header:
            ydl_opts["http_headers"]["Cookie"] = cookie_header
        if cookiefile:
            ydl_opts["cookiefile"] = cookiefile

        info = None
        errors: list[str] = []
        for candidate_url in build_candidate_urls(url):
            try:
                with YoutubeDL(ydl_opts) as ydl:
                    info = ydl.extract_info(candidate_url, download=False)
                if isinstance(info, dict):
                    break
            except Exception as exc:
                errors.append(f"yt-dlp: {str(exc)}")

        if not isinstance(info, dict):
            for candidate_url in build_candidate_urls(url):
                try:
                    info = extract_from_public_html(candidate_url, cookie_header)
                    if isinstance(info, dict):
                        break
                except Exception as exc:
                    errors.append(f"html: {str(exc)}")

        if not isinstance(info, dict):
            payload = {
                "error": "Video extract failed. Reel may be restricted or rate-limited."
            }
            combined = " | ".join(errors).lower()
            if (
                ("login required" in combined or "rate-limit" in combined)
                and not cookie_header
            ):
                payload["hint"] = (
                    "Set INSTAGRAM_SESSIONID (or INSTAGRAM_COOKIES) in Vercel "
                    "Environment Variables, then redeploy."
                )
            if errors:
                payload["detail"] = " | ".join(errors)[:320]
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
