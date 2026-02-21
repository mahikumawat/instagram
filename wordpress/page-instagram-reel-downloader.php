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
    : 'https://instagram-delta-indol.vercel.app/api/extract';
$apiToken = defined('REEL_DOWNLOADER_API_TOKEN')
    ? (string) REEL_DOWNLOADER_API_TOKEN
    : 'ba7289cad95b333eb685ceaf63fe423b55eae260aa14d5fe853481c9dde53506';

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

function reel_downloader_validate_setup(string $apiUrl, string $apiToken): void
{
    if ($apiUrl === '' || !filter_var($apiUrl, FILTER_VALIDATE_URL)) {
        reel_downloader_fail('API URL is not configured correctly.', 500);
    }
    if ($apiToken === '' || $apiToken === 'CHANGE_ME_TOKEN') {
        reel_downloader_fail('API token is not configured.', 500);
    }
}

function reel_downloader_validate_video_url(string $videoUrl): string
{
    $videoUrl = trim($videoUrl);
    if ($videoUrl === '') {
        reel_downloader_fail('Reel URL is required.');
    }
    if (!filter_var($videoUrl, FILTER_VALIDATE_URL)) {
        reel_downloader_fail('The URL appears invalid. Please paste the full URL.');
    }
    if (!reel_downloader_is_supported_url($videoUrl)) {
        reel_downloader_fail('Only Instagram/Facebook reel URLs are supported.');
    }
    return $videoUrl;
}

