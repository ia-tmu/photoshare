const uploadButton = document.querySelector("#upload-button");
const photoInput = document.querySelector("#photo-input");
const progressEl = document.querySelector(".upload-progress");
const progressBar = document.querySelector("#upload-progress-bar");
const previewDialog = document.querySelector("#preview-dialog");
const previewPanel = document.querySelector("#preview-panel");
const previewList = document.querySelector("#preview-list");
const previewCount = document.querySelector("#preview-count");
const sendButton = document.querySelector("#send-button");
const clearButton = document.querySelector("#clear-button");
const selectMoreButton = document.querySelector("#select-more-button");
const galleryScrollButton = document.querySelector("#gallery-scroll-button");
const galleryPeek = document.querySelector("#gallery-peek");
const gallerySection = document.querySelector("#gallery-section");

const selectedPhotos = new Map();
const previewUrls = new Map();
let previewCloseTimer = null;
let shouldResetPreviewAfterClose = false;

const prefersReducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)");

const updateGalleryPeekVisibility = () => {
  galleryPeek.classList.toggle("is-hidden", window.scrollY > 24);
};

const setProgress = (value) => {
  const safeValue = Math.max(0, Math.min(100, value));
  progressEl.style.setProperty("--progress", `${safeValue}%`);
  uploadButton.style.setProperty("--progress", `${safeValue}%`);
  progressBar.style.setProperty("--progress", `${safeValue}%`);
  progressBar.setAttribute("aria-valuenow", String(Math.round(safeValue)));
};

const setProgressBarActive = (isActive) => {
  if (isActive) {
    progressBar.hidden = false;
    progressBar.classList.add("is-active");
    return;
  }

  progressBar.classList.remove("is-active");

  window.setTimeout(() => {
    if (!progressBar.classList.contains("is-active")) {
      progressBar.hidden = true;
    }
  }, 180);
};

const formatCount = (count) => `${count}枚`;

const photoKey = (file) => `${file.name}-${file.size}-${file.lastModified}`;

const revokePreviewUrl = (key) => {
  const previewUrl = previewUrls.get(key);
  if (!previewUrl) return;

  URL.revokeObjectURL(previewUrl);
  previewUrls.delete(key);
};

const clearSelectedPhotos = () => {
  for (const key of previewUrls.keys()) {
    revokePreviewUrl(key);
  }

  selectedPhotos.clear();
  photoInput.value = "";
  renderPreviews();
};

const resetPreviewDialogContent = () => {
  previewList.innerHTML = "";
  previewPanel.hidden = true;
  previewPanel.classList.remove("is-sending");
  previewCount.textContent = `${formatCount(0)}選択中`;
  sendButton.disabled = true;
};

const closePreviewDialog = ({ resetAfterClose = false } = {}) => {
  if (!previewDialog.open) {
    if (resetAfterClose) resetPreviewDialogContent();
    return;
  }

  if (previewDialog.classList.contains("is-closing")) {
    shouldResetPreviewAfterClose = shouldResetPreviewAfterClose || resetAfterClose;
    return;
  }

  window.clearTimeout(previewCloseTimer);
  shouldResetPreviewAfterClose = resetAfterClose;
  previewDialog.classList.remove("is-open");
  previewDialog.classList.add("is-closing");

  const finishClose = () => {
    previewDialog.classList.remove("is-closing");
    previewDialog.close();
  };

  if (prefersReducedMotion.matches) {
    finishClose();
    return;
  }

  previewCloseTimer = window.setTimeout(finishClose, 180);
};

const openPreviewDialog = () => {
  const isAlreadyOpen = previewDialog.open && !previewDialog.classList.contains("is-closing");

  window.clearTimeout(previewCloseTimer);
  shouldResetPreviewAfterClose = false;

  if (isAlreadyOpen) {
    previewDialog.classList.add("is-open");
    return;
  }

  previewDialog.classList.remove("is-open", "is-closing");

  if (!previewDialog.open) {
    previewDialog.showModal();
  }

  window.requestAnimationFrame(() => {
    previewDialog.classList.add("is-open");
  });
};

