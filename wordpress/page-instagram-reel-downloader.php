<?php
/**
 * Template Name: Instagram Reel Downloader
 * Template Post Type: page
 */

declare(strict_types=1);

/**
 * Set your Vercel API values here.
 * You can also define these in wp-config.php and those values will be used first.
 */
$apiUrl = defined('REEL_DOWNLOADER_API_URL')
    ? (string) REEL_DOWNLOADER_API_URL
    : 'https://your-project.vercel.app/api/extract';
$apiToken = defined('REEL_DOWNLOADER_API_TOKEN')
    ? (string) REEL_DOWNLOADER_API_TOKEN
    : 'CHANGE_ME_TOKEN';

function reel_downloader_fail(string $message, int $status = 400): void
{
    http_response_code($status);
    $isAjax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode(['message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function reel_downloader_is_supported_url(string $url): bool
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $supportedHosts = ['instagram.com', 'facebook.com', 'fb.watch'];
    foreach ($supportedHosts as $allowed) {
        if (str_contains($host, $allowed)) {
            return true;
        }
    }
    return false;
}

function reel_downloader_extract_from_api(string $videoUrl, string $apiUrl, string $apiToken): array
{
    if (!function_exists('curl_init')) {
        reel_downloader_fail('Server pe cURL extension enabled nahi hai.', 500);
    }

    $payload = wp_json_encode(['url' => $videoUrl], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        reel_downloader_fail('Request payload create nahi ho paya.', 500);
    }

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiToken,
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $rawBody = (string) curl_exec($ch);
    $curlError = (string) curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = [];
    if ($rawBody !== '') {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    if ($curlError !== '') {
        reel_downloader_fail('API request failed: ' . $curlError, 502);
    }
    if ($status === 401 || $status === 403) {
        reel_downloader_fail('API token invalid hai. Token check karein.', 401);
    }
    if (!empty($data['error']) && is_string($data['error'])) {
        reel_downloader_fail($data['error'], $status >= 400 ? $status : 422);
    }
    if ($status >= 500) {
        reel_downloader_fail('API server pe error aa raha hai. Thodi der baad try karein.', 502);
    }
    if ($status < 200 || $status >= 300 || $rawBody === '') {
        reel_downloader_fail('API se valid response nahi mila. HTTP ' . $status, 502);
    }
    if ($data === []) {
        reel_downloader_fail('API JSON response invalid hai.', 502);
    }

    $mediaUrl = (string) ($data['media_url'] ?? '');
    if (!filter_var($mediaUrl, FILTER_VALIDATE_URL)) {
        reel_downloader_fail('API ne valid media URL return nahi kiya.', 422);
    }

    $fileName = (string) ($data['filename'] ?? '');
    if ($fileName === '') {
        $fileName = 'reel_' . date('Ymd_His') . '.mp4';
    }

    return [$mediaUrl, $fileName];
}

function reel_downloader_stream_remote_file(string $fileUrl, string $downloadName): void
{
    $ch = curl_init($fileUrl);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_BUFFERSIZE => 8192,
        CURLOPT_HEADER => false,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . sanitize_file_name($downloadName) . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: public');

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($curl, $chunk): int {
        echo $chunk;
        flush();
        return strlen($chunk);
    });

    $ok = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($ok === false || $status >= 400) {
        reel_downloader_fail('Video stream fail ho gaya. Dubara try karein.', 502);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reel_download_action'])) {
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce((string) $_POST['_wpnonce'], 'reel_downloader_submit')) {
        reel_downloader_fail('Security check fail ho gaya. Page refresh karke dubara try karein.', 403);
    }

    $videoUrl = trim((string) ($_POST['video_url'] ?? ''));
    if ($videoUrl === '') {
        reel_downloader_fail('Reel URL required hai.');
    }
    if (!filter_var($videoUrl, FILTER_VALIDATE_URL)) {
        reel_downloader_fail('URL invalid lag raha hai. Full URL paste karein.');
    }
    if (!reel_downloader_is_supported_url($videoUrl)) {
        reel_downloader_fail('Sirf Instagram/Facebook reel URL support hota hai.');
    }
    if ($apiUrl === '' || !filter_var($apiUrl, FILTER_VALIDATE_URL)) {
        reel_downloader_fail('API URL sahi set nahi hai.', 500);
    }
    if ($apiToken === '' || $apiToken === 'CHANGE_ME_TOKEN') {
        reel_downloader_fail('API token set nahi hai.', 500);
    }

    [$mediaUrl, $fileName] = reel_downloader_extract_from_api($videoUrl, $apiUrl, $apiToken);
    reel_downloader_stream_remote_file($mediaUrl, $fileName);
}

get_header();
?>

<main class="irm-wrap">
  <section class="irm-card">
    <h1>Instagram / Facebook Reel Downloader</h1>
    <p class="irm-sub">Reel URL paste karke download karein.</p>

    <form id="irm-form" method="post" action="">
      <?php wp_nonce_field('reel_downloader_submit'); ?>
      <input type="hidden" name="reel_download_action" value="1">

      <label for="irm-url">Reel URL</label>
      <input id="irm-url" name="video_url" type="url" placeholder="https://www.instagram.com/reel/..." required>

      <button id="irm-btn" type="submit">
        <span id="irm-btn-text">Download Reel</span>
      </button>
    </form>

    <p id="irm-msg" class="irm-msg" aria-live="polite"></p>
  </section>
</main>

<style>
  .irm-wrap { max-width: 760px; margin: 60px auto; padding: 0 18px; }
  .irm-card { background:#fff; border:1px solid #e9e9ee; border-radius:16px; padding:28px; box-shadow:0 10px 30px rgba(0,0,0,.05); }
  .irm-card h1 { margin:0 0 8px; font-size:28px; line-height:1.2; color:#101828; }
  .irm-sub { margin:0 0 20px; color:#475467; }
  #irm-form { display:grid; gap:12px; }
  #irm-form label { font-weight:600; color:#344054; }
  #irm-form input { height:46px; border:1px solid #d0d5dd; border-radius:10px; padding:0 14px; }
  #irm-form button { height:46px; border:0; border-radius:10px; background:#111827; color:#fff; font-weight:600; cursor:pointer; }
  #irm-form button[disabled] { opacity:.7; cursor:not-allowed; }
  .irm-msg { min-height:24px; margin:10px 0 0; }
  .irm-msg.error { color:#b42318; }
  .irm-msg.success { color:#027a48; }
</style>

<script>
  (() => {
    const form = document.getElementById('irm-form');
    const btn = document.getElementById('irm-btn');
    const btnText = document.getElementById('irm-btn-text');
    const msg = document.getElementById('irm-msg');
    const urlInput = document.getElementById('irm-url');

    const setMsg = (text, type = '') => {
      msg.textContent = text;
      msg.className = 'irm-msg' + (type ? ' ' + type : '');
    };

    const isSupportedUrl = (url) => {
      try {
        const host = new URL(url).hostname.toLowerCase();
        return host.includes('instagram.com') || host.includes('facebook.com') || host.includes('fb.watch');
      } catch (e) {
        return false;
      }
    };

    const readFilename = (headers) => {
      const cd = headers.get('Content-Disposition') || '';
      const utf = cd.match(/filename\*=UTF-8''([^;]+)/i);
      if (utf && utf[1]) return decodeURIComponent(utf[1]);
      const simple = cd.match(/filename="?([^"]+)"?/i);
      return simple && simple[1] ? simple[1] : 'reel_download.mp4';
    };

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const url = urlInput.value.trim();
      if (!isSupportedUrl(url)) {
        setMsg('Sirf Instagram/Facebook URL supported hain.', 'error');
        return;
      }

      btn.disabled = true;
      btnText.textContent = 'Downloading...';
      setMsg('Link verify ho raha hai...');

      try {
        const formData = new FormData(form);
        const response = await fetch(window.location.href, {
          method: 'POST',
          body: formData,
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        if (!response.ok) {
          let errorMessage = 'Download fail ho gaya. Dubara try karein.';
          try {
            const data = await response.json();
            if (data && data.message) errorMessage = data.message;
          } catch (_) {}
          setMsg(errorMessage, 'error');
          return;
        }

        const blob = await response.blob();
        const fileName = readFilename(response.headers);
        const blobUrl = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = blobUrl;
        a.download = fileName;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(blobUrl);
        setMsg('Download start ho gaya.', 'success');
      } catch (_) {
        setMsg('Server connection issue. Dubara try karein.', 'error');
      } finally {
        btn.disabled = false;
        btnText.textContent = 'Download Reel';
      }
    });
  })();
</script>

<?php get_footer(); ?>
