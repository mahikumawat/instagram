# Vercel API Deploy

This folder contains a Vercel serverless API for Instagram/Facebook reel URL extraction.

## 1) Deploy

1. Push this `vercel-api` folder to a GitHub repo (or keep in current repo).
2. In Vercel dashboard, create a new project and select this folder as root.
3. Add environment variable:
   - `API_TOKEN` = your secret token
4. Deploy.

Endpoint after deploy:

`https://<your-project>.vercel.app/api/extract`

## 2) Configure PHP frontend

In `/config.php` set:

```php
define('DOWNLOADER_API_URL', 'https://<your-project>.vercel.app/api/extract');
define('DOWNLOADER_API_TOKEN', 'same-token-as-vercel-api-token');
```

## 3) Test request

```bash
curl -X POST 'https://<your-project>.vercel.app/api/extract' \
  -H 'Authorization: Bearer <API_TOKEN>' \
  -H 'Content-Type: application/json' \
  -d '{"url":"https://www.instagram.com/reel/xxxx"}'
```