const renderPreviews = () => {
  const count = selectedPhotos.size;

  if (count === 0) {
    closePreviewDialog({ resetAfterClose: true });
    return;
  }

  previewPanel.hidden = false;
  previewList.innerHTML = "";

  for (const [key, file] of selectedPhotos.entries()) {
    let previewUrl = previewUrls.get(key);
    if (!previewUrl) {
      previewUrl = URL.createObjectURL(file);
      previewUrls.set(key, previewUrl);
    }

    const item = document.createElement("li");
    item.className = "preview-item";

    const image = document.createElement("img");
    image.src = previewUrl;
    image.alt = file.name;

    const removeButton = document.createElement("button");
    removeButton.className = "remove-preview";
    removeButton.type = "button";
    removeButton.setAttribute("aria-label", `${file.name}を外す`);
    removeButton.textContent = "×";
    removeButton.addEventListener("click", () => {
      selectedPhotos.delete(key);
      revokePreviewUrl(key);
      renderPreviews();
    });

    item.append(image, removeButton);
    previewList.append(item);
  }

  previewCount.textContent = `${formatCount(count)}選択中`;
  sendButton.disabled = false;

  openPreviewDialog();
};

const addPhotos = (fileList) => {
  const files = Array.from(fileList).filter((file) => file.type.startsWith("image/"));
  if (files.length === 0) {
    return;
  }

  uploadButton.classList.remove("is-success", "is-error");
  setProgress(0);
  setProgressBarActive(false);

  for (const file of files) {
    selectedPhotos.set(photoKey(file), file);
  }

  renderPreviews();
};

const uploadPhotos = (files) =>
  new Promise((resolve, reject) => {
    const formData = new FormData();

    for (const file of files) {
      formData.append("photos[]", file);
    }

    const request = new XMLHttpRequest();
    request.open("POST", "api/send.php");
    request.responseType = "json";

    request.upload.addEventListener("progress", (event) => {
      if (!event.lengthComputable) return;
      setProgress((event.loaded / event.total) * 100);
    });

    request.addEventListener("load", () => {
      const result = request.response || {};
      if (request.status < 200 || request.status >= 300 || !result.ok) {
        reject(new Error(result.error || "アップロードに失敗しました。"));
        return;
      }

      resolve(result);
    });

    request.addEventListener("error", () => {
      reject(new Error("通信に失敗しました。時間をおいてもう一度お試しください。"));
    });

    request.send(formData);
  });

const sendSelectedPhotos = async () => {
  const files = Array.from(selectedPhotos.values());
  if (files.length === 0) {
    return;
  }

  uploadButton.disabled = true;
  sendButton.disabled = true;
  clearButton.disabled = true;
  selectMoreButton.disabled = true;
  uploadButton.classList.remove("is-success", "is-error");
  uploadButton.classList.add("is-uploading");
  previewPanel.classList.add("is-sending");
  setProgress(0);
  setProgressBarActive(true);

  try {
    await uploadPhotos(files);
    setProgress(100);
    uploadButton.classList.remove("is-uploading");
    uploadButton.classList.add("is-success");
    clearSelectedPhotos();

    window.setTimeout(() => {
      uploadButton.classList.remove("is-success");
      setProgress(0);
      setProgressBarActive(false);
    }, 1800);
  } catch (_error) {
    uploadButton.classList.remove("is-uploading");
    uploadButton.classList.add("is-error");
    sendButton.disabled = false;

    window.setTimeout(() => {
      uploadButton.classList.remove("is-error");
      setProgress(0);
      setProgressBarActive(false);
    }, 1800);
  } finally {
    uploadButton.disabled = false;
    clearButton.disabled = false;
    selectMoreButton.disabled = false;
    previewPanel.classList.remove("is-sending");
    photoInput.value = "";
  }
};

uploadButton.addEventListener("click", () => {
  photoInput.click();
});

selectMoreButton.addEventListener("click", () => {
  photoInput.click();
});

clearButton.addEventListener("click", () => {
  clearSelectedPhotos();
});

sendButton.addEventListener("click", () => {
  sendSelectedPhotos();
});

previewDialog.addEventListener("cancel", (event) => {
  event.preventDefault();
});

previewDialog.addEventListener("close", () => {
  window.clearTimeout(previewCloseTimer);
  previewDialog.classList.remove("is-open", "is-closing");

  if (shouldResetPreviewAfterClose) {
    resetPreviewDialogContent();
    shouldResetPreviewAfterClose = false;
  }
});

const scrollToGallery = () => {
  gallerySection.scrollIntoView({
    behavior: "smooth",
    block: "start",
  });
};

galleryScrollButton.addEventListener("click", () => {
  scrollToGallery();
});

galleryPeek.addEventListener("click", () => {
  scrollToGallery();
});

window.addEventListener("scroll", updateGalleryPeekVisibility, { passive: true });
updateGalleryPeekVisibility();

photoInput.addEventListener("change", (event) => {
  addPhotos(event.target.files);
  photoInput.value = "";
});
