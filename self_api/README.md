# Self Downloader API (Your Own)

This API lets you extract direct media URL from Instagram/Facebook reels using `yt-dlp`.

## 1) Local run

```bash
cd self_api
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
export API_TOKEN='put-a-long-random-token-here'
python app.py
```

Health check: `GET /health`

## 2) Docker run

```bash
cd self_api
docker build -t reel-api .
docker run -p 8000:8000 -e API_TOKEN='put-a-long-random-token-here' reel-api
```

## 3) API usage

Endpoint: `POST /extract`

Headers:
- `Authorization: Bearer <API_TOKEN>`
- `Content-Type: application/json`

Body:

```json
{
  "url": "https://www.instagram.com/reel/xxxx"
}
```

Success response:

```json
{
  "ok": true,
  "media_url": "https://...",
  "filename": "reel_name.mp4",
  "source": "https://www.instagram.com/reel/xxxx"
}
```

## 4) Connect with PHP frontend

Update `/config.php`:

```php
define('DOWNLOADER_API_URL', 'https://your-api-domain.com/extract');
define('DOWNLOADER_API_TOKEN', 'same-token-as-API_TOKEN');
```
