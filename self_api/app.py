import os
import re
from urllib.parse import urlparse

from flask import Flask, jsonify, request
from yt_dlp import YoutubeDL

app = Flask(__name__)

API_TOKEN = os.getenv("API_TOKEN", "")
SUPPORTED_HOSTS = ("instagram.com", "facebook.com", "fb.watch")


def error(message: str, status: int = 400):
    response = jsonify({"error": message})
    response.status_code = status
    return response


def is_supported_url(url: str) -> bool:
    try:
        host = (urlparse(url).hostname or "").lower()
    except Exception:
        return False
    return any(h in host for h in SUPPORTED_HOSTS)


def sanitize_filename(title: str, ext: str) -> str:
    safe = re.sub(r"[^a-zA-Z0-9._-]+", "_", title.strip())
    safe = safe.strip("._") or "reel"
    ext = (ext or "mp4").strip(".")[:5] or "mp4"
    return f"{safe}.{ext}"


@app.get("/health")
def health():
    return jsonify({"ok": True})


@app.post("/extract")
def extract():
    auth = request.headers.get("Authorization", "")
    expected = f"Bearer {API_TOKEN}"
    if not API_TOKEN:
        return error("API_TOKEN server par set nahi hai.", 500)
    if auth != expected:
        return error("Unauthorized", 401)

    payload = request.get_json(silent=True) or {}
    url = str(payload.get("url", "")).strip()

    if not url:
        return error("URL required hai.", 400)
    if not is_supported_url(url):
        return error("Sirf Instagram/Facebook URLs supported hain.", 400)

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
        return error("Video extract fail ho gaya. Reel private ya unavailable ho sakti hai.", 422)

    if not isinstance(info, dict):
        return error("Extractor response invalid hai.", 422)

    media_url = info.get("url")
    if not isinstance(media_url, str) or not media_url.startswith("http"):
        return error("Downloadable media URL nahi mila.", 422)

    title = str(info.get("title") or "reel")
    ext = str(info.get("ext") or "mp4")
    filename = sanitize_filename(title, ext)

    return jsonify({
        "ok": True,
        "media_url": media_url,
        "filename": filename,
        "source": url,
    })


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=int(os.getenv("PORT", "8000")))
