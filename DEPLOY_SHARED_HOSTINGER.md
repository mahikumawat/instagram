# Hostinger Shared Hosting Deployment

This project runs on Hostinger Shared Hosting as a PHP frontend only.
`self_api` cannot run on shared hosting because it needs Python/Flask.

## 1) What to upload

Upload only these to `public_html/`:

- `index.php`
- `download.php`
- `config.php`
- `assets/` (full folder)

Do not upload `self_api/` to shared hosting.

## 2) Required settings on Hostinger

- PHP version: 8.1+ (8.2/8.3 recommended)
- PHP extension: `curl` enabled
- SSL enabled on your domain

## 3) Configure remote API

Edit `config.php`:

```php
define('DOWNLOADER_API_URL', 'https://your-api-domain.com/extract');
define('DOWNLOADER_API_TOKEN', 'your-long-random-token');
```

`DOWNLOADER_API_URL` must point to your Python API hosted on VPS/Render/Railway/etc.
Token must exactly match that API server token.

## 4) Test

1. Open your domain.
2. Paste an Instagram/Facebook reel URL.
3. Click Download.
4. If it fails, verify:
   - API URL is reachable from browser/postman
   - token is correct
   - `curl` extension is enabled in PHP

## 5) Common errors

- `config.php me DOWNLOADER_API_URL sahi set karein.`
  - API URL missing or invalid.

- `Own API token invalid hai.`
  - Token mismatch between shared hosting and API server.

- `Own API server pe internal error aa raha hai.`
  - API server itself is down or failing.
