const grid = document.querySelector("#photo-grid");
const sortSelect = document.querySelector("#sort-select");
const statusEl = document.querySelector("#gallery-status");
const sentinel = document.querySelector("#gallery-sentinel");
const galleryPeek = document.querySelector("#gallery-peek");
const galleryShell = grid.closest(".gallery-shell");

const PAGE_SIZE = 48;
const PEEK_SIZE = 5;
const DEFAULT_POLL_INTERVAL_MS = 10000;

let offset = 0;
let total = 0;
let isLoading = false;
let isPolling = false;
let hasMore = true;
let isGalleryActive = false;
let currentSort = sortSelect.value;
let pollTimer = null;
let pollIntervalMs = DEFAULT_POLL_INTERVAL_MS;

const knownPhotoIds = new Set();
const isAdminMode = new URLSearchParams(window.location.search).has("admin");
let isAdminAuthenticated = false;
let adminPassword = "";
let adminStatusEl = null;

const dateFormatter = new Intl.DateTimeFormat("ja-JP", {
  year: "numeric",
  month: "2-digit",
  day: "2-digit",
  hour: "2-digit",
  minute: "2-digit",
});

const formatPhotoDate = (photo) => {
  const dateValue = photo.capturedAt || photo.uploadedAt;
  const label = photo.capturedAt ? "撮影日時" : "共有日時";
  return `${label}: ${dateFormatter.format(new Date(dateValue))}`;
};

const createPhotoLightbox = () => {
  let dialog = document.querySelector("#photo-lightbox");

  if (!dialog) {
    dialog = document.createElement("dialog");
    dialog.id = "photo-lightbox";
    dialog.className = "photo-lightbox";
    dialog.setAttribute("aria-label", "写真の拡大表示");

    const closeButton = document.createElement("button");
    closeButton.id = "photo-lightbox-close";
    closeButton.className = "photo-lightbox-close";
    closeButton.type = "button";
    closeButton.setAttribute("aria-label", "拡大表示を閉じる");
    closeButton.textContent = "×";

    const figure = document.createElement("figure");
    figure.className = "photo-lightbox-figure";

    const image = document.createElement("img");
    image.id = "photo-lightbox-image";
    image.alt = "";

    const caption = document.createElement("figcaption");
    caption.id = "photo-lightbox-caption";

    figure.append(image, caption);
    dialog.append(closeButton, figure);
    document.body.append(dialog);
  }

  return {
    caption: dialog.querySelector("#photo-lightbox-caption"),
    closeButton: dialog.querySelector("#photo-lightbox-close"),
    dialog,
    image: dialog.querySelector("#photo-lightbox-image"),
  };
};

const lightbox = createPhotoLightbox();
let lastFocusedElement = null;
let lightboxCloseTimer = null;

const prefersReducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)");

const closePhotoLightbox = () => {
  if (!lightbox.dialog.open) return;
  if (lightbox.dialog.classList.contains("is-closing")) return;

  window.clearTimeout(lightboxCloseTimer);
  lightbox.dialog.classList.remove("is-open");
  lightbox.dialog.classList.add("is-closing");

  const finishClose = () => {
    lightbox.dialog.classList.remove("is-closing");
    lightbox.dialog.close();
  };

  if (prefersReducedMotion.matches) {
    finishClose();
    return;
  }

  lightboxCloseTimer = window.setTimeout(finishClose, 180);
};

const openPhotoLightbox = (photo) => {
  lastFocusedElement = document.activeElement instanceof HTMLElement ? document.activeElement : null;
  window.clearTimeout(lightboxCloseTimer);
  lightbox.dialog.classList.remove("is-open", "is-closing");
  lightbox.image.src = photo.originalUrl || photo.url;
  lightbox.image.alt = photo.name;
  lightbox.caption.textContent = formatPhotoDate(photo);
  lightbox.dialog.showModal();
  window.requestAnimationFrame(() => {
    lightbox.dialog.classList.add("is-open");
  });
  lightbox.closeButton.focus();
};

const setStatus = (message) => {
  statusEl.textContent = message;
};

const setAdminStatus = (message) => {
  if (adminStatusEl) {
    adminStatusEl.textContent = message;
  }
};

const returnToNormalPage = () => {
  const url = new URL(window.location.href);
  url.searchParams.delete("admin");
  window.location.replace(url.toString());
};

