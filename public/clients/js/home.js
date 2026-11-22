(() => {
    const slides = document.querySelectorAll(".misutech_home_hero_slide");
    const dots = document.querySelectorAll(".misutech_home_hero_dot");
    let currentSlide = 0;
    let slideInterval;

    function showSlide(index) {
        if (slides.length === 0) return;

        slides.forEach((slide, i) => {
            if (i === index) {
                slide.style.opacity = "1";
                slide.style.transform = "scale(1)";
                slide.style.pointerEvents = "auto";
                slide.style.zIndex = "1";
                if (dots[i]) dots[i].setAttribute("aria-current", "true");
            } else {
                slide.style.opacity = "0";
                slide.style.transform = "scale(1.05)";
                slide.style.pointerEvents = "none";
                slide.style.zIndex = "0";
                if (dots[i]) dots[i].setAttribute("aria-current", "false");
            }
        });
        currentSlide = index;
    }

    function nextSlide() {
        if (slides.length <= 1) return;
        showSlide((currentSlide + 1) % slides.length);
    }

    function startSlider() {
        if (slides.length > 1) {
            slideInterval = setInterval(nextSlide, 5000);
        }
    }

    function resetSlider() {
        clearInterval(slideInterval);
        startSlider();
    }

    dots.forEach((dot, index) => {
        dot.addEventListener("click", () => {
            showSlide(index);
            resetSlider();
        });
    });

    startSlider();

    /* Partners Carousel ("Các đối tác của chúng tôi") */
    const partnerSlider = document.querySelector("[data-partners-slider]");
    if (partnerSlider) {
        const track = partnerSlider.querySelector("[data-partners-track]");
        const pages = partnerSlider.querySelectorAll("[data-partners-page]");
        const prevBtn = partnerSlider.querySelector("[data-partners-prev]");
        const nextBtn = partnerSlider.querySelector("[data-partners-next]");
        const partnerDots = document.querySelectorAll("[data-partners-dot]");
        const totalPages = pages.length;
        let currentPage = 0;
        let partnerTimer;
        let touchStartX = 0;

        function goToPartnerPage(index) {
            if (!track || totalPages <= 0) return;
            currentPage = (index + totalPages) % totalPages;
            track.style.transform = `translate3d(-${currentPage * 100}%, 0, 0)`;
            partnerDots.forEach((dot, i) => {
                dot.setAttribute("aria-current", i === currentPage ? "true" : "false");
            });
        }

        function startPartnerAuto() {
            if (totalPages <= 1) return;
            clearInterval(partnerTimer);
            partnerTimer = setInterval(() => goToPartnerPage(currentPage + 1), 6000);
        }

        if (totalPages <= 1) {
            if (prevBtn) prevBtn.style.display = "none";
            if (nextBtn) nextBtn.style.display = "none";
        } else {
            if (prevBtn) {
                prevBtn.addEventListener("click", () => {
                    goToPartnerPage(currentPage - 1);
                    startPartnerAuto();
                });
            }
            if (nextBtn) {
                nextBtn.addEventListener("click", () => {
                    goToPartnerPage(currentPage + 1);
                    startPartnerAuto();
                });
            }
            partnerDots.forEach((dot, idx) => {
                dot.addEventListener("click", () => {
                    goToPartnerPage(idx);
                    startPartnerAuto();
                });
            });

            partnerSlider.addEventListener("mouseenter", () => clearInterval(partnerTimer));
            partnerSlider.addEventListener("mouseleave", startPartnerAuto);

            partnerSlider.addEventListener(
                "touchstart",
                (e) => {
                    touchStartX = e.changedTouches[0].clientX;
                },
                { passive: true },
            );
            partnerSlider.addEventListener(
                "touchend",
                (e) => {
                    const deltaX = e.changedTouches[0].clientX - touchStartX;
                    if (Math.abs(deltaX) > 40) {
                        goToPartnerPage(deltaX < 0 ? currentPage + 1 : currentPage - 1);
                        startPartnerAuto();
                    }
                },
                { passive: true },
            );

            startPartnerAuto();
        }
    }
})();
