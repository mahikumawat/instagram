const form = document.getElementById("downloadForm");
const button = document.getElementById("downloadBtn");
const message = document.getElementById("message");
const urlInput = document.getElementById("videoUrl");
const btnText = document.getElementById("btnText");

function isSupportedUrl(url) {
  try {
    const parsed = new URL(url);
    const host = parsed.hostname.toLowerCase();
    return (
      host.includes("instagram.com") ||
      host.includes("facebook.com") ||
      host.includes("fb.watch")
    );
  } catch (error) {
    return false;
  }
}

function setMessage(text, type = "") {
  message.textContent = text;
  message.classList.remove("error", "success");
  if (type) {
    message.classList.add(type);
  }
}

function setLoadingState(isLoading) {
  button.disabled = isLoading;
  button.classList.toggle("loading", isLoading);
  btnText.textContent = isLoading ? "Downloading..." : "Download Reel";
}

function readFilename(headers) {
  const header = headers.get("Content-Disposition") || "";
  const utf = header.match(/filename\*=UTF-8''([^;]+)/i);
  if (utf && utf[1]) {
    return decodeURIComponent(utf[1]);
  }
  const simple = header.match(/filename="?([^"]+)"?/i);
  return simple && simple[1] ? simple[1] : "reel_download.mp4";
}

form.addEventListener("submit", async (event) => {
  event.preventDefault();
  const url = urlInput.value.trim();

  if (!isSupportedUrl(url)) {
    setMessage(
      "Sahi URL daalein. Sirf Instagram/Facebook reel links supported hain.",
      "error"
    );
    return;
  }

  setLoadingState(true);
  setMessage("Link verify ho raha hai, download prepare ho raha hai...");

  try {
    const formData = new FormData(form);
    const response = await fetch(form.action, {
      method: "POST",
      body: formData,
      headers: {
        "X-Requested-With": "XMLHttpRequest"
      }
    });

    if (!response.ok) {
      let errorMessage = "Download fail ho gaya. Please dubara try karein.";
      try {
        const data = await response.json();
        if (data && data.message) {
          errorMessage = data.message;
        }
      } catch (error) {
        const fallbackText = await response.text();
        if (fallbackText.trim()) {
          errorMessage = fallbackText.trim();
        }
      }
      setMessage(errorMessage, "error");
      return;
    }

    const blob = await response.blob();
    const fileName = readFilename(response.headers);
    const blobUrl = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = blobUrl;
    link.download = fileName;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(blobUrl);

    setMessage("Download start ho gaya. Agar start na ho to popup blocker check karein.", "success");
  } catch (error) {
    setMessage(
      "Server se connection issue hai. Internet/server check karke phir try karein.",
      "error"
    );
  } finally {
    setLoadingState(false);
  }
});
