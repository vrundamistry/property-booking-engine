/**
 * PBE Frontend Script
 *
 * Handles UI interactions like datepickers and other refinements.
 */

jQuery(document).ready(function ($) {

    // Initialize both Global Search Pairs
    function initDatePair(inID, outID) {
        if (typeof flatpickr === 'undefined') return;

        const $in = $(inID);
        const $out = $(outID);
        if (!$in.length || !$out.length) return;

        const inFp = flatpickr(inID, {
            minDate: "today",
            dateFormat: "Y-m-d",
            onChange: function (selectedDates, dateStr) {
                outFp.set("minDate", dateStr);
            }
        });

        const outFp = flatpickr(outID, {
            minDate: "today",
            dateFormat: "Y-m-d"
        });
    }

    // Initialize Global Search Form if present
    initDatePair('#pbe-sf-checkin', '#pbe-sf-checkout');

    // Reusable Custom Dropdown Logic
    $(document).on('click', '.pbe-custom-dropdown .pbe-cd-trigger', function (e) {
        e.stopPropagation();
        const $dropdown = $(this).closest('.pbe-custom-dropdown');
        $('.pbe-custom-dropdown').not($dropdown).removeClass('is-open');
        $dropdown.toggleClass('is-open');
    });

    $(document).on('click', '.pbe-custom-dropdown .pbe-cd-options li', function () {
        const $dropdown = $(this).closest('.pbe-custom-dropdown');
        const $label = $dropdown.find('.pbe-cd-label');
        const $input = $dropdown.find('input[type="hidden"]');
        const val = $(this).data('value');
        const text = $(this).text().trim();

        $input.val(val);
        $label.text(text);
        $(this).addClass('active').siblings().removeClass('active');
        $dropdown.removeClass('is-open');
    });

    $(document).on('click', function () {
        $('.pbe-custom-dropdown').removeClass('is-open');
    });

    // --- Property Gallery Slider & Lightbox ---
    const thumbSwiperAvailable = typeof Swiper !== 'undefined';
    const $thumbSlider = jQuery('.pbe-thumb-slider');
    const $mainSlider = jQuery('.pbe-main-slider');

    if (thumbSwiperAvailable && $thumbSlider.length && $mainSlider.length && !$mainSlider.hasClass('pbe-static-hero')) {
        const thumbSwiper = new Swiper(".pbe-thumb-slider", {
            spaceBetween: 12, slidesPerView: 4, freeMode: true, watchSlidesProgress: true,
            breakpoints: { 320: { slidesPerView: 3 }, 460: { slidesPerView: 4 }, 576: { slidesPerView: 5 }, 767: { slidesPerView: 6 }, 1200: { slidesPerView: 8 } }
        });

        const mainSwiper = new Swiper(".pbe-main-slider", {
            spaceBetween: 0, loop: true, navigation: { nextEl: ".swiper-button-next", prevEl: ".swiper-button-prev" },
            thumbs: { swiper: thumbSwiper },
            on: {
                slideChange: function () {
                    const realIndex = this.realIndex;
                    jQuery('.pbe-mini-thumb-item').removeClass('active');
                    jQuery(`.pbe-mini-thumb-item[data-index="${realIndex}"]`).addClass('active');
                }
            }
        });
    }

    // --- Listing Card Swiper ---
    if (typeof Swiper !== 'undefined' && $('.pbe-card-swiper').length) {
        new Swiper(".pbe-card-swiper", {
            loop: true,
            speed: 500,
            navigation: {
                nextEl: ".swiper-button-next",
                prevEl: ".swiper-button-prev",
            },
            pagination: {
                el: ".swiper-pagination",
                clickable: true,
                dynamicBullets: true,
            },
            keyboard: {
                enabled: true,
                onlyInViewport: true,
            }
        });
    }

    if (typeof GLightbox !== 'undefined') {
        const lightbox = GLightbox({
            selector: '.pbe-glightbox',
            touchNavigation: true,
            loop: true
        });

        lightbox.on('open', () => {
            const index = lightbox.index + 1;
            const total = lightbox.elements.length;
            if ($('#pbe-lb-counter').length === 0) {
                $('<div id="pbe-lb-counter" class="pbe-photo-counter lb">' + index + ' / ' + total + '</div>').appendTo('body');
            }
        });

        lightbox.on('slide_changed', ({ prev, current }) => {
            const index = current.index + 1;
            const total = lightbox.elements.length;
            $('#pbe-lb-counter').text(index + ' / ' + total);
        });

        lightbox.on('close', () => {
            $('#pbe-lb-counter').fadeOut(200, function () { $(this).remove(); });
        });
    }

    // Descriptions & Amenities
    $(document).on('click', '.pbe-read-more-toggle', function () {
        $(this).closest('.pbe-description-wrap').toggleClass('expanded');
        $(this).text($(this).text() === 'Read more' ? 'Read less' : 'Read more');
    });

    $(document).on('click', '.pbe-aa-trigger', function (e) {
        e.preventDefault();
        $(this).closest('.pbe-aa-item').toggleClass('active').siblings().removeClass('active');
    });

    // Review Submission
    $(document).on('submit', '#pbe-submit-review-form', function (e) {
        e.preventDefault();
        const $form = $(this), $status = $('#pbe-review-status'), $btn = $form.find('.pbe-submit-btn');
        const formData = new FormData(this); formData.append('action', 'pbe_submit_review');
        $btn.prop('disabled', true).text('Submitting...');
        $.ajax({
            url: pbe_ajax.url, type: 'POST', data: formData, processData: false, contentType: false,
            success: function (response) {
                $status.addClass(response.success ? 'success' : 'error').text(response.data.message).fadeIn();
                if (response.success) $form[0].reset();
                setTimeout(() => $status.fadeOut(), 5000);
            },
            complete: () => $btn.prop('disabled', false).text('Post Review')
        });
    });

    // Review Read More Toggle
    $(document).on('click', '.pbe-review-read-more-btn', function () {
        const $btn = $(this);
        const $wrap = $btn.prev('.pbe-review-text-wrap');
        $wrap.toggleClass('is-expanded');
        $btn.text($wrap.hasClass('is-expanded') ? 'Read less' : 'Read more');
    });

    // Review Pagination
    $(document).on('click', '.pbe-pagination-num, .pbe-pagination-btn', function (e) {
        e.preventDefault();
        const $btn = $(this);
        if ($btn.is(':disabled') || $btn.hasClass('active')) return;

        const page = parseInt($btn.data('page'));
        const propertyId = $btn.data('property');
        const $container = $('.pbe-reviews-list');
        const $loader = $('.pbe-reviews-loader');
        const $pagination = $('.pbe-reviews-pagination');

        if (!page || !propertyId) return;

        $container.css('opacity', '0.5');
        $loader.fadeIn(200);

        $.ajax({
            url: pbe_ajax.url,
            type: 'POST',
            data: {
                action: 'pbe_get_reviews_page',
                property_id: propertyId,
                page: page
            },
            success: function (response) {
                if (response.success) {
                    $container.html(response.data.html).css('opacity', '1');
                    $loader.fadeOut(200);
                    updatePaginationUI($pagination, response.data.current, response.data.total_pages);
                    $('html, body').animate({
                        scrollTop: $('.pbe-reviews-section-full').offset().top - 100
                    }, 500);
                }
            },
            error: function () {
                $container.css('opacity', '1');
                $loader.fadeOut(200);
            }
        });
    });

    function updatePaginationUI($pagination, current, total) {
        $pagination.find('.pbe-pagination-num').removeClass('active');
        $pagination.find(`.pbe-pagination-num[data-page="${current}"]`).addClass('active');
        const $prev = $pagination.find('.pbe-pagination-btn.prev');
        const $next = $pagination.find('.pbe-pagination-btn.next');
        $prev.data('page', current - 1).prop('disabled', current <= 1);
        $next.data('page', current + 1).prop('disabled', current >= total);
    }

    // --- Bespoke Custom Availability Calendar ---
    function initCustomAvailabilityCalendar() {
        const $calContainer = $('#pbe-inline-calendar');
        if (!$calContainer.length) return;

        const propertyId = $calContainer.data('property-id');
        const rows = parseInt($calContainer.data('rows')) || 2;
        const cols = parseInt($calContainer.data('cols')) || 2;
        const totalMonths = parseInt($calContainer.data('total-months')) || (rows * cols);

        let currentStartDate = new Date();
        currentStartDate.setDate(1);
        let blockedDates = [];
        let restrictions = {};

        $calContainer.css('--pbe-cal-cols', cols);

        function formatDate(date) {
            const d = new Date(date);
            return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        }

        const fetchAvailability = () => {
            if (window.pbeAvailabilityData) {
                blockedDates = window.pbeAvailabilityData.filter(d => d.status !== 'available').map(d => d.date);
                window.pbeAvailabilityData.forEach(d => {
                    restrictions[d.date] = { cta: d.cta == 1, ctd: d.ctd == 1 };
                });
                render();
                return;
            }

            const startStr = formatDate(new Date());
            const endD = new Date();
            endD.setMonth(endD.getMonth() + 24);
            const endStr = formatDate(endD);

            $.ajax({
                url: pbe_ajax.url,
                type: 'GET',
                data: {
                    action: 'pbe_get_property_availability',
                    property_id: propertyId,
                    from: startStr,
                    to: endStr
                },
                success: function (response) {
                    const guestyData = response.data.data || response.data;
                    if (response.success && guestyData && guestyData.days) {
                        blockedDates = guestyData.days.filter(d => d.status !== 'available').map(d => d.date);
                        guestyData.days.forEach(d => {
                            restrictions[d.date] = { cta: d.cta == 1, ctd: d.ctd == 1 };
                        });
                        render();
                    }
                }
            });
        };

        function generateMonthHTML(year, month, index) {
            const monthDate = new Date(year, month);
            const monthName = monthDate.toLocaleString('default', { month: 'long' });
            const yearLabel = monthDate.getFullYear();
            const daysInMonth = new Date(year, month + 1, 0).getDate();
            const firstDayIndex = new Date(year, month, 1).getDay();
            const adjustedFirstDay = firstDayIndex === 0 ? 6 : firstDayIndex - 1;

            const weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
            let html = `<div class="pbe-month-card" style="animation-delay: ${index * 0.05}s">
                <div class="pbe-month-name">${monthName} <span>${yearLabel}</span></div>
                <div class="pbe-days-grid">
                    ${weekdays.map(w => `<div class="pbe-weekday">${w}</div>`).join('')}`;

            for (let i = 0; i < adjustedFirstDay; i++) {
                html += `<div class="pbe-day is-empty"></div>`;
            }

            const todayStr = formatDate(new Date());
            for (let d = 1; d <= daysInMonth; d++) {
                const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
                const isBooked = blockedDates.includes(dateStr);
                const isToday = dateStr === todayStr;
                const isCta = restrictions[dateStr] && restrictions[dateStr].cta;
                const isCtd = restrictions[dateStr] && restrictions[dateStr].ctd;

                html += `<div class="pbe-day ${isBooked ? 'is-booked' : ''} ${isToday ? 'is-today' : ''} ${isCta ? 'is-cta' : ''} ${isCtd ? 'is-ctd' : ''}">
                    ${d}
                </div>`;
            }

            html += `</div></div>`;
            return html;
        }

        function render() {
            let html = '<div class="pbe-custom-calendar">';
            for (let i = 0; i < totalMonths; i++) {
                const d = new Date(currentStartDate);
                d.setMonth(d.getMonth() + i);
                html += generateMonthHTML(d.getFullYear(), d.getMonth(), i);
            }
            html += '</div>';

            $calContainer.css('opacity', 0);
            setTimeout(() => {
                $calContainer.html(html).css('opacity', 1);
                const today = new Date();
                today.setDate(1);
                $('#pbe-cal-prev').prop('disabled', currentStartDate <= today);
            }, 150);
        }

        $('#pbe-cal-prev').off('click').on('click', function () {
            currentStartDate.setMonth(currentStartDate.getMonth() - totalMonths);
            render();
        });
        $('#pbe-cal-next').off('click').on('click', function () {
            currentStartDate.setMonth(currentStartDate.getMonth() + totalMonths);
            render();
        });

        fetchAvailability();
    }

    // --- Mobile Drawers (Booking & Reviews) ---
    $(document).on('click', '#pbe-mobile-drawer-trigger', function (e) {
        e.preventDefault();
        $('body').addClass('pbe-booking-drawer-active');
    });

    $(document).on('click', '.pbe-mobile-review-trigger', function (e) {
        e.preventDefault();
        $('body').addClass('pbe-review-drawer-active');
    });

    $(document).on('click', '.pbe-mobile-booking-drawer-overlay, .pbe-mobile-drawer-close, .pbe-mobile-review-close', function (e) {
        e.preventDefault();
        $('body').removeClass('pbe-booking-drawer-active pbe-review-drawer-active');
    });

    initCustomAvailabilityCalendar();

    // --- Sticky Navigation Bar Logic ---
    const $stickyWrapper = $('.pbe-sticky-nav-wrapper');
    if ($stickyWrapper.length > 0) {
        const navLinks = $stickyWrapper.find('.pbe-nav-link');
        const sections = navLinks.map(function () {
            const target = $(this).attr('href');
            if (target && target.startsWith('#')) return $(target);
        }).get();

        const navHeight = $stickyWrapper.outerHeight() || 0;
        const STICKY_OFFSET = navHeight + 20;
        let pbeLockedTarget = null; // The section we are currently jumping to

        function updateActiveState() {
            // FORCE-LOCK: If we are jumping to a specific section, ignore calculations
            if (pbeLockedTarget) {
                navLinks.removeClass('active');
                navLinks.filter(`[href="${pbeLockedTarget}"]`).addClass('active');
                return;
            }

            const scrollPos = $(window).scrollTop();
            const $thumbs = $('.pbe-mini-thumbs-wrap');
            const revealPoint = $thumbs.length ? $thumbs.offset().top + $thumbs.outerHeight() : 100;

            // 1. Reveal logic (Show after thumbnails)
            if (scrollPos > revealPoint - 50) {
                $stickyWrapper.addClass('pbe-nav-revealed');
            } else {
                $stickyWrapper.removeClass('pbe-nav-revealed');
            }

            // 2. Section Tracking
            let currentSectionId = '';
            const detectionPoint = scrollPos + STICKY_OFFSET + 100;

            sections.forEach(function ($section) {
                if ($section.length) {
                    const top = $section.offset().top;
                    if (detectionPoint >= top) {
                        currentSectionId = '#' + $section.attr('id');
                    }
                }
            });

            if (currentSectionId) {
                navLinks.removeClass('active');
                navLinks.filter(`[href="${currentSectionId}"]`).addClass('active');
            }
        }

        // Attach listener
        $(window).on('scroll.pbeSticky', updateActiveState);

        // Smooth Scroll with Force-Lock
        navLinks.on('click', function (e) {
            const targetId = $(this).attr('href');
            const target = $(targetId);

            if (target.length) {
                e.preventDefault();
                e.stopImmediatePropagation();

                // 1. Lock the highlight to THIS target immediately
                pbeLockedTarget = targetId;

                // 2. Set UI immediately
                navLinks.removeClass('active');
                $(this).addClass('active');

                // 3. Perform scroll
                $('html, body').stop(true).animate({
                    scrollTop: target.offset().top - STICKY_OFFSET
                }, 800, 'swing', function () {
                    // 4. Release lock ONLY after full settling
                    setTimeout(() => {
                        pbeLockedTarget = null;
                        updateActiveState();
                    }, 400);
                });
            }
        });
    }
});