const adminRequest = async (payload) => {
  if (!adminPassword) {
    throw new Error("管理パスワードが入力されていません。");
  }

  const response = await fetch("api/admin.php", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify({
      ...payload,
      password: adminPassword,
    }),
  });
  const result = await response.json().catch(() => ({}));

  if (!response.ok || !result.ok) {
    if (response.status === 403) adminPassword = "";
    throw new Error(result.error || "管理操作に失敗しました。");
  }

  return result;
};

const authenticateAdminMode = async () => {
  if (!isAdminMode) return true;

  const password = window.prompt("管理パスワードを入力してください。");
  if (!password) {
    window.alert("管理パスワードが入力されていません。通常ページに戻ります。");
    returnToNormalPage();
    return false;
  }

  adminPassword = password;

  try {
    await adminRequest({ action: "verify" });
    isAdminAuthenticated = true;
    return true;
  } catch (error) {
    adminPassword = "";
    window.alert(`${error.message}\n通常ページに戻ります。`);
    returnToNormalPage();
    return false;
  }
};

const createAdminToolbar = () => {
  if (!isAdminMode || !isAdminAuthenticated || !galleryShell) return;

  const toolbar = document.createElement("section");
  toolbar.className = "admin-toolbar";
  toolbar.setAttribute("aria-label", "管理モード");

  const label = document.createElement("strong");
  label.textContent = "管理モード";

  adminStatusEl = document.createElement("span");
  adminStatusEl.className = "admin-status";
  adminStatusEl.textContent = "個別削除と全削除ができます。";

  const deleteAllButton = document.createElement("button");
  deleteAllButton.className = "danger-button";
  deleteAllButton.type = "button";
  deleteAllButton.textContent = "全て削除";
  deleteAllButton.addEventListener("click", async () => {
    if (total === 0) {
      setAdminStatus("削除する写真はありません。");
      return;
    }
    if (!window.confirm("アップロードされた全ての写真を削除します。よろしいですか？")) return;

    deleteAllButton.disabled = true;
    setAdminStatus("全て削除しています...");

    try {
      const result = await adminRequest({ action: "delete_all" });
      knownPhotoIds.clear();
      grid.innerHTML = "";
      if (galleryPeek) {
        galleryPeek.hidden = true;
      }
      offset = 0;
      total = 0;
      hasMore = false;
      sentinel.classList.remove("is-loading");
      updateStatus(`${result.deleted || 0}枚削除しました。`);
      setAdminStatus("全て削除しました。");
    } catch (error) {
      setAdminStatus(error.message);
    } finally {
      deleteAllButton.disabled = false;
    }
  });

  const exitButton = document.createElement("button");
  exitButton.className = "secondary-button";
  exitButton.type = "button";
  exitButton.textContent = "終了";
  exitButton.addEventListener("click", () => {
    const url = new URL(window.location.href);
    url.searchParams.delete("admin");
    window.location.href = url.toString();
  });

  toolbar.append(label, adminStatusEl, deleteAllButton, exitButton);
  galleryShell.insertBefore(toolbar, statusEl);
};

const updateStatus = (prefix = "") => {
  if (total === 0) {
    setStatus("まだ写真はありません。");
    return;
  }

  const shown = knownPhotoIds.size;
  setStatus(`${prefix}${total}枚中 ${Math.min(shown, total)}枚を表示しています。`);
};

const updateGalleryPeek = (photos) => {
  if (!galleryPeek || photos.length === 0) return;

  const latestPhotos = [...photos]
    .sort((left, right) => Number(right.timestamp || 0) - Number(left.timestamp || 0))
    .slice(0, PEEK_SIZE);

  if (latestPhotos.length === 0) return;

  const list = document.createElement("span");
  list.className = "gallery-peek-list";

  for (const photo of latestPhotos) {
    const item = document.createElement("span");
    item.className = "gallery-peek-item";

    const image = document.createElement("img");
    image.src = photo.thumbnailUrl || photo.url;
    image.alt = "";
    image.loading = "lazy";
    image.decoding = "async";

    item.append(image);
    list.append(item);
  }

  galleryPeek.replaceChildren(list);
  galleryPeek.hidden = false;
};

const comparePhotos = (left, right, sort = currentSort) => {
  if (sort === "oldest") return left.timestamp - right.timestamp;
  if (sort === "captured_newest") return compareCapturedPhotos(left, right, "desc");
  if (sort === "captured_oldest") return compareCapturedPhotos(left, right, "asc");
  if (sort === "name_asc") return left.name.localeCompare(right.name, "ja", { numeric: true });
  if (sort === "name_desc") return right.name.localeCompare(left.name, "ja", { numeric: true });

  return right.timestamp - left.timestamp;
};

