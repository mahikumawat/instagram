<?php
?><!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reel Downloader</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
  <main class="card">
    <h1>Instagram / Facebook Reel Downloader</h1>
    <p class="sub">Paste reel URL and click download.</p>

    <form id="downloadForm" action="download.php" method="post" novalidate>
      <label for="videoUrl">Reel URL</label>
      <input
        id="videoUrl"
        name="video_url"
        type="url"
        placeholder="https://www.instagram.com/reel/..."
        required
      >
      <button id="downloadBtn" type="submit">
        <span id="spinner" class="spinner" aria-hidden="true"></span>
        <span id="btnText">Download Reel</span>
      </button>
    </form>

    <p id="message" class="message" aria-live="polite"></p>

    <div class="note">
      <strong>Required on server:</strong> PHP + cURL extension + your self-hosted downloader API
    </div>
  </main>

  <script src="assets/app.js"></script>
</body>
</html>
