const grid = document.querySelector("#photo-grid");
const sortSelect = document.querySelector("#sort-select");
const statusEl = document.querySelector("#gallery-status");
const sentinel = document.querySelector("#gallery-sentinel");
const galleryPeek = document.querySelector("#gallery-peek");

const PAGE_SIZE = 48;
const PEEK_SIZE = 5;
const DEFAULT_POLL_INTERVAL_MS = 10000;

let offset = 0;
let total = 0;
let isLoading = false;
let isPolling = false;
let hasMore = true;
let currentSort = sortSelect.value;
let pollTimer = null;
let pollIntervalMs = DEFAULT_POLL_INTERVAL_MS;

const knownPhotoIds = new Set();

const dateFormatter = new Intl.DateTimeFormat("ja-JP", {
  year: "numeric",
  month: "2-digit",
  day: "2-digit",
  hour: "2-digit",
  minute: "2-digit",
});

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
  lightbox.caption.textContent = dateFormatter.format(new Date(photo.uploadedAt));
  lightbox.dialog.showModal();
  window.requestAnimationFrame(() => {
    lightbox.dialog.classList.add("is-open");
  });
  lightbox.closeButton.focus();
};

const setStatus = (message) => {
  statusEl.textContent = message;
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
  if (sort === "name_asc") return left.name.localeCompare(right.name, "ja", { numeric: true });
  if (sort === "name_desc") return right.name.localeCompare(left.name, "ja", { numeric: true });

  return right.timestamp - left.timestamp;
};

const photoFromItem = (item) => ({
  id: item.dataset.id,
  name: item.dataset.name,
  timestamp: Number(item.dataset.timestamp || 0),
});

const createPhotoItem = (photo, { isNew = false } = {}) => {
  const item = document.createElement("figure");
  item.className = `photo-item${isNew ? " is-new" : ""}`;
  item.dataset.id = photo.id;
  item.dataset.name = photo.name;
  item.dataset.timestamp = String(photo.timestamp || 0);

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
  caption.textContent = dateFormatter.format(new Date(photo.uploadedAt));

  item.append(zoomButton, caption);
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
  if (isLoading || !hasMore) return;

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

resetGallery();
schedulePolling();