const compareCapturedPhotos = (left, right, direction) => {
  const leftCaptured = Number(left.capturedTimestamp || 0);
  const rightCaptured = Number(right.capturedTimestamp || 0);

  if (leftCaptured > 0 && rightCaptured > 0) {
    return direction === "asc" ? leftCaptured - rightCaptured : rightCaptured - leftCaptured;
  }

  if (leftCaptured > 0) return -1;
  if (rightCaptured > 0) return 1;

  return direction === "asc" ? left.timestamp - right.timestamp : right.timestamp - left.timestamp;
};

const photoFromItem = (item) => ({
  id: item.dataset.id,
  name: item.dataset.name,
  timestamp: Number(item.dataset.timestamp || 0),
  capturedTimestamp: Number(item.dataset.capturedTimestamp || 0),
});

const createPhotoItem = (photo, { isNew = false } = {}) => {
  const item = document.createElement("figure");
  item.className = `photo-item${isNew ? " is-new" : ""}`;
  item.dataset.id = photo.id;
  item.dataset.name = photo.name;
  item.dataset.timestamp = String(photo.timestamp || 0);
  item.dataset.capturedTimestamp = String(photo.capturedTimestamp || 0);

  const frame = document.createElement("div");
  frame.className = "photo-frame";

  const loader = document.createElement("span");
  loader.className = "photo-loader";
  loader.setAttribute("aria-hidden", "true");

  const image = document.createElement("img");
  image.src = photo.thumbnailUrl || photo.url;
  image.alt = photo.name;
  image.loading = "lazy";
  image.decoding = "async";
  image.addEventListener("load", () => {
    frame.classList.add("is-loaded");
  });
  image.addEventListener("error", () => {
    frame.classList.add("is-error");
  });

  frame.append(loader, image);

  const zoomButton = document.createElement("button");
  zoomButton.className = "photo-zoom-button";
  zoomButton.type = "button";
  zoomButton.setAttribute("aria-label", `${photo.name}を拡大表示`);
  zoomButton.addEventListener("click", () => {
    openPhotoLightbox(photo);
  });
  zoomButton.append(frame);

  const caption = document.createElement("figcaption");
  caption.textContent = formatPhotoDate(photo);

  item.append(zoomButton);

  if (isAdminMode && isAdminAuthenticated) {
    const deleteButton = document.createElement("button");
    deleteButton.className = "photo-delete-button";
    deleteButton.type = "button";
    deleteButton.setAttribute("aria-label", `${photo.name}を削除`);
    deleteButton.textContent = "削除";
    deleteButton.addEventListener("click", async () => {
      if (!window.confirm("この写真を削除します。よろしいですか？")) return;

      deleteButton.disabled = true;
      setAdminStatus("削除しています...");

      try {
        await adminRequest({ action: "delete", name: photo.name });
        knownPhotoIds.delete(photo.id);
        item.remove();
        total = Math.max(0, total - 1);
        offset = Math.max(0, offset - 1);
        if (total === 0 && galleryPeek) {
          galleryPeek.hidden = true;
        }
        updateStatus("1枚削除しました。");
        setAdminStatus("削除しました。");
      } catch (error) {
        setAdminStatus(error.message);
        deleteButton.disabled = false;
      }
    });
    item.append(deleteButton);
  }

  item.append(caption);
  return item;
};

const fetchPhotos = async ({ sort = currentSort, pageOffset = offset, limit = PAGE_SIZE } = {}) => {
  const params = new URLSearchParams({
    sort,
    offset: String(pageOffset),
    limit: String(limit),
  });

  const response = await fetch(`api/list.php?${params.toString()}`, {
    cache: "no-store",
  });
  const result = await response.json().catch(() => ({}));

  if (!response.ok || !result.ok) {
    throw new Error(result.error || "写真一覧を読み込めませんでした。");
  }

  const nextPollIntervalMs = Number(result.pollIntervalMs) || DEFAULT_POLL_INTERVAL_MS;
  if (nextPollIntervalMs !== pollIntervalMs) {
    pollIntervalMs = nextPollIntervalMs;
    if (pollTimer !== null) {
      schedulePolling();
    }
  }

  return result;
};

