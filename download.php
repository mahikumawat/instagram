<?php
declare(strict_types=1);

$configPath = __DIR__ . '/config.php';
if (is_file($configPath)) {
    require_once $configPath;
}

function fail(string $message, int $status = 400): void
{
    http_response_code($status);
    $isAjax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function streamRemoteFile(string $fileUrl, string $downloadName): void
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
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: public');

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($curl, $chunk): int {
        echo $chunk;
        flush();
        return strlen($chunk);
    });

    $ok = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($ok === false || $status >= 400) {
        fail('Video stream fail ho gaya. Dubara try karein.', 502);
    }
    exit;
}

function extractFromOwnApi(string $videoUrl, string $apiUrl, string $apiToken): array
{
    if (!function_exists('curl_init')) {
        fail('Server pe cURL extension enabled nahi hai. Hostinger PHP settings me enable karein.', 500);
    }

    $payload = json_encode(['url' => $videoUrl], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        fail('Request payload create nahi ho paya.', 500);
    }

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
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

    $rawBody = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status === 401 || $status === 403) {
        fail('Own API token invalid hai. config.php me token check karein.', 401);
    }
    if ($status >= 500) {
        fail('Own API server pe internal error aa raha hai. VPS logs check karein.', 502);
    }
    if ($status < 200 || $status >= 300 || $rawBody === '') {
        fail('Own API se valid response nahi mila.', 502);
    }

    $data = json_decode($rawBody, true);
    if (!is_array($data)) {
        fail('Own API JSON response invalid hai.', 502);
    }

    if (!empty($data['error']) && is_string($data['error'])) {
        fail($data['error'], 422);
    }

    $mediaUrl = (string)($data['media_url'] ?? '');
    if (!filter_var($mediaUrl, FILTER_VALIDATE_URL)) {
        fail('Own API ne valid media URL return nahi kiya.', 422);
    }

    $fileName = (string)($data['filename'] ?? '');
    if ($fileName === '') {
        $path = (string)parse_url($mediaUrl, PHP_URL_PATH);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === '' || strlen($ext) > 5) {
            $ext = 'mp4';
        }
        $fileName = 'reel_' . date('Ymd_His') . '.' . $ext;
    }

    return [$mediaUrl, $fileName];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Invalid request method. Please submit using the download form.', 405);
}

$videoUrl = trim((string)($_POST['video_url'] ?? ''));
if ($videoUrl === '') {
    fail('Reel URL required hai. Link paste karke phir try karein.');
}
if (!filter_var($videoUrl, FILTER_VALIDATE_URL)) {
    fail('URL invalid lag raha hai. Full Instagram/Facebook reel URL paste karein.');
}

$host = strtolower((string)parse_url($videoUrl, PHP_URL_HOST));
$supportedHosts = ['instagram.com', 'facebook.com', 'fb.watch'];
$isSupported = false;
foreach ($supportedHosts as $allowed) {
    if (str_contains($host, $allowed)) {
        $isSupported = true;
        break;
    }
}
if (!$isSupported) {
    fail('Sirf Instagram ya Facebook reel/video URL support hota hai.');
}

$apiUrl = trim((string)(defined('DOWNLOADER_API_URL') ? DOWNLOADER_API_URL : getenv('DOWNLOADER_API_URL')));
$apiToken = trim((string)(defined('DOWNLOADER_API_TOKEN') ? DOWNLOADER_API_TOKEN : getenv('DOWNLOADER_API_TOKEN')));
if ($apiUrl === '' || !filter_var($apiUrl, FILTER_VALIDATE_URL)) {
    fail('config.php me DOWNLOADER_API_URL sahi set karein.', 500);
}
if ($apiToken === '' || $apiToken === 'CHANGE_ME_LONG_RANDOM_TOKEN') {
    fail('config.php me DOWNLOADER_API_TOKEN set karein.', 500);
}

[$mediaUrl, $fileName] = extractFromOwnApi($videoUrl, $apiUrl, $apiToken);
streamRemoteFile($mediaUrl, $fileName);