function reel_downloader_extract_from_api(string $videoUrl, string $apiUrl, string $apiToken): array
{
    if (!function_exists('curl_init')) {
        reel_downloader_fail('cURL extension is not enabled on the server.', 500);
    }

    $payload = wp_json_encode(['url' => $videoUrl], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        reel_downloader_fail('Could not create request payload.', 500);
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
        reel_downloader_fail('Invalid API token. Please verify your token.', 401);
    }
    if (!empty($data['error']) && is_string($data['error'])) {
        reel_downloader_fail($data['error'], $status >= 400 ? $status : 422);
    }
    if ($status >= 500) {
        reel_downloader_fail('The API server returned an error. Please try again later.', 502);
    }
    if ($status < 200 || $status >= 300 || $rawBody === '') {
        reel_downloader_fail('No valid response received from API. HTTP ' . $status, 502);
    }
    if ($data === []) {
        reel_downloader_fail('Invalid JSON response from API.', 502);
    }

    $mediaUrl = (string) ($data['media_url'] ?? '');
    if (!filter_var($mediaUrl, FILTER_VALIDATE_URL)) {
        reel_downloader_fail('API did not return a valid media URL.', 422);
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
        reel_downloader_fail('Video streaming failed. Please try again.', 502);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce((string) $_POST['_wpnonce'], 'reel_downloader_submit')) {
        reel_downloader_fail('Security check failed. Refresh the page and try again.', 403);
    }

    reel_downloader_validate_setup($apiUrl, $apiToken);

    if (isset($_POST['reel_preview_action'])) {
        $videoUrl = reel_downloader_validate_video_url((string) ($_POST['video_url'] ?? ''));
        [$mediaUrl, $fileName] = reel_downloader_extract_from_api($videoUrl, $apiUrl, $apiToken);
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode([
            'ok' => true,
            'media_url' => $mediaUrl,
            'filename' => $fileName,
            'source' => $videoUrl,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (isset($_POST['reel_download_action'])) {
        $videoUrl = reel_downloader_validate_video_url((string) ($_POST['video_url'] ?? ''));
        [$mediaUrl, $fileName] = reel_downloader_extract_from_api($videoUrl, $apiUrl, $apiToken);
        reel_downloader_stream_remote_file($mediaUrl, $fileName);
    }
}

get_header();
?>

<main class="irm-wrap">
  <section class="irm-card">
    <h1>Instagram / Facebook Reel Downloader</h1>
    <p class="irm-sub">Paste a reel URL, preview it, then download.</p>

    <form id="irm-form" method="post" action="">
      <?php wp_nonce_field('reel_downloader_submit'); ?>
      <input type="hidden" name="reel_preview_action" value="1">

      <label for="irm-url">Reel URL</label>
      <div class="irm-input-row">
        <input id="irm-url" name="video_url" type="url" placeholder="https://www.instagram.com/reel/..." required>
        <button id="irm-paste-btn" type="button">Paste & Preview</button>
      </div>

      <button id="irm-btn" type="submit">
        <span id="irm-btn-text">Show Preview</span>
      </button>
    </form>

    <div id="irm-preview-wrap" class="irm-preview-wrap" hidden>
      <video id="irm-preview" controls playsinline preload="metadata"></video>
      <form id="irm-download-form" method="post" action="">
        <?php wp_nonce_field('reel_downloader_submit'); ?>
        <input type="hidden" name="reel_download_action" value="1">
        <input type="hidden" id="irm-download-url" name="video_url" value="">
        <button id="irm-download-btn" type="submit">Download Reel</button>
      </form>
    </div>

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
  .irm-input-row { display:grid; grid-template-columns: 1fr auto; gap:10px; }
  #irm-form input { height:46px; border:1px solid #d0d5dd; border-radius:10px; padding:0 14px; }
  #irm-paste-btn { height:46px; border:1px solid #d0d5dd; border-radius:10px; background:#fff; color:#111827; font-weight:600; cursor:pointer; padding:0 14px; white-space:nowrap; }
  #irm-paste-btn[disabled] { opacity:.7; cursor:not-allowed; }
  #irm-btn { height:46px; border:0; border-radius:10px; background:#111827; color:#fff; font-weight:600; cursor:pointer; }
  #irm-btn[disabled] { opacity:.7; cursor:not-allowed; }
  .irm-preview-wrap { margin-top:14px; border:1px solid #e4e7ec; border-radius:12px; padding:12px; background:#f9fafb; }
  #irm-preview { width:100%; border-radius:10px; background:#000; max-height:520px; }
  #irm-download-form { margin-top:12px; }
  #irm-download-btn { width:100%; height:46px; border:0; border-radius:10px; background:#111827; color:#fff; font-weight:600; cursor:pointer; }
  #irm-download-btn[disabled] { opacity:.7; cursor:not-allowed; }
  .irm-msg { min-height:24px; margin:10px 0 0; }
  .irm-msg.error { color:#b42318; }
  .irm-msg.success { color:#027a48; }
</style>

<script>
  (() => {
    const form = document.getElementById('irm-form');
    const btn = document.getElementById('irm-btn');
    const pasteBtn = document.getElementById('irm-paste-btn');
    const btnText = document.getElementById('irm-btn-text');
    const msg = document.getElementById('irm-msg');
    const urlInput = document.getElementById('irm-url');
    const previewWrap = document.getElementById('irm-preview-wrap');
    const previewVideo = document.getElementById('irm-preview');
    const downloadUrlInput = document.getElementById('irm-download-url');
    const downloadForm = document.getElementById('irm-download-form');
    const downloadBtn = document.getElementById('irm-download-btn');

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

    const handlePreview = async () => {
      const url = urlInput.value.trim();
      if (!isSupportedUrl(url)) {
        setMsg('Only Instagram/Facebook URLs are supported.', 'error');
        return false;
      }

      btn.disabled = true;
      pasteBtn.disabled = true;
      btnText.textContent = 'Loading preview...';
      setMsg('Verifying link...');

      try {
        const formData = new FormData(form);
        const response = await fetch(window.location.href, {
          method: 'POST',
          body: formData,
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        if (!response.ok) {
          let errorMessage = 'Preview failed. Please try again.';
          try {
            const data = await response.json();
            if (data && data.message) errorMessage = data.message;
          } catch (_) {}
          setMsg(errorMessage, 'error');
          return false;
        }

        const data = await response.json();
        if (!data || !data.media_url || !data.source) {
          setMsg('Invalid preview response from server.', 'error');
          return false;
        }

        previewVideo.src = data.media_url;
        previewWrap.hidden = false;
        downloadUrlInput.value = data.source;
        setMsg('Preview loaded. Click Download Reel below.', 'success');
        return true;
      } catch (_) {
        setMsg('Server connection issue. Please try again.', 'error');
        return false;
      } finally {
        btn.disabled = false;
        pasteBtn.disabled = false;
        btnText.textContent = 'Show Preview';
      }
    };

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      await handlePreview();
    });

    pasteBtn.addEventListener('click', async () => {
      if (!navigator.clipboard || !navigator.clipboard.readText) {
        setMsg('Clipboard access is not available. Please paste manually (Ctrl+V).', 'error');
        return;
      }

      try {
        const text = (await navigator.clipboard.readText()).trim();
        if (!text) {
          setMsg('Clipboard is empty. Copy a reel URL first.', 'error');
          return;
        }
        urlInput.value = text;
        await handlePreview();
      } catch (_) {
        setMsg('Clipboard permission denied. Please allow clipboard access and try again.', 'error');
      }
    });

    downloadForm.addEventListener('submit', () => {
      downloadBtn.disabled = true;
      downloadBtn.textContent = 'Preparing download...';
    });
  })();
</script>

<?php get_footer(); ?>