const appendPhoto = (photo, options = {}) => {
  if (knownPhotoIds.has(photo.id)) return false;

  knownPhotoIds.add(photo.id);
  grid.append(createPhotoItem(photo, options));
  return true;
};

const insertPhoto = (photo, options = {}) => {
  if (knownPhotoIds.has(photo.id)) return false;

  const item = createPhotoItem(photo, options);
  const existingItems = Array.from(grid.querySelectorAll(".photo-item"));
  const nextItem = existingItems.find((existingItem) => {
    const existingPhoto = photoFromItem(existingItem);
    return comparePhotos(photo, existingPhoto) < 0;
  });

  knownPhotoIds.add(photo.id);
  grid.insertBefore(item, nextItem || null);
  return true;
};

const loadNextPage = async () => {
  if (!isGalleryActive || isLoading || !hasMore) return;

  isLoading = true;
  sentinel.classList.add("is-loading");

  try {
    const result = await fetchPhotos();
    total = result.total || 0;
    hasMore = Boolean(result.hasMore);
    offset += result.photos.length;

    updateGalleryPeek(result.photos);

    for (const photo of result.photos) {
      appendPhoto(photo);
    }

    updateStatus();
  } catch (error) {
    setStatus(error.message);
    hasMore = false;
  } finally {
    isLoading = false;
    sentinel.classList.toggle("is-loading", hasMore);
  }
};

const pollForNewPhotos = async () => {
  if (isPolling || document.hidden) return;

  isPolling = true;

  try {
    const result = await fetchPhotos({
      sort: "newest",
      pageOffset: 0,
      limit: PAGE_SIZE,
    });
    updateGalleryPeek(result.photos);
    const newPhotos = result.photos.filter((photo) => !knownPhotoIds.has(photo.id));

    if (newPhotos.length > 0) {
      total = result.total || total + newPhotos.length;
      offset += newPhotos.length;

      const sortedNewPhotos = [...newPhotos].sort((left, right) => comparePhotos(left, right));
      let insertedCount = 0;
      for (const photo of sortedNewPhotos) {
        if (insertPhoto(photo, { isNew: true })) {
          insertedCount += 1;
        }
      }

      if (insertedCount > 0) {
        updateStatus(`${insertedCount}枚追加されました。`);
      }
    } else {
      total = result.total || total;
      updateStatus();
    }

    hasMore = knownPhotoIds.size < total;
    sentinel.classList.toggle("is-loading", hasMore && isLoading);
  } catch (_error) {
    // Temporary polling failures should not disturb the projected gallery.
  } finally {
    isPolling = false;
  }
};

const schedulePolling = () => {
  window.clearInterval(pollTimer);
  pollTimer = window.setInterval(pollForNewPhotos, pollIntervalMs);
};

const resetGallery = () => {
  offset = 0;
  total = 0;
  hasMore = true;
  knownPhotoIds.clear();
  grid.innerHTML = "";
  setStatus("読み込み中です。");
  loadNextPage();
};

const observer = new IntersectionObserver(
  (entries) => {
    if (entries.some((entry) => entry.isIntersecting)) {
      loadNextPage();
    }
  },
  {
    rootMargin: "720px 0px",
  },
);

observer.observe(sentinel);

lightbox.closeButton.addEventListener("click", () => {
  closePhotoLightbox();
});

lightbox.dialog.addEventListener("click", (event) => {
  if (event.target === lightbox.dialog) {
    closePhotoLightbox();
  }
});

lightbox.dialog.addEventListener("cancel", (event) => {
  event.preventDefault();
  closePhotoLightbox();
});

lightbox.dialog.addEventListener("close", () => {
  window.clearTimeout(lightboxCloseTimer);
  lightbox.dialog.classList.remove("is-open", "is-closing");
  lightbox.image.removeAttribute("src");
  lightbox.image.alt = "";
  lightbox.caption.textContent = "";
  lastFocusedElement?.focus();
  lastFocusedElement = null;
});

sortSelect.addEventListener("change", () => {
  currentSort = sortSelect.value;
  resetGallery();
});

document.addEventListener("visibilitychange", () => {
  if (!document.hidden) {
    pollForNewPhotos();
  }
});

const initializeGallery = async () => {
  if (!(await authenticateAdminMode())) return;

  isGalleryActive = true;
  createAdminToolbar();
  resetGallery();
  schedulePolling();
};

initializeGallery();
