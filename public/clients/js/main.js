(() => {
    const searchForm = document.querySelector(".misutech_home_search");
    const searchInput = document.querySelector(".misutech_home_search_input");
    const productCards = [
        ...document.querySelectorAll(".misutech_home_product_card"),
    ];
    const emptyState = document.querySelector(".misutech_home_empty");
    const cartCount = document.querySelector(".misutech_home_cart_count");
    const cartLabel =
        document.getElementById("misutechCartHeaderLabel") ||
        document.querySelector(
            ".misutech_home_header_action:last-child .misutech_home_header_action_copy strong",
        );
    const toast = document.querySelector(".misutech_home_toast");
    let cart = 0;
    let toastTimer;

    const showToast = (message) => {
        const toastEl = document.getElementById("misutech_global_toast") || document.querySelector(".misutech_toast, .misutech_home_toast, .misutech_product_toast");
        if (!toastEl) return;
        toastEl.textContent = message;
        toastEl.removeAttribute("hidden");
        toastEl.setAttribute("aria-hidden", "false");
        toastEl.classList.add("show");
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => {
            toastEl.classList.remove("show");
            toastEl.setAttribute("aria-hidden", "true");
            toastEl.setAttribute("hidden", "true");
            toastEl.textContent = "";
        }, 2200);
    };

    window.showToast = showToast;


    let lastCartClickTime = 0;

    document.addEventListener("click", async (event) => {
        const cartBtn = event.target.closest("[data-cart]");
        if (!cartBtn) return;
        
        event.preventDefault();
        
        const now = Date.now();
        if (now - lastCartClickTime < 400) {
            return;
        }
        lastCartClickTime = now;
        
        const productCard = cartBtn.closest(".misutech_home_product_card") 
            || cartBtn.closest(".misutech_home_product_detail_wrapper") 
            || cartBtn.closest(".misutech_product_related_card")
            || cartBtn.closest(".misutech_series_card")
            || cartBtn.closest(".misutech_series_product_card")
            || cartBtn.closest("article");
        const productId = Number(cartBtn.dataset.productId || (productCard ? productCard.dataset.productId : 0)) || 0;
        const productName = cartBtn.dataset.product || (productCard ? (productCard.dataset.name || productCard.dataset.productName || "Sản phẩm") : "Sản phẩm");
        const img = productCard ? productCard.querySelector("img") : null;
        
        // Button visual feedback
        const originalText = cartBtn.innerHTML;
        cartBtn.innerHTML = "✓";
        cartBtn.classList.add("added");
        setTimeout(() => {
            cartBtn.innerHTML = originalText;
            cartBtn.classList.remove("added");
        }, 1500);
        
        if (img && cartCount) {
            const imgRect = img.getBoundingClientRect();
            const cartRect = cartCount.getBoundingClientRect();
            
            const flyingImg = img.cloneNode(true);
            flyingImg.style.position = "fixed";
            flyingImg.style.zIndex = "999999";
            flyingImg.style.left = `${imgRect.left}px`;
            flyingImg.style.top = `${imgRect.top}px`;
            flyingImg.style.width = `${imgRect.width}px`;
            flyingImg.style.height = `${imgRect.height}px`;
            flyingImg.style.transition = "all 0.8s cubic-bezier(0.25, 1, 0.5, 1)";
            flyingImg.style.borderRadius = "50%";
            flyingImg.style.opacity = "0.8";
            flyingImg.style.objectFit = "cover";
            flyingImg.style.boxShadow = "0 10px 25px rgba(0,0,0,0.2)";
            
            document.body.appendChild(flyingImg);
            
            requestAnimationFrame(() => {
                flyingImg.style.left = `${cartRect.left - 10}px`;
                flyingImg.style.top = `${cartRect.top - 10}px`;
                flyingImg.style.width = "20px";
                flyingImg.style.height = "20px";
                flyingImg.style.opacity = "0.2";
            });
            
            setTimeout(() => {
                flyingImg.remove();
                if (cartCount.parentElement && cartCount.parentElement.parentElement) {
                    cartCount.parentElement.parentElement.style.transition = "transform 0.2s";
                    cartCount.parentElement.parentElement.style.transform = "scale(1.2)";
                    setTimeout(() => {
                        cartCount.parentElement.parentElement.style.transform = "scale(1)";
                    }, 200);
                }
            }, 800);
        }
        
        try {
            const csrfTokenMeta = document.querySelector('meta[name="csrf-token"]');
            const response = await fetch('/api/v1/cart/add', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfTokenMeta ? csrfTokenMeta.content : ''
                },
                body: JSON.stringify({
                    product_id: productId || undefined,
                    product_name: productName,
                    quantity: 1
                })
            });
            
            if (response.ok) {
                const data = await response.json();
                if (typeof data.cart_count !== "undefined") {
                    document.querySelectorAll(".misutech_home_cart_count").forEach((el) => {
                        el.textContent = data.cart_count;
                    });
                    if (cartLabel) cartLabel.textContent = data.cart_count + " sản phẩm";
                }
                showToast(data.message || ("Đã thêm “" + productName + "” vào giỏ hàng!"));
            } else if (response.status === 429) {
                const data = await response.json();
                showToast(data.message || "Vui lòng chậm lại tránh spam làm hỏng database");
            } else {
                showToast("Có lỗi xảy ra khi thêm vào giỏ hàng");
            }
        } catch (error) {
            console.error("Lỗi khi thêm vào giỏ hàng:", error);
            showToast("Có lỗi xảy ra khi thêm vào giỏ hàng");
        }
    });

    document
        .querySelectorAll(".misutech_home_product_favorite")
        .forEach((button) => {
            button.addEventListener("click", () => {
                const active = button.getAttribute("aria-pressed") !== "true";
                button.setAttribute("aria-pressed", String(active));
                button.textContent = active ? "♥" : "♡";
            });
        });

    const categoryToggle = document.querySelector(
        ".misutech_home_nav_categories",
    );
    const dropdownWrapper = document.querySelector(
        ".misutech_home_dropdown_wrapper",
    );
    const menuBackdrop = document.getElementById("menuBackdrop");

    function closeCategoryMenu() {
        if (dropdownWrapper && !dropdownWrapper.hidden) {
            dropdownWrapper.hidden = true;
            if (categoryToggle) categoryToggle.setAttribute("aria-expanded", "false");
            if (menuBackdrop) menuBackdrop.classList.remove("active");
        }
    }

    function openCategoryMenu() {
        if (dropdownWrapper) {
            dropdownWrapper.hidden = false;
            if (categoryToggle) categoryToggle.setAttribute("aria-expanded", "true");
            if (menuBackdrop) menuBackdrop.classList.add("active");
        }
    }

    if (categoryToggle) {
        categoryToggle.addEventListener("click", (e) => {
            e.stopPropagation();
            if (dropdownWrapper) {
                if (dropdownWrapper.hidden) {
                    openCategoryMenu();
                } else {
                    closeCategoryMenu();
                }
            }
        });
    }

    if (menuBackdrop) {
        menuBackdrop.addEventListener("click", () => {
            closeCategoryMenu();
        });
    }

    document.addEventListener("click", (e) => {
        if (dropdownWrapper && !dropdownWrapper.hidden) {
            if (!dropdownWrapper.contains(e.target) && !categoryToggle.contains(e.target)) {
                closeCategoryMenu();
            }
        }
    });

    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape") {
            closeCategoryMenu();
        }
    });

    document
        .querySelector(".misutech_home_newsletter")
        ?.addEventListener("submit", (event) => {
            event.preventDefault();
            showToast("Đăng ký nhận ưu đãi thành công");
            event.currentTarget.reset();
        });

    const scrollTopBtn = document.querySelector("[data-scroll-top]");
    if (scrollTopBtn) {
        scrollTopBtn.addEventListener("click", (e) => {
            e.preventDefault();
            window.scrollTo({ top: 0, behavior: "smooth" });
        });

        const checkScrollTopVisibility = () => {
            if (window.scrollY > 280) {
                scrollTopBtn.classList.add("is-visible");
            } else {
                scrollTopBtn.classList.remove("is-visible");
            }
        };

        window.addEventListener("scroll", checkScrollTopVisibility, { passive: true });
        checkScrollTopVisibility();
    }

    document
        .querySelector("[data-chat]")
        ?.addEventListener("click", () =>
            showToast("MISUTECH sẵn sàng tư vấn cho bạn"),
        );

    const headerGroup = document.querySelector(".misutech_header_sticky_group");
    const header = document.querySelector(".misutech_home_header");
    const nav = document.querySelector(".misutech_home_nav");
    let isStickyActive = false;

    window.addEventListener("scroll", () => {
        const scrollY = window.scrollY;
        if (scrollY > 80) {
            if (!isStickyActive) {
                isStickyActive = true;
                headerGroup?.classList.add("is-sticky");
                header?.classList.add("is-sticky");
                nav?.classList.add("is-sticky");
            }
        } else if (scrollY < 30) {
            if (isStickyActive) {
                isStickyActive = false;
                headerGroup?.classList.remove("is-sticky");
                header?.classList.remove("is-sticky");
                nav?.classList.remove("is-sticky");
            }
        }
    }, { passive: true });

    /* ═══════════════════════════════════════════════════════════════════════════
       MOBILE OFF-CANVAS DRAWER MENU HANDLERS
       ═══════════════════════════════════════════════════════════════════════════ */
    const btnOpenDrawer = document.getElementById("btnOpenMobileDrawer");
    const btnCloseDrawer = document.getElementById("btnCloseMobileDrawer");
    const mobileDrawer = document.getElementById("mobileDrawer");
    const drawerBackdrop = document.getElementById("mobileDrawerBackdrop");

    function openMobileDrawer() {
        if (mobileDrawer && drawerBackdrop) {
            mobileDrawer.classList.add("is-open");
            mobileDrawer.setAttribute("aria-hidden", "false");
            drawerBackdrop.classList.add("is-active");
            drawerBackdrop.setAttribute("aria-hidden", "false");
            document.body.style.overflow = "hidden";
        }
    }

    function closeMobileDrawer() {
        if (mobileDrawer && drawerBackdrop) {
            mobileDrawer.classList.remove("is-open");
            mobileDrawer.setAttribute("aria-hidden", "true");
            drawerBackdrop.classList.remove("is-active");
            drawerBackdrop.setAttribute("aria-hidden", "true");
            document.body.style.overflow = "";
        }
    }

    if (btnOpenDrawer) {
        btnOpenDrawer.addEventListener("click", (e) => {
            e.preventDefault();
            openMobileDrawer();
        });
    }

    if (btnCloseDrawer) {
        btnCloseDrawer.addEventListener("click", (e) => {
            e.preventDefault();
            closeMobileDrawer();
        });
    }

    if (drawerBackdrop) {
        drawerBackdrop.addEventListener("click", () => {
            closeMobileDrawer();
        });
    }

    // Drawer Tabs Switcher
    const drawerTabs = document.querySelectorAll(".misutech_drawer_tab");
    const panelCategories = document.getElementById("drawerPanelCategories");
    const panelMenu = document.getElementById("drawerPanelMenu");

    drawerTabs.forEach((tab) => {
        tab.addEventListener("click", () => {
            drawerTabs.forEach((t) => t.classList.remove("is-active"));
            tab.classList.add("is-active");

            const tabType = tab.dataset.drawerTab;
            if (tabType === "categories") {
                if (panelCategories) {
                    panelCategories.classList.add("is-active");
                    panelCategories.removeAttribute("hidden");
                }
                if (panelMenu) {
                    panelMenu.classList.remove("is-active");
                    panelMenu.setAttribute("hidden", "true");
                }
            } else {
                if (panelCategories) {
                    panelCategories.classList.remove("is-active");
                    panelCategories.setAttribute("hidden", "true");
                }
                if (panelMenu) {
                    panelMenu.classList.add("is-active");
                    panelMenu.removeAttribute("hidden");
                }
            }
        });
    });

    // Drawer Tree Accordion - Level 1 Categories
    document.querySelectorAll(".misutech_drawer_toggle").forEach((toggleBtn) => {
        toggleBtn.addEventListener("click", (e) => {
            e.stopPropagation();
            const parentItem = toggleBtn.closest(".misutech_drawer_item");
            const subMenu = parentItem ? parentItem.querySelector(".misutech_drawer_submenu") : null;
            if (subMenu) {
                const isExpanded = parentItem.classList.contains("is-expanded");
                if (isExpanded) {
                    parentItem.classList.remove("is-expanded");
                    subMenu.hidden = true;
                } else {
                    parentItem.classList.add("is-expanded");
                    subMenu.hidden = false;
                }
            }
        });
    });

    // Drawer Tree Accordion - Level 2 Grandchild Categories
    document.querySelectorAll(".misutech_drawer_grandtoggle").forEach((grandBtn) => {
        grandBtn.addEventListener("click", (e) => {
            e.stopPropagation();
            const parentSubItem = grandBtn.closest(".misutech_drawer_subitem");
            const grandMenu = parentSubItem ? parentSubItem.querySelector(".misutech_drawer_grandmenu") : null;
            if (grandMenu) {
                const isExpanded = parentSubItem.classList.contains("is-expanded");
                if (isExpanded) {
                    parentSubItem.classList.remove("is-expanded");
                    grandMenu.hidden = true;
                } else {
                    parentSubItem.classList.add("is-expanded");
                    grandMenu.hidden = false;
                }
            }
        });
    });

    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && mobileDrawer && mobileDrawer.classList.contains("is-open")) {
            closeMobileDrawer();
        }
    });

    /* ═══════════════════════════════════════════════════════════════════════════
       HEADER SEARCH AUTOCOMPLETE (>= 3 ký tự, đợi 1s sau khi ngừng gõ, khóa tuần tự)
       ═══════════════════════════════════════════════════════════════════════════ */
    const escapeHtml = (str) =>
        String(str ?? "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");

    const initSearchAutocomplete = (formEl, inputEl) => {
        if (!formEl || !inputEl) return;

        const MIN_CHARS = 3;
        const DELAY_MS = 1000;

        let dropdown = formEl.querySelector(".misutech_search_suggest_box");
        if (!dropdown) {
            dropdown = document.createElement("div");
            dropdown.className = "misutech_search_suggest_box";
            dropdown.setAttribute("role", "listbox");
            dropdown.hidden = true;
            formEl.appendChild(dropdown);
        }

        let debounceTimer = null;
        let isFetching = false;
        let hasPendingInput = false;
        let lastFetchedQuery = "";

        const hideDropdown = () => {
            dropdown.hidden = true;
        };

        const showDropdown = () => {
            dropdown.hidden = false;
        };

        const renderLoading = () => {
            dropdown.innerHTML = '<div class="misutech_search_suggest_status">Đang tìm kiếm gợi ý...</div>';
            showDropdown();
        };

        const renderResults = (data, query) => {
            const items = Array.isArray(data?.items) ? data.items : [];
            const viewAllUrl = data?.view_all_url || `/shop/?q=${encodeURIComponent(query)}`;

            if (items.length === 0) {
                dropdown.innerHTML = `<div class="misutech_search_suggest_status">Không tìm thấy sản phẩm phù hợp với “${escapeHtml(query)}”</div>`;
                showDropdown();
                return;
            }

            const listHtml = items
                .map((item) => {
                    const skuHtml = item.sku
                        ? `<span class="misutech_search_suggest_sku">SKU: ${escapeHtml(item.sku)}</span>`
                        : "";
                    const oldPriceHtml = item.old_price_formatted
                        ? `<span class="misutech_search_suggest_old">${escapeHtml(item.old_price_formatted)}</span>`
                        : "";

                    return `
                        <li class="misutech_search_suggest_item">
                            <a href="${escapeHtml(item.url)}">
                                <img class="misutech_search_suggest_thumb" src="${escapeHtml(item.thumbnail)}" alt="${escapeHtml(item.name)}" loading="lazy" decoding="async" />
                                <div class="misutech_search_suggest_meta">
                                    <div class="misutech_search_suggest_name">${escapeHtml(item.name)}</div>
                                    <div class="misutech_search_suggest_sub">
                                        <span class="misutech_search_suggest_price">${escapeHtml(item.price_formatted)}</span>
                                        ${oldPriceHtml}
                                        ${skuHtml}
                                    </div>
                                </div>
                            </a>
                        </li>
                    `;
                })
                .join("");

            dropdown.innerHTML = `
                <ul class="misutech_search_suggest_list">${listHtml}</ul>
                <div class="misutech_search_suggest_footer">
                    <a href="${escapeHtml(viewAllUrl)}">Xem tất cả kết quả cho “${escapeHtml(query)}” ›</a>
                </div>
            `;
            showDropdown();
        };

        const scheduleSearch = () => {
            if (debounceTimer) {
                clearTimeout(debounceTimer);
            }
            debounceTimer = setTimeout(() => {
                debounceTimer = null;
                executeSearch();
            }, DELAY_MS);
        };

        const executeSearch = async () => {
            if (isFetching) return;

            const currentQuery = inputEl.value.trim();
            if (currentQuery.length < MIN_CHARS) {
                lastFetchedQuery = "";
                hasPendingInput = false;
                hideDropdown();
                return;
            }

            if (currentQuery === lastFetchedQuery) {
                if (dropdown.innerHTML.trim() !== "") {
                    showDropdown();
                }
                return;
            }

            isFetching = true;
            hasPendingInput = false;
            lastFetchedQuery = currentQuery;
            renderLoading();

            try {
                const resp = await fetch(`/api/v1/products/suggest?q=${encodeURIComponent(currentQuery)}`, {
                    headers: { Accept: "application/json" },
                });
                if (!resp.ok) {
                    throw new Error("Search suggest failed");
                }
                const data = await resp.json();
                // Chỉ hiển thị nếu ô tìm kiếm vẫn đang đủ >= 3 ký tự
                if (inputEl.value.trim().length >= MIN_CHARS) {
                    renderResults(data, currentQuery);
                } else {
                    hideDropdown();
                }
            } catch {
                if (inputEl.value.trim().length >= MIN_CHARS) {
                    dropdown.innerHTML = '<div class="misutech_search_suggest_status">Không thể tải gợi ý lúc này.</div>';
                    showDropdown();
                }
            } finally {
                isFetching = false;

                const latestQuery = inputEl.value.trim();
                if (latestQuery.length < MIN_CHARS) {
                    lastFetchedQuery = "";
                    hasPendingInput = false;
                    hideDropdown();
                } else if (hasPendingInput && latestQuery !== lastFetchedQuery) {
                    // Sau khi request trước chạy xong hoàn toàn, mới bắt đầu đợi 1s cho lần gõ cuối cùng
                    hasPendingInput = false;
                    scheduleSearch();
                }
            }
        };

        inputEl.addEventListener("input", () => {
            const q = inputEl.value.trim();

            if (q.length < MIN_CHARS) {
                if (debounceTimer) {
                    clearTimeout(debounceTimer);
                    debounceTimer = null;
                }
                hasPendingInput = false;
                lastFetchedQuery = "";
                hideDropdown();
                return;
            }

            // Nếu đang có request chạy: không đặt timer mới, chỉ ghi nhận để sau khi chạy xong mới đợi 1s
            if (isFetching) {
                hasPendingInput = true;
                return;
            }

            // Nếu quay lại đúng từ khóa vừa tìm xong thì hiện lại ngay không cần gọi API
            if (q === lastFetchedQuery && dropdown.innerHTML.trim() !== "") {
                if (debounceTimer) {
                    clearTimeout(debounceTimer);
                    debounceTimer = null;
                }
                showDropdown();
                return;
            }

            scheduleSearch();
        });

        inputEl.addEventListener("focus", () => {
            const q = inputEl.value.trim();
            if (q.length >= MIN_CHARS && q === lastFetchedQuery && dropdown.innerHTML.trim() !== "") {
                showDropdown();
            }
        });

        inputEl.addEventListener("keydown", (e) => {
            if (e.key === "Escape") {
                hideDropdown();
            }
        });

        document.addEventListener("click", (e) => {
            if (!formEl.contains(e.target)) {
                hideDropdown();
            }
        });
    };

    initSearchAutocomplete(searchForm, searchInput);
    initSearchAutocomplete(
        document.querySelector(".misutech_drawer_search"),
        document.querySelector(".misutech_drawer_search_input")
    );
})();

