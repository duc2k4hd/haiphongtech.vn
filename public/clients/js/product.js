(function () {
  "use strict";

  let productImages = Array.from(document.querySelectorAll(".misutech_product_thumbnail"));
  const mainImage = document.querySelector(".misutech_product_main_image");
  const imageModal = document.querySelector('[data-modal="image"]');
  const modalImage = document.querySelector(".misutech_product_modal_image");
  const quantityInput = document.querySelector(".misutech_product_quantity_input");
  const cartCount = document.querySelector(".misutech_product_cart_count");
  const cartDrawer = document.querySelector(".misutech_product_cart_drawer");
  const cartOverlay = document.querySelector(".misutech_product_drawer_overlay");
  const cartItems = document.querySelector(".misutech_product_cart_items");
  const cartTotal = document.querySelector(".misutech_product_cart_total");
  const toast = document.querySelector(".misutech_product_toast");
  const relatedTrack = document.querySelector(".misutech_product_related_track");
  const relatedCards = Array.from(document.querySelectorAll(".misutech_product_related_card"));
  const relatedDots = document.querySelector(".misutech_product_related_dots");
  const backTop = document.querySelector(".misutech_product_back_top");
  let activeImageIndex = 0;
  let cartItemCount = 0;
  let cartValue = 0;
  let relatedPage = 0;
  let relatedVisibleCount = 4;
  let quickViewCard = null;
  let toastTimer = null;
  let imageTimer = null;

  // Local In-Memory Cache (0ms latency, Zero server load)
  let localModelStore = {};
  let currentAbortController = null;

  // Khởi tạo data cache từ inline script (được render sẵn từ server)
  try {
    const embeddedScript = document.getElementById("misutech_embedded_models");
    if (embeddedScript && embeddedScript.textContent.trim()) {
      localModelStore = JSON.parse(embeddedScript.textContent);
    }
  } catch (e) {
    console.warn("Could not parse embedded models JSON:", e);
  }

  function showToast(message) {
    window.clearTimeout(toastTimer);
    if (!toast) return;
    toast.textContent = message;
    toast.hidden = false;
    toastTimer = window.setTimeout(() => {
      toast.hidden = true;
    }, 2300);
  }

  function setMainImage(index) {
    if (!productImages.length || !mainImage) return;
    const boundedIndex = (index + productImages.length) % productImages.length;
    const button = productImages[boundedIndex];
    if (!button) return;
    const newSource = button.dataset.image;
    window.clearTimeout(imageTimer);
    mainImage.classList.add("misutech_product_image_changing");
    imageTimer = window.setTimeout(() => {
      mainImage.src = newSource;
      if (modalImage) modalImage.src = newSource;
      mainImage.classList.remove("misutech_product_image_changing");
    }, 100);
    productImages.forEach((item) => item.classList.remove("misutech_product_active"));
    button.classList.add("misutech_product_active");
    activeImageIndex = boundedIndex;
  }

  function rebindGalleryEvents() {
    productImages = Array.from(document.querySelectorAll(".misutech_product_thumbnail"));
    productImages.forEach((button, index) => {
      button.addEventListener("click", () => setMainImage(index));
    });
    activeImageIndex = 0;
  }

  function openModal(name) {
    const target = document.querySelector(`[data-modal="${name}"]`);
    if (!target) return;
    if (name === "image") {
      syncLightboxGallery(activeImageIndex);
    }
    target.hidden = false;
    document.body.classList.add("misutech_product_no_scroll");
    const closeButton = target.querySelector("[data-close-modal]");
    if (closeButton) closeButton.focus();
  }

  function closeModal(target) {
    if (!target) return;
    if (target === imageModal) {
      stopLightboxSlideshow();
      if (document.fullscreenElement === imageModal) {
        document.exitFullscreen?.().catch(() => {});
      }
      resetLightboxTransform();
    }
    target.hidden = true;
    if (!document.querySelector(".misutech_product_modal:not([hidden])")) {
      document.body.classList.remove("misutech_product_no_scroll");
    }
  }

  /* ═══════════════════════════════════════════════════════════════════════════
     FULLSCREEN PRODUCT LIGHTBOX (Zoom, Pan, Rotate, Slideshow, Fullscreen, Swipe)
     ═══════════════════════════════════════════════════════════════════════════ */
  const lbCounter = imageModal?.querySelector("[data-lb-counter]");
  const lbCaption = imageModal?.querySelector("[data-lb-caption]");
  const lbZoomLevel = imageModal?.querySelector("[data-lb-zoom-level]");
  const lbStage = imageModal?.querySelector("[data-lb-stage]");
  const lbViewport = imageModal?.querySelector("[data-lb-viewport]");
  const lbPrevBtn = imageModal?.querySelector("[data-lb-prev]");
  const lbNextBtn = imageModal?.querySelector("[data-lb-next]");
  const lbThumbsBar = imageModal?.querySelector("[data-lb-thumbs-bar]");
  const lbThumbsWrap = imageModal?.querySelector("[data-lb-thumbs]");
  const lbDownloadBtn = imageModal?.querySelector("[data-lb-download]");
  const lbSlideshowBtn = imageModal?.querySelector("[data-lb-slideshow]");
  const lbFullscreenBtn = imageModal?.querySelector("[data-lb-fullscreen]");

  let lbImages = [];
  let lbIndex = 0;
  let lbScale = 1;
  let lbRotate = 0;
  let lbPanX = 0;
  let lbPanY = 0;
  let lbSlideshowTimer = null;
  let lbIsDragging = false;
  let lbDragStartX = 0;
  let lbDragStartY = 0;
  let lbTouchStartX = 0;
  let lbTouchStartY = 0;

  function applyLightboxTransform() {
    if (!modalImage) return;
    modalImage.style.transform = `translate3d(${lbPanX}px, ${lbPanY}px, 0) scale(${lbScale}) rotate(${lbRotate}deg)`;
    if (lbZoomLevel) lbZoomLevel.textContent = `${Math.round(lbScale * 100)}%`;
    if (lbViewport) {
      lbViewport.classList.toggle("is-zoomed", lbScale > 1.01);
    }
  }

  function resetLightboxTransform() {
    lbScale = 1;
    lbRotate = 0;
    lbPanX = 0;
    lbPanY = 0;
    applyLightboxTransform();
  }

  function setLightboxZoom(nextScale) {
    lbScale = Math.min(4, Math.max(1, Number(nextScale.toFixed(2))));
    if (lbScale <= 1) {
      lbPanX = 0;
      lbPanY = 0;
    }
    applyLightboxTransform();
  }

  function collectProductGalleryImages() {
    const thumbs = Array.from(document.querySelectorAll(".misutech_product_thumbnail"));
    const items = [];
    const seen = new Set();
    const fallbackAlt = document.querySelector(".misutech_product_title")?.textContent?.trim() || "Ảnh sản phẩm";

    thumbs.forEach((btn) => {
      const src = (btn.dataset.image || btn.querySelector("img")?.src || "").trim();
      if (!src || seen.has(src)) return;
      seen.add(src);
      items.push({
        src,
        alt: btn.querySelector("img")?.alt?.trim() || fallbackAlt,
      });
    });

    if (items.length === 0 && mainImage?.src) {
      items.push({
        src: mainImage.src,
        alt: mainImage.alt || fallbackAlt,
      });
    }

    return items;
  }

  function showLightboxImage(index) {
    if (!lbImages.length || !modalImage) return;
    lbIndex = (index + lbImages.length) % lbImages.length;
    const item = lbImages[lbIndex];

    resetLightboxTransform();
    modalImage.style.opacity = "0.35";
    modalImage.src = item.src;
    modalImage.alt = item.alt;
    window.requestAnimationFrame(() => {
      modalImage.style.opacity = "1";
    });

    if (lbCounter) lbCounter.textContent = `${lbIndex + 1} / ${lbImages.length}`;
    if (lbCaption) lbCaption.textContent = item.alt;
    if (lbDownloadBtn) lbDownloadBtn.href = item.src;

    if (lbThumbsWrap) {
      const thumbBtns = lbThumbsWrap.querySelectorAll(".misutech_lb_thumb");
      thumbBtns.forEach((el, idx) => {
        const active = idx === lbIndex;
        el.classList.toggle("is-active", active);
        if (active) {
          el.scrollIntoView({ behavior: "smooth", block: "nearest", inline: "center" });
        }
      });
    }

    // Đồng bộ ảnh chính trên trang sản phẩm
    if (productImages.length > lbIndex && lbIndex !== activeImageIndex) {
      setMainImage(lbIndex);
    }
  }

  function syncLightboxGallery(startIndex = 0) {
    lbImages = collectProductGalleryImages();
    const hasMultiple = lbImages.length > 1;

    if (lbPrevBtn) lbPrevBtn.hidden = !hasMultiple;
    if (lbNextBtn) lbNextBtn.hidden = !hasMultiple;
    if (lbThumbsBar) lbThumbsBar.hidden = !hasMultiple;
    if (lbSlideshowBtn) lbSlideshowBtn.hidden = !hasMultiple;

    if (lbThumbsWrap) {
      lbThumbsWrap.innerHTML = "";
      if (hasMultiple) {
        lbImages.forEach((img, idx) => {
          const btn = document.createElement("button");
          btn.type = "button";
          btn.className = "misutech_lb_thumb";
          btn.setAttribute("aria-label", `Xem ảnh ${idx + 1}`);
          btn.innerHTML = `<img src="${img.src}" alt="${img.alt.replace(/"/g, "&quot;")}" loading="lazy" decoding="async">`;
          btn.addEventListener("click", () => {
            stopLightboxSlideshow();
            showLightboxImage(idx);
          });
          lbThumbsWrap.appendChild(btn);
        });
      }
    }

    showLightboxImage(startIndex);
  }

  function stopLightboxSlideshow() {
    if (lbSlideshowTimer) {
      window.clearInterval(lbSlideshowTimer);
      lbSlideshowTimer = null;
    }
    if (lbSlideshowBtn) {
      lbSlideshowBtn.classList.remove("is-active");
      lbSlideshowBtn.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>';
    }
  }

  function toggleLightboxSlideshow() {
    if (lbImages.length <= 1) return;
    if (lbSlideshowTimer) {
      stopLightboxSlideshow();
      return;
    }
    if (lbSlideshowBtn) {
      lbSlideshowBtn.classList.add("is-active");
      lbSlideshowBtn.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>';
    }
    lbSlideshowTimer = window.setInterval(() => {
      showLightboxImage(lbIndex + 1);
    }, 2800);
  }

  function toggleLightboxFullscreen() {
    if (!imageModal) return;
    if (document.fullscreenElement === imageModal) {
      document.exitFullscreen?.().catch(() => {});
    } else {
      imageModal.requestFullscreen?.().catch(() => {});
    }
  }

  if (imageModal) {
    imageModal.querySelector("[data-lb-zoom-in]")?.addEventListener("click", () => setLightboxZoom(lbScale + 0.5));
    imageModal.querySelector("[data-lb-zoom-out]")?.addEventListener("click", () => setLightboxZoom(lbScale - 0.5));
    imageModal.querySelector("[data-lb-rotate-left]")?.addEventListener("click", () => {
      lbRotate -= 90;
      applyLightboxTransform();
    });
    imageModal.querySelector("[data-lb-rotate-right]")?.addEventListener("click", () => {
      lbRotate += 90;
      applyLightboxTransform();
    });
    imageModal.querySelector("[data-lb-reset]")?.addEventListener("click", resetLightboxTransform);
    lbSlideshowBtn?.addEventListener("click", toggleLightboxSlideshow);
    lbFullscreenBtn?.addEventListener("click", toggleLightboxFullscreen);

    lbPrevBtn?.addEventListener("click", () => {
      stopLightboxSlideshow();
      showLightboxImage(lbIndex - 1);
    });
    lbNextBtn?.addEventListener("click", () => {
      stopLightboxSlideshow();
      showLightboxImage(lbIndex + 1);
    });

    // Cuộn chuột để zoom mượt
    lbViewport?.addEventListener("wheel", (e) => {
      e.preventDefault();
      const delta = e.deltaY < 0 ? 0.25 : -0.25;
      setLightboxZoom(lbScale + delta);
    }, { passive: false });

    // Nhấp đúp chuột vào ảnh để phóng to 220% hoặc đặt lại 100%
    modalImage?.addEventListener("dblclick", () => {
      if (lbScale > 1.05) {
        resetLightboxTransform();
      } else {
        setLightboxZoom(2.2);
      }
    });

    // Kéo di chuyển ảnh khi đang zoom
    lbViewport?.addEventListener("mousedown", (e) => {
      if (lbScale <= 1.01) return;
      e.preventDefault();
      lbIsDragging = true;
      lbDragStartX = e.clientX - lbPanX;
      lbDragStartY = e.clientY - lbPanY;
      lbViewport.classList.add("is-dragging");
    });

    window.addEventListener("mousemove", (e) => {
      if (!lbIsDragging) return;
      lbPanX = e.clientX - lbDragStartX;
      lbPanY = e.clientY - lbDragStartY;
      applyLightboxTransform();
    });

    window.addEventListener("mouseup", () => {
      if (!lbIsDragging) return;
      lbIsDragging = false;
      lbViewport?.classList.remove("is-dragging");
    });

    // Vuốt trái/phải trên thiết bị cảm ứng hoặc kéo di chuyển khi đang zoom
    let lbIsTouchDragging = false;
    let lbTouchDragStartX = 0;
    let lbTouchDragStartY = 0;

    lbViewport?.addEventListener("touchstart", (e) => {
      if (e.touches.length !== 1) return;
      lbTouchStartX = e.touches[0].clientX;
      lbTouchStartY = e.touches[0].clientY;
      if (lbScale > 1.05) {
        lbIsTouchDragging = true;
        lbTouchDragStartX = e.touches[0].clientX - lbPanX;
        lbTouchDragStartY = e.touches[0].clientY - lbPanY;
        lbViewport.classList.add("is-dragging");
      }
    }, { passive: true });

    lbViewport?.addEventListener("touchmove", (e) => {
      if (!lbIsTouchDragging || lbScale <= 1.05 || e.touches.length !== 1) return;
      e.preventDefault();
      lbPanX = e.touches[0].clientX - lbTouchDragStartX;
      lbPanY = e.touches[0].clientY - lbTouchDragStartY;
      applyLightboxTransform();
    }, { passive: false });

    lbViewport?.addEventListener("touchend", (e) => {
      if (lbIsTouchDragging) {
        lbIsTouchDragging = false;
        lbViewport?.classList.remove("is-dragging");
      }
      if (lbScale > 1.05 || e.changedTouches.length !== 1) return;
      const dx = e.changedTouches[0].clientX - lbTouchStartX;
      const dy = e.changedTouches[0].clientY - lbTouchStartY;
      if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) {
        stopLightboxSlideshow();
        showLightboxImage(dx > 0 ? lbIndex - 1 : lbIndex + 1);
      }
    }, { passive: true });

    // Bấm vào nền tối xung quanh ảnh để đóng modal
    lbStage?.addEventListener("click", (e) => {
      if (e.target === lbStage || e.target === lbViewport) {
        closeModal(imageModal);
      }
    });

    document.addEventListener("fullscreenchange", () => {
      if (lbFullscreenBtn) {
        lbFullscreenBtn.classList.toggle("is-active", document.fullscreenElement === imageModal);
      }
    });
  }

  // Cho phép bấm trực tiếp vào ảnh chính sản phẩm để mở lightbox toàn màn hình
  mainImage?.addEventListener("click", () => {
    openModal("image");
  });

  function openCart() {
    if (!cartDrawer) return;
    cartDrawer.classList.add("misutech_product_open");
    cartDrawer.setAttribute("aria-hidden", "false");
    if (cartOverlay) cartOverlay.hidden = false;
    document.body.classList.add("misutech_product_no_scroll");
  }

  function closeCart() {
    if (!cartDrawer) return;
    cartDrawer.classList.remove("misutech_product_open");
    cartDrawer.setAttribute("aria-hidden", "true");
    if (cartOverlay) cartOverlay.hidden = true;
    if (!document.querySelector(".misutech_product_modal:not([hidden])")) {
      document.body.classList.remove("misutech_product_no_scroll");
    }
  }

  async function addToCart(productId, quantity, title, openAfterAdd) {
    const safeQuantity = Math.max(1, Number(quantity) || 1);
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || "";
    try {
      const response = await fetch("/api/v1/cart/add", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "Accept": "application/json",
          "X-CSRF-TOKEN": csrfToken,
        },
        body: JSON.stringify({
          product_id: Number(productId) || undefined,
          product_name: title,
          quantity: safeQuantity,
        }),
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        showToast(data.message || "Không thể thêm sản phẩm vào giỏ hàng.");
        return;
      }

      document.querySelectorAll(".misutech_home_cart_count, .misutech_product_cart_count").forEach((el) => {
        el.textContent = String(data.cart_count ?? 0);
      });
      const headerCartLabel = document.getElementById("misutechCartHeaderLabel");
      if (headerCartLabel) {
        headerCartLabel.textContent = `${data.cart_count ?? 0} sản phẩm`;
      }
      if (cartTotal) {
        const totalNum = Number(data.total_price) || 0;
        cartTotal.textContent = totalNum > 0 ? `${totalNum.toLocaleString("vi-VN")}đ` : "Liên hệ báo giá";
      }
      if (cartItems && Array.isArray(data.cart_items)) {
        cartItems.innerHTML = data.cart_items.map((it) => {
          const unitPrice = Number(it.price) > 0 ? `${Number(it.price).toLocaleString("vi-VN")}đ` : "Liên hệ báo giá";
          return `
            <article class="misutech_product_cart_item">
              <img class="misutech_product_cart_item_image" src="${it.thumbnail_url || "/storage/clients/imgs/products/no-image.png"}" alt="${it.name}">
              <div>
                <h3 class="misutech_product_cart_item_title">${it.name}</h3>
                <p class="misutech_product_cart_item_meta">${it.quantity} × ${unitPrice}</p>
              </div>
            </article>`;
        }).join("");
      }
      showToast(data.message || `Đã thêm “${title}” vào giỏ hàng.`);
      if (openAfterAdd) openCart();
    } catch (err) {
      console.error("Add to cart error:", err);
      showToast("Có lỗi kết nối khi thêm vào giỏ hàng.");
    }
  }

  // =========================================================================
  // HIGH-PERFORMANCE ZERO-LATENCY MODEL SWITCHER (Anti-Spam, 0ms Delay)
  // =========================================================================

  function renderModelData(data, targetUrl, pushState = true) {
    // 1. Update Title & Meta in Document Head
    if (data.meta_title) document.title = data.meta_title;
    const metaDesc = document.querySelector('meta[name="description"]');
    if (metaDesc && data.meta_description) {
      metaDesc.setAttribute("content", data.meta_description);
    }

    // 2. Update Breadcrumb & Heading
    const breadcrumbCurrent = document.getElementById("misutech_breadcrumb_current");
    if (breadcrumbCurrent) breadcrumbCurrent.textContent = data.name;

    const productTitle = document.getElementById("misutech_product_title");
    if (productTitle) productTitle.textContent = data.name;

    // 3. Update Price Box
    const priceBox = document.getElementById("misutech_product_price_box");
    if (priceBox) {
      if (data.has_sale) {
        priceBox.innerHTML = `
          <span class="misutech_product_price_sale">${data.sale_price_formatted}</span>
          <span class="misutech_product_price_original">${data.price_formatted}</span>
          <span class="misutech_product_price_badge">-${data.discount_percent}%</span>
        `;
      } else {
        priceBox.innerHTML = `<span class="misutech_product_price_sale">${data.price_formatted}</span>`;
      }
    }

    // 4. Update Short Description
    const summaryEl = document.getElementById("misutech_product_summary");
    if (summaryEl) {
      summaryEl.innerHTML = data.short_description || "";
    }

    // 5. Update SKU
    const skuEl = document.getElementById("misutech_product_sku");
    if (skuEl) skuEl.textContent = data.sku || "Đang cập nhật";

    // 6. Update Stock Status
    const stockDot = document.getElementById("misutech_product_stock_dot");
    const stockText = document.getElementById("misutech_product_stock_text");
    if (stockDot) {
      stockDot.className = `misutech_product_stock_dot ${data.is_active ? "" : "misutech_product_out_of_stock"}`;
    }
    if (stockText) {
      stockText.textContent = data.is_active ? "Còn hàng" : "Hết hàng";
    }

    // 7. Update Button Product IDs
    document.querySelectorAll("[data-product-id]").forEach((btn) => {
      btn.dataset.productId = data.id;
    });

    // 8. Update Description Tab Content
    const descPanel = document.getElementById("misutech_tab_panel_description");
    if (descPanel) {
      descPanel.innerHTML = data.content || '<p class="misutech_product_no_content">Chưa có mô tả chi tiết cho sản phẩm này.</p>';
    }

    // 9. Update Documents Tab Content (Embedded PDF Viewers)
    const docPanel = document.getElementById("misutech_tab_panel_document");
    if (docPanel) {
      if (data.catalogs && data.catalogs.length > 0) {
        let docsHtml = '<div class="misutech_product_catalog_viewers_list">';
        data.catalogs.forEach((doc) => {
          const docTitle = doc.filename || 'Tài liệu Catalog / Kỹ thuật';
          const downloadUrl = doc.download_url || (doc.id ? `/documents/${doc.id}/download` : doc.url);
          docsHtml += `
            <div class="misutech_product_catalog_item">
              <div class="misutech_product_catalog_header">
                <div class="misutech_product_catalog_title">
                  <span class="misutech_product_catalog_badge">PDF</span>
                  <strong>${docTitle}</strong>
                </div>
                <div class="misutech_product_catalog_actions">
                  <a href="${doc.url}" target="_blank" rel="noopener" class="misutech_product_catalog_btn">
                    Mở toàn màn hình ↗
                  </a>
                  <a href="${downloadUrl}" class="misutech_product_catalog_btn misutech_product_catalog_btn_primary">
                    Tải xuống ⤓
                  </a>
                </div>
              </div>
              <div class="misutech_product_catalog_frame_wrap">
                <iframe src="${doc.url}#navpanes=0&pagemode=none&view=FitH&toolbar=1" class="misutech_product_pdf_iframe" title="${docTitle}" loading="lazy"></iframe>
              </div>
            </div>
          `;
        });
        docsHtml += "</div>";
        docPanel.innerHTML = docsHtml;
      } else {
        docPanel.innerHTML = '<p class="misutech_product_no_content">Chưa có tài liệu catalog cho sản phẩm này.</p>';
      }
    }

    // 10. Update Gallery & Main Image
    const fallbackImage = '/storage/clients/imgs/products/no-image.png';
    const thumbUrl = data.thumbnail_url || fallbackImage;

    if (mainImage) {
      mainImage.src = thumbUrl;
      mainImage.alt = data.name;
    }
    if (modalImage) {
      modalImage.src = thumbUrl;
      modalImage.alt = data.name;
    }

    const thumbWrapper = document.getElementById("misutech_product_thumbnails_wrapper");
    if (thumbWrapper) {
      let thumbsHtml = `
        <button class="misutech_product_thumbnail misutech_product_active"
            type="button"
            data-image="${thumbUrl}"
            aria-label="Ảnh chính">
            <img class="misutech_product_thumbnail_image"
                src="${thumbUrl}"
                alt="${data.name}"
                onerror="this.src='${fallbackImage}'">
        </button>
      `;
      const dirWrapper = document.getElementById("misutech_product_direction_wrapper");
      if (data.gallery && data.gallery.length > 0) {
        thumbWrapper.style.display = "";
        if (dirWrapper) dirWrapper.style.display = "";
        data.gallery.forEach((img, i) => {
          thumbsHtml += `
            <button class="misutech_product_thumbnail"
                type="button"
                data-image="${img.url || fallbackImage}"
                aria-label="Ảnh ${i + 2}">
                <img class="misutech_product_thumbnail_image"
                    src="${img.url || fallbackImage}"
                    alt="${img.alt || data.name}"
                    onerror="this.src='${fallbackImage}'">
            </button>
          `;
        });
      } else {
        thumbWrapper.style.display = "none";
        if (dirWrapper) dirWrapper.style.display = "none";
      }
      thumbWrapper.innerHTML = thumbsHtml;
      rebindGalleryEvents();
    }

    // 11. Update URL via HTML5 History API (Preserves direct URL access & SEO)
    if (pushState) {
      const nextUrl = targetUrl || data.url || `/san-pham/${data.slug}`;
      window.history.pushState({ slug: data.slug, name: data.name }, data.name, nextUrl);
    }

    // 12. Update Share Modal input URL
    const shareInput = document.querySelector(".misutech_product_copy_input");
    if (shareInput) {
      shareInput.value = window.location.href;
    }
  }

  async function switchProductModel(slug, targetUrl, pushState = true) {
    // 1. Highlight nút active tức thì
    document.querySelectorAll(".misutech_product_model_btn").forEach((btn) => {
      const isTarget = btn.dataset.slug === slug;
      btn.classList.toggle("misutech_product_model_active", isTarget);
      if (isTarget) {
        btn.setAttribute("aria-current", "page");
      } else {
        btn.removeAttribute("aria-current");
      }
    });

    // 2. KIỂM TRA IN-MEMORY CACHE (0ms Latency - Không tốn tài nguyên Server)
    if (localModelStore[slug]) {
      renderModelData(localModelStore[slug], targetUrl, pushState);
      return;
    }

    // 3. FALLBACK: Fetch AJAX nếu chưa có trong cache, kèm AbortController chống spam
    if (currentAbortController) {
      currentAbortController.abort(); // Hủy request cũ nếu người dùng spam click
    }
    currentAbortController = new AbortController();

    const productGrid = document.querySelector(".misutech_product_product_grid");
    if (productGrid) productGrid.style.opacity = "0.75";

    try {
      const response = await fetch(`/san-pham/${slug}?ajax=1`, {
        signal: currentAbortController.signal,
        headers: {
          "X-Requested-With": "XMLHttpRequest",
          "Accept": "application/json",
        },
      });

      if (!response.ok) throw new Error("Network error");
      const data = await response.json();

      if (data && data.success) {
        localModelStore[slug] = data; // Cache lại ngay
        renderModelData(data, targetUrl, pushState);
      }
    } catch (err) {
      if (err.name !== "AbortError") {
        console.error("Model fetch error:", err);
      }
    } finally {
      if (productGrid) productGrid.style.opacity = "1";
    }
  }

  // Bind Model Switcher buttons
  function initModelSwitcher() {
    document.querySelectorAll(".misutech_product_model_btn").forEach((btn) => {
      btn.addEventListener("click", function (e) {
        e.preventDefault();
        const slug = this.dataset.slug;
        const targetUrl = this.href;
        if (!slug || this.classList.contains("misutech_product_model_active")) return;
        switchProductModel(slug, targetUrl, true);
      });
    });

    // Handle Browser Back / Forward buttons (popstate)
    window.addEventListener("popstate", function (e) {
      if (e.state && e.state.slug) {
        switchProductModel(e.state.slug, window.location.pathname, false);
      } else {
        const match = window.location.pathname.match(/\/san-pham\/([^/]+)/);
        if (match && match[1]) {
          switchProductModel(match[1], window.location.pathname, false);
        }
      }
    });
  }

  // Related products carousel
  function relatedCardsPerPage() {
    if (window.innerWidth <= 480) return 1;
    if (window.innerWidth <= 900) return 2;
    return 4;
  }

  function showRelatedPage(page) {
    if (!relatedTrack) return;
    const totalPages = Math.ceil(relatedCards.length / relatedVisibleCount);
    relatedPage = Math.max(0, Math.min(page, totalPages - 1));
    relatedTrack.style.transform = `translateX(-${relatedPage * 100}%)`;
    document.querySelectorAll(".misutech_product_related_dot").forEach((dot, index) => {
      dot.classList.toggle("misutech_product_active", index === relatedPage);
      dot.setAttribute("aria-pressed", String(index === relatedPage));
    });
  }

  function buildRelatedDots() {
    if (!relatedDots || !relatedCards.length) return;
    const newVisibleCount = relatedCardsPerPage();
    const totalPages = Math.ceil(relatedCards.length / newVisibleCount);
    relatedVisibleCount = newVisibleCount;
    relatedDots.innerHTML = "";
    for (let index = 0; index < totalPages; index += 1) {
      const button = document.createElement("button");
      button.className = "misutech_product_related_dot";
      button.type = "button";
      button.dataset.relatedPage = String(index);
      button.setAttribute("aria-label", `Related products page ${index + 1}`);
      button.addEventListener("click", () => showRelatedPage(index));
      relatedDots.appendChild(button);
    }
    showRelatedPage(Math.min(relatedPage, totalPages - 1));
  }

  // Initial event bindings
  rebindGalleryEvents();
  initModelSwitcher();

  document.querySelectorAll("[data-image-direction]").forEach((button) => {
    button.addEventListener("click", () => {
      setMainImage(activeImageIndex + Number(button.dataset.imageDirection));
    });
  });

  document.querySelector("[data-open-image]")?.addEventListener("click", () => openModal("image"));

  document.querySelectorAll("[data-quantity-change]").forEach((button) => {
    button.addEventListener("click", () => {
      const nextValue = Number(quantityInput.value) + Number(button.dataset.quantityChange);
      quantityInput.value = String(Math.max(1, Math.min(99, nextValue)));
    });
  });

  quantityInput?.addEventListener("change", () => {
    quantityInput.value = String(Math.max(1, Math.min(99, Number(quantityInput.value) || 1)));
  });

  document.querySelector(".misutech_product_add_button")?.addEventListener("click", (event) => {
    const btn = event.currentTarget;
    const productId = btn.dataset.productId || 0;
    const title = document.getElementById("misutech_product_title")?.textContent?.trim() || "Sản phẩm";
    addToCart(productId, quantityInput ? quantityInput.value : 1, title, true);
  });

  document.querySelector(".misutech_product_buy_now")?.addEventListener("click", () => {
    const questionModal = document.querySelector('[data-modal="question"]');
    if (questionModal) {
      openModal("question");
    } else {
      window.location.href = "/lien-he/";
    }
  });

  document.querySelector(".misutech_product_wishlist_button")?.addEventListener("click", (event) => {
    const button = event.currentTarget;
    const active = button.classList.toggle("misutech_product_active");
    button.textContent = active ? "★" : "☆";
    showToast(active ? "Đã thêm vào danh sách yêu thích." : "Đã xóa khỏi danh sách yêu thích.");
  });

  document.querySelectorAll("[data-open-modal]").forEach((button) => {
    button.addEventListener("click", () => openModal(button.dataset.openModal));
  });

  document.querySelectorAll("[data-close-modal]").forEach((button) => {
    button.addEventListener("click", () => closeModal(button.closest(".misutech_product_modal")));
  });

  document.querySelectorAll(".misutech_product_modal").forEach((modal) => {
    modal.addEventListener("click", (event) => {
      if (event.target === modal) closeModal(modal);
    });
  });

  document.querySelector(".misutech_product_copy_button")?.addEventListener("click", async () => {
    const input = document.querySelector(".misutech_product_copy_input");
    if (!input) return;
    try {
      await navigator.clipboard.writeText(input.value);
    } catch (error) {
      input.select();
      document.execCommand("copy");
    }
    showToast("Đã sao chép liên kết sản phẩm.");
  });

  const shareCopyBtn = document.getElementById("misutech_share_copy_btn");
  if (shareCopyBtn) {
    shareCopyBtn.addEventListener("click", async () => {
      try {
        await navigator.clipboard.writeText(window.location.href);
      } catch (e) {
        const dummy = document.createElement("input");
        document.body.appendChild(dummy);
        dummy.value = window.location.href;
        dummy.select();
        document.execCommand("copy");
        document.body.removeChild(dummy);
      }

      const originalSvg = shareCopyBtn.innerHTML;
      shareCopyBtn.classList.add("copied");
      shareCopyBtn.innerHTML = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';

      showToast("Đã sao chép liên kết sản phẩm!");

      setTimeout(() => {
        shareCopyBtn.classList.remove("copied");
        shareCopyBtn.innerHTML = originalSvg;
      }, 2000);
    });
  }

  const questionForm = document.querySelector(".misutech_product_question_form");
  if (questionForm) {
    questionForm.addEventListener("submit", async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      const submitBtn = form.querySelector(".misutech_product_modal_submit");
      const btnText = submitBtn ? submitBtn.querySelector(".btn-text") : null;
      const errorMsg = form.querySelector(".misutech_modal_error_msg");

      if (errorMsg) {
        errorMsg.style.display = "none";
        errorMsg.textContent = "";
      }

      if (submitBtn) {
        submitBtn.disabled = true;
        if (btnText) btnText.textContent = "ĐANG GỬI YÊU CẦU...";
      }

      try {
        const formData = new FormData(form);
        const response = await fetch(form.action, {
          method: "POST",
          body: formData,
          headers: {
            "X-Requested-With": "XMLHttpRequest",
            "Accept": "application/json"
          }
        });

        const result = await response.json();

        if (response.ok && result.success !== false) {
          form.reset();
          showToast(result.message || "Yêu cầu của bạn đã được gửi thành công. Đội ngũ MISUTECH sẽ liên hệ lại sớm nhất!");
          setTimeout(() => {
            closeModal(document.querySelector('[data-modal="question"]'));
          }, 800);
        } else {
          let errText = result.message || "Không thể gửi yêu cầu. Vui lòng kiểm tra lại thông tin.";
          if (result.errors) {
            const firstError = Object.values(result.errors)[0];
            if (Array.isArray(firstError)) errText = firstError[0];
          }
          if (errorMsg) {
            errorMsg.textContent = errText;
            errorMsg.style.display = "block";
          } else {
            showToast(errText);
          }
        }
      } catch (err) {
        console.error("Submit question error:", err);
        if (errorMsg) {
          errorMsg.textContent = "Có lỗi xảy ra khi kết nối máy chủ. Vui lòng thử lại hoặc gọi Hotline.";
          errorMsg.style.display = "block";
        } else {
          showToast("Có lỗi xảy ra khi kết nối. Vui lòng gọi trực tiếp Hotline.");
        }
      } finally {
        if (submitBtn) {
          submitBtn.disabled = false;
          if (btnText) btnText.textContent = "GỬI YÊU CẦU CHO KỸ SƯ";
        }
      }
    });
  }

  // Tab switching
  document.querySelectorAll(".misutech_product_tab_button").forEach((button) => {
    button.addEventListener("click", (e) => {
      e.preventDefault();
      const tabKey = button.dataset.tab;
      const targetPanel = document.querySelector(`[data-panel="${tabKey}"]`);
      if (!targetPanel) return;

      const tabsSection = document.querySelector(".misutech_product_tabs_section");
      const currentScrollY = window.scrollY;
      const tabsTop = tabsSection ? (tabsSection.getBoundingClientRect().top + window.scrollY - 110) : 0;

      // 1. Show target panel first to prevent body collapse
      targetPanel.hidden = false;
      targetPanel.classList.add("misutech_product_active");

      // 2. Hide other panels
      document.querySelectorAll(".misutech_product_tab_panel").forEach((panel) => {
        if (panel !== targetPanel) {
          panel.classList.remove("misutech_product_active");
          panel.hidden = true;
        }
      });

      // 3. Update tab buttons
      document.querySelectorAll(".misutech_product_tab_button").forEach((item) => {
        item.classList.remove("misutech_product_active");
        item.setAttribute("aria-selected", "false");
      });
      button.classList.add("misutech_product_active");
      button.setAttribute("aria-selected", "true");

      // 4. If current scroll was below tabs, smoothly keep user at the top of the tabs section
      if (tabsSection && currentScrollY > tabsTop) {
        window.scrollTo({ top: tabsTop, behavior: "smooth" });
      }

      // 5. Khi mở tab Tài liệu & Catalog, đảm bảo toàn bộ iframe có tham số chuẩn
      if (tabKey === "document") {
        targetPanel.querySelectorAll(".misutech_product_pdf_iframe").forEach((iframe) => {
          let src = iframe.getAttribute("src") || "";
          if (src && !src.includes("navpanes=0")) {
            const base = src.split("#")[0];
            iframe.setAttribute("src", `${base}#navpanes=0&pagemode=none&view=FitH&toolbar=1`);
          }
        });
      }
    });
  });

  document.querySelectorAll("[data-related-wishlist]").forEach((button) => {
    button.addEventListener("click", () => {
      const active = button.classList.toggle("misutech_product_active");
      button.textContent = active ? "♥" : "♡";
      showToast(active ? "Đã thêm vào yêu thích." : "Đã xóa khỏi yêu thích.");
    });
  });

  document.querySelector(".misutech_product_cart_trigger")?.addEventListener("click", openCart);
  document.querySelector(".misutech_product_cart_close")?.addEventListener("click", closeCart);
  cartOverlay?.addEventListener("click", closeCart);

  backTop?.addEventListener("click", () => window.scrollTo({ top: 0, behavior: "smooth" }));
  window.addEventListener("scroll", () => {
    if (backTop) backTop.classList.toggle("misutech_product_visible", window.scrollY > 600);
  }, { passive: true });

  window.addEventListener("resize", () => {
    const newVisibleCount = relatedCardsPerPage();
    if (newVisibleCount !== relatedVisibleCount) buildRelatedDots();
  });

  document.addEventListener("keydown", (event) => {
    const isLightboxOpen = imageModal && !imageModal.hidden;
    if (event.key === "Escape") {
      document.querySelectorAll(".misutech_product_modal:not([hidden])").forEach(closeModal);
      closeCart();
      return;
    }
    if (!isLightboxOpen) return;

    if (event.key === "ArrowLeft") {
      event.preventDefault();
      stopLightboxSlideshow();
      showLightboxImage(lbIndex - 1);
    } else if (event.key === "ArrowRight") {
      event.preventDefault();
      stopLightboxSlideshow();
      showLightboxImage(lbIndex + 1);
    } else if (event.key === "+" || event.key === "=") {
      event.preventDefault();
      setLightboxZoom(lbScale + 0.5);
    } else if (event.key === "-") {
      event.preventDefault();
      setLightboxZoom(lbScale - 0.5);
    } else if (event.key === "0") {
      event.preventDefault();
      resetLightboxTransform();
    } else if (event.key.toLowerCase() === "r") {
      event.preventDefault();
      lbRotate += 90;
      applyLightboxTransform();
    } else if (event.key.toLowerCase() === "f") {
      event.preventDefault();
      toggleLightboxFullscreen();
    } else if (event.key === " ") {
      event.preventDefault();
      toggleLightboxSlideshow();
    }
  });

  // Tự động tối ưu bảng trong nội dung mô tả sản phẩm (30/70 cho 2 cột & cuộn ngang mượt cho >= 3 cột)
  function initProductContentTables() {
    const tables = document.querySelectorAll(".misutech_product_content_body table, .misutech_product_tab_panel table");
    tables.forEach((table) => {
      // Phân loại số lượng cột từ hàng đầu tiên
      const firstRow = table.querySelector("tr");
      if (firstRow) {
        const cellCount = firstRow.children.length;
        if (cellCount === 2) {
          table.classList.add("misutech_table_2cols");
        } else if (cellCount >= 3) {
          table.classList.add("misutech_table_multicols");
        }
      }
      // Tự động bọc wrapper cuộn ngang mượt mà nếu chưa có
      if (!table.parentElement.classList.contains("misutech_product_table_wrapper")) {
        const wrapper = document.createElement("div");
        wrapper.className = "misutech_product_table_wrapper";
        table.parentNode.insertBefore(wrapper, table);
        wrapper.appendChild(table);
      }
    });
  }

  // ─── Xử lý Sản phẩm đã xem qua localStorage (0ms server latency, siêu nhanh) ───
  function initRecentProducts() {
    try {
      const STORAGE_KEY = "misutech_recent_products";
      const MAX_ITEMS = 10;
      const metaScript = document.getElementById("misutech_current_product_meta");
      if (!metaScript) return;

      let currentProd = null;
      try {
        currentProd = JSON.parse(metaScript.textContent);
      } catch (e) {
        return;
      }
      if (!currentProd || !currentProd.id) return;

      let stored = [];
      try {
        const raw = localStorage.getItem(STORAGE_KEY);
        stored = raw ? JSON.parse(raw) : [];
        if (!Array.isArray(stored)) stored = [];
      } catch (e) {
        stored = [];
      }

      // Lọc bỏ sản phẩm hiện tại để không lặp lại chính nó trong danh sách hiển thị
      const others = stored.filter((item) => item && item.id && item.id !== currentProd.id);

      const card = document.getElementById("misutechRecentProductsCard");
      const list = document.getElementById("misutechRecentProductsList");

      // Nếu có sản phẩm đã xem trước đó thì hiển thị tối đa 4-5 sản phẩm ở sidebar
      if (others.length > 0 && card && list) {
        const displayItems = others.slice(0, 5);
        let html = "";
        for (let i = 0; i < displayItems.length; i++) {
          const item = displayItems[i];
          const priceHtml = item.price
            ? `<div class="misutech_product_side_pprice"><strong>${item.price}</strong></div>`
            : "";

          html += `
            <a href="${item.url}" class="misutech_product_side_pitem" title="${item.name}">
              <div class="misutech_product_side_pimg">
                <img src="${item.image}" alt="${item.name}" loading="lazy" decoding="async">
              </div>
              <div class="misutech_product_side_pinfo">
                <h4 class="misutech_product_side_pname">${item.name}</h4>
                ${priceHtml}
              </div>
            </a>
          `;
        }
        list.innerHTML = html;
        card.style.display = "block";
      }

      // Cập nhật lại localStorage: đưa sản phẩm hiện tại lên đầu, lưu tối đa 10 items
      const updated = [currentProd, ...others].slice(0, MAX_ITEMS);
      localStorage.setItem(STORAGE_KEY, JSON.stringify(updated));
    } catch (err) {
      console.warn("Could not handle recent products:", err);
    }
  }

  initProductContentTables();
  buildRelatedDots();
  initRecentProducts();
})();
