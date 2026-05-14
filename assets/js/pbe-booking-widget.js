/**
 * PBE Booking Widget Script
 * Handles premium Litepicker integration, availability blocking, and real-time quoting.
 */

jQuery(document).ready(function($) {
    const $widget = $('.pbe-booking-widget-container');
    if (!$widget.length) return;

    const propertyId = $widget.data('property-id');
    const platformId = $widget.data('platform-id');
    
    let propertyConfig = { min_nights: 1 };
    let firstBlockedDateAfterCheckin = null;
    let propertyAvailability = {
        blocked: [],
        rules: {} // date -> { minNights: X }
    };

    let picker = null;

    // --- Helpers ---
    function formatDate(d) {
        if (!d) return '';
        const date = (d instanceof Date) ? d : (d.getTime ? new Date(d.getTime()) : new Date(d));
        if (isNaN(date.getTime())) return '';
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    function getPrevDayStr(d) {
        const date = (d instanceof Date) ? new Date(d.getTime()) : (d.getTime ? new Date(d.getTime()) : new Date(d));
        date.setDate(date.getDate() - 1);
        return formatDate(date);
    }

    // --- 1. Initialization ---
    initBookingWidget();

    function initBookingWidget() {
        fetchPropertyConfig();
        fetchAvailability();
    }

    // --- 2. Data Fetching ---
    function fetchPropertyConfig() {
        $.ajax({
            url: pbe_ajax.url,
            type: 'GET',
            data: { action: 'pbe_get_property_config', property_id: propertyId },
            success: function(response) {
                if (response.success) {
                    propertyConfig = { ...propertyConfig, ...response.data };
                    initLitepicker();
                }
            }
        });
    }

    function fetchAvailability() {
        const processData = (guestyData) => {
            if (guestyData && (guestyData.days || Array.isArray(guestyData))) {
                propertyAvailability.blocked = [];
                const days = guestyData.days || guestyData; // handle both direct array or payload wrapper
                
                days.forEach(day => {
                    const todayFormatted = formatDate(new Date());
                    if (day.status !== 'available') {
                        propertyAvailability.blocked.push(day.date);
                    }
                    
                    propertyAvailability.rules[day.date] = { 
                        minNights: day.minNights || day.min_nights ? parseInt(day.minNights || day.min_nights) : (propertyConfig.min_nights || 1),
                        maxNights: day.maxNights || day.max_nights ? parseInt(day.maxNights || day.max_nights) : null,
                        cta: day.cta == 1,
                        ctd: day.ctd == 1,
                        status: day.status
                    };
                });
                
                // Apply dynamic CSS for blocked dates to survive Litepicker hover-preview
                updateBlockedDatesStyle();

                if (picker) {
                    picker.render(); // Refresh to apply updated blocked days in filter
                } else {
                    initLitepicker();
                }

                // Auto-trigger quote if dates are pre-filled from URL
                const cin  = $('#pbe-sp-checkin').val();
                const cout = $('#pbe-sp-checkout').val();
                if (cin && cout) {
                    updateNightsDisplay(new Date(cin), new Date(cout));
                    fetchBookingQuote();
                    $('#pbe-clear-dates').show();
                }
            }
        };

        // Use Instant Local Data if injected by the shortcode or template
        if (window.pbeAvailabilityData) {
            processData(window.pbeAvailabilityData);
            return;
        }

        const startStr = new Date().toISOString().split('T')[0];
        const endD = new Date();
        endD.setMonth(endD.getMonth() + 24);
        const endStr = endD.toISOString().split('T')[0];

        $.ajax({
            url: pbe_ajax.url,
            type: 'GET',
            data: {
                action: 'pbe_get_property_availability',
                property_id: propertyId,
                from: startStr,
                to: endStr
            },
            success: function(response) {
                if (response.success) {
                    const guestyData = response.data.data || response.data;
                    processData(guestyData);
                }
            }
        });
    }

    // --- 3. Litepicker Integration ---
    function initLitepicker() {
        const checkinEl  = document.getElementById('pbe-sp-checkin');
        const checkoutEl = document.getElementById('pbe-sp-checkout');

        if (!checkinEl || typeof Litepicker === 'undefined') return;
        if (picker) return; // Already initialized

        // Destroy any Flatpickr instances that may have been applied to these inputs
        // (Flatpickr is loaded globally for the search form but must not touch the widget fields)
        if (checkinEl._flatpickr)  checkinEl._flatpickr.destroy();
        if (checkoutEl && checkoutEl._flatpickr) checkoutEl._flatpickr.destroy();
        
        const todayLocalMidnight = new Date();
        todayLocalMidnight.setHours(0, 0, 0, 0);

        picker = new Litepicker({
            element: checkinEl,
            elementEnd: checkoutEl,
            singleMode: false,
            firstDay: 0, // Set Sunday as the first day of the week
            numberOfMonths: 1, // 1 month for all screens
            numberOfColumns: 1,
            minDate: todayLocalMidnight,
            format: 'YYYY-MM-DD',
            scrollToDate: true,
            backdrop: false,
            parentEl: $widget[0],
            autoRefresh: true,
            disallowLockDaysInRange: true,
            lockDaysFilter: (date, date2, pickedDates) => {
                const dStr = formatDate(date);
                
                if (pickedDates.length === 0) {
                    // Selecting check-in: cannot pick a blocked day OR a CTA day
                    return propertyAvailability.blocked.includes(dStr) || (propertyAvailability.rules[dStr] && propertyAvailability.rules[dStr].cta);
                } else {
                    const checkinStr = formatDate(pickedDates[0]);
                    
                    // Guesty-style strict backward locking: disable all dates STRICTLY BEFORE Check-in date
                    if (dStr < checkinStr) {
                        return true;
                    }

                    // Bridge Prevention: Hard stop at the first blocked date encountered after check-in
                    if (firstBlockedDateAfterCheckin && dStr > firstBlockedDateAfterCheckin) {
                        return true;
                    }
                    
                    // The Check-in date itself is inherently valid for its own range, do not evaluate it as a check-out day.
                    if (dStr === checkinStr) {
                        return false;
                    }

                    // Selecting check-out: can pick a blocked day IF its night before was available AND not a CTD day
                    if (propertyAvailability.rules[dStr] && propertyAvailability.rules[dStr].ctd) {
                        return true;
                    }
                    const prevStr = getPrevDayStr(date);
                    return propertyAvailability.blocked.includes(prevStr);
                }
            },
            showTooltip: false, // Unified in custom tooltip handler below
            tooltipText: {
                one: 'night',
                other: 'nights'
            },
            tooltipNumber: (totalDays) => {
                return totalDays > 0 ? (totalDays - 1) : 0;
            },
            resetButton: {
                label: 'Reset Dates'
            },
            setup: (picker) => {

                picker.on('show', () => {
                    // Hide the widget card's shadow so it doesn't show behind the calendar
                    $widget.addClass('pbe-calendar-open');
                });

                picker.on('hide', () => {
                    $widget.removeClass('pbe-calendar-open');
                    $('#pbe-cal-tooltip').hide();
                });

                picker.on('selected', (date1, date2) => {
                    $('#pbe-dynamic-hover').remove();
                    updateNightsDisplay(date1, date2);
                    fetchBookingQuote();
                    $('#pbe-clear-dates').show();
                });

                // Safe, dynamic minNights and maxStay bounding (Guesty Style)
                picker.on('preselect', (date) => {
                    const dateStr = formatDate(date);
                    const rules = propertyAvailability.rules[dateStr];

                    // Distinguish between: clicking a new check-in vs hovering/picking a check-out date
                    const isNewSearch = !picker.getStartDate() || picker.getEndDate();

                    if (isNewSearch) {
                        // --- NEW CHECK-IN SELECTED ---

                        // Populate Check-in and clear checkout immediately
                        $('#pbe-sp-checkin').val(dateStr);
                        $('#pbe-sp-checkout').val('');

                        // Manually paint the Check-in date to survive interim hovers (injects invincible CSS rule for this specific date)
                        $('#pbe-dynamic-hover').remove();
                        $('<style id="pbe-dynamic-hover">')
                            .text(`.litepicker .day-item[data-time="${date.getTime()}"] { background-color: var(--pbe-accent, #196ee6) !important; color: #fff !important; border-radius: 4px !important; }`)
                            .appendTo('head');

                        // Min Stay from rules or global config
                        const min = (rules && rules.minNights) ? parseInt(rules.minNights) : (propertyConfig.min_nights || 1);
                        const newMinDays = min + 1; // n nights = n+1 days selected

                        // Forward locking: restrict Max Stay strictly up to the next chronological blocked date
                        firstBlockedDateAfterCheckin = null;
                        for (const bDateStr of propertyAvailability.blocked) {
                            if (bDateStr > dateStr) {
                                if (firstBlockedDateAfterCheckin === null || bDateStr < firstBlockedDateAfterCheckin) {
                                    firstBlockedDateAfterCheckin = bDateStr;
                                }
                            }
                        }

                        let newMaxDays = null;
                        if (firstBlockedDateAfterCheckin) {
                            newMaxDays = calculateNights(new Date(dateStr), new Date(firstBlockedDateAfterCheckin)) + 1;
                        }

                        // Max Stay from Data rules overrides forward locking if stricter
                        const maxRule = (rules && rules.maxNights) ? parseInt(rules.maxNights) : null;
                        if (maxRule) {
                            const ruleMaxDays = maxRule + 1;
                            newMaxDays = (newMaxDays === null) ? ruleMaxDays : Math.min(newMaxDays, ruleMaxDays);
                        }

                        // Safely mutate options directly to avoid triggering the destructive picker.setOptions() re-render loop
                        picker.options.minDays = newMinDays;
                        picker.options.maxDays = newMaxDays;

                        // Smart Cross-out: Instantly hide the diagonal line for the boundary checkout date
                        updateBlockedDatesStyle(firstBlockedDateAfterCheckin);

                        // Force view refresh to apply new min/max constraints
                        if (typeof picker.render === 'function') {
                            picker.render();
                        }
                    } else {
                        // --- HOVERING / PICKING CHECK-OUT ---
                        // Reveal the hovered turnover date as valid by hiding its cross-out line
                        updateBlockedDatesStyle(dateStr);
                    }
                });

                picker.on('clear:selection', () => {
                    firstBlockedDateAfterCheckin = null;
                    updateBlockedDatesStyle(null); // Restore all cross-outs
                    $('#pbe-dynamic-hover').remove();
                    $('#pbe-sp-checkin, #pbe-sp-checkout').val('');
                    $('#pbe-clear-dates, #pbe-nights-count').hide();
                    $('#pbe-price-breakdown-target').hide().html('');
                    // Reset button state
                    $('#pbe-book-now').prop('disabled', true);
                });

                // --- Custom Tooltip Handler ---
                const $calParent = $(picker.options.parentEl);
                if (!$('#pbe-cal-tooltip').length) {
                    $('<div id="pbe-cal-tooltip" class="pbe-cal-custom-tooltip"></div>').appendTo('body').hide();
                }
                const $tooltip = $('#pbe-cal-tooltip');

                $(document).on('mouseenter', '.litepicker .day-item', function() {
                    // Litepicker sometimes locks dates but does not add .is-locked instantly to CTA/CTD
                    const dateStr = formatDate(parseInt($(this).data('time')));
                    const rules = propertyAvailability.rules[dateStr];
                    if (!rules || rules.status !== 'available') return;

                    let msg = '';
                    const hasCheckin = $('#pbe-sp-checkin').val();
                    const hasCheckout = $('#pbe-sp-checkout').val();

                    if (hasCheckin && !hasCheckout) {
                        // Check-in selected, hovering for check-out
                        const nights = calculateNights(new Date(hasCheckin), new Date(parseInt($(this).data('time'))));
                        const minReq = propertyAvailability.rules[hasCheckin] ? parseInt(propertyAvailability.rules[hasCheckin].minNights) : (propertyConfig.min_nights || 1);

                        if (nights >= 0 && nights < minReq) {
                            msg = `Minimum ${minReq} Nights`;
                        } else if (nights > 0 && rules.ctd) {
                            msg = "Check-out is not allowed on this day";
                        } else if (nights > 0) {
                            // Unified: Show night count if duration is valid and not restricted
                            msg = nights + (nights === 1 ? ' Night' : ' Nights');
                        }
                    } else {
                        // Picking check-in (Either brand new selection OR starting over)
                        if (rules.cta) msg = "Check-in is not allowed on this day";
                    }

                    if (msg) {
                        const rect = this.getBoundingClientRect();
                        $tooltip.text(msg).css({
                            top: (rect.top + window.scrollY - 40) + 'px',
                            left: (rect.left + window.scrollX + (rect.width / 2)) + 'px',
                            display: 'block'
                        });
                        
                        // If it's a restriction, show not-allowed cursor
                        if (msg.includes('not allowed') || msg.includes('Minimum')) {
                            $(this).css('cursor', 'not-allowed');
                        } else {
                            $(this).css('cursor', 'pointer');
                        }
                    }
                }).on('mouseleave', '.litepicker .day-item', function() {
                    $tooltip.hide();
                });
            }
        });
    }

    // --- 4. Helpers & UI Actions ---

    // Dynamic CSS Injection: Ensure booked dates remain crossed out even during Litepicker hover-preview
    function updateBlockedDatesStyle(excludeDateStr = null) {
        $('#pbe-dynamic-crossouts').remove();
        if (!propertyAvailability.blocked || !propertyAvailability.blocked.length) return;
        
        let selectors = [];
        propertyAvailability.blocked.forEach(dateStr => {
            if (!dateStr || dateStr === excludeDateStr) return; // Hide cross-out if excluded OR if date is empty
            
            const parts = dateStr.split('-');
            const date = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]), 0, 0, 0, 0);
            const time = date.getTime();
            selectors.push(`.litepicker .day-item[data-time="${time}"]::after`);
        });
        
        if (selectors.length) {
            const css = `
                ${selectors.join(', ')} {
                    content: "" !important;
                    position: absolute !important;
                    top: 50% !important;
                    left: 15% !important;
                    width: 70% !important;
                    height: 1px !important;
                    background: #cbd5e1 !important;
                    transform: rotate(-45deg) !important;
                    pointer-events: none !important;
                    z-index: 10 !important;
                }
            `;
            $('<style id="pbe-dynamic-crossouts">').text(css).appendTo('head');
        }
    }

    function getJSDate(d) {
        if (!d) return null;
        if (d instanceof Date) return d;
        if (typeof d.toJSDate === 'function') return d.toJSDate();
        if (typeof d.getTime === 'function') return new Date(d.getTime());
        return new Date(d);
    }

    function calculateNights(start, end) {
        const d1 = getJSDate(start);
        const d2 = getJSDate(end);
        if (!d1 || !d2) return 0;
        
        // Standard UTC-based night counting (Industry Standard)
        const utc1 = Date.UTC(d1.getFullYear(), d1.getMonth(), d1.getDate());
        const utc2 = Date.UTC(d2.getFullYear(), d2.getMonth(), d2.getDate());
        
        const diff = utc2 - utc1;
        const dayMs = 1000 * 60 * 60 * 24;
        
        return Math.floor(diff / dayMs);
    }

    function updateNightsDisplay(start, end) {
        const $badge = $('#pbe-nights-count');
        if (start && end) {
            const nights = calculateNights(start, end);
            $badge.show().find('span').text(nights + (nights === 1 ? ' Night' : ' Nights'));
        } else {
            $badge.hide();
        }
    }

    function fetchBookingQuote() {
        const checkin  = $('#pbe-sp-checkin').val();
        const checkout = $('#pbe-sp-checkout').val();
        const guests   = $('#pbe-sp-guests-val').val() || 2;

        if (!checkin || !checkout) return;

        const nights = calculateNights(new Date(checkin), new Date(checkout));
        const minReq = propertyAvailability.rules[checkin] ? parseInt(propertyAvailability.rules[checkin].minNights) : (propertyConfig.min_nights || 1);
        const $breakdown = $('#pbe-price-breakdown-target');

        if (nights < minReq) {
            $breakdown.show().html(`<div class="pbe-pb-error">A minimum stay of ${minReq} nights is required for these dates.</div>`);
            return;
        }

        $breakdown.show().html('<div class="pbe-pb-loading">Calculating total...</div>');
        $('#pbe-book-now').prop('disabled', true);

        $.ajax({
            url: pbe_ajax.url,
            type: 'POST',
            data: { action: 'pbe_get_stay_quote', property_id: propertyId, checkin: checkin, checkout: checkout, guests: guests },
            success: function(response) {
                    if (response.success) {
                    let html = '';
                    const quote = response.data;
                    (quote.breakdown || []).forEach(item => {
                        html += `<div class="pbe-pb-row"><span>${item.label}</span><span>$${Number(item.amount).toLocaleString()}</span></div>`;
                    });
                    html += `<div class="pbe-pb-total"><span>Total</span><span>$${Number(quote.total).toLocaleString()}</span></div>`;
                    $breakdown.html(html);
                    
                    // Enable button once price is shown
                    $('#pbe-book-now').prop('disabled', false);
                } else {
                    $breakdown.html(`<div class="pbe-pb-error">${response.data.message}</div>`);
                    $('#pbe-book-now').prop('disabled', true);
                }
            }
        });
    }

    // Clear Dates Action
    $(document).on('click', '#pbe-clear-dates', function() {
        if (picker) {
            picker.clearSelection();
        }
        $('#pbe-sp-checkin, #pbe-sp-checkout').val('');
        $('#pbe-clear-dates, #pbe-nights-count').hide();
        $('#pbe-price-breakdown-target').hide().html('');
    });

    // Guests Change
    $(document).on('click', '.pbe-custom-dropdown .pbe-cd-options li', function() {
        const $dropdown = $(this).closest('.pbe-custom-dropdown');
        const $input = $dropdown.find('input[type="hidden"]');
        if ($input.attr('id') === 'pbe-sp-guests-val') {
            fetchBookingQuote();
        }
    });

    // Final Booking Redirect
    $(document).on('click', '#pbe-book-now', function() {
        const checkin = $('#pbe-sp-checkin').val();
        const checkout = $('#pbe-sp-checkout').val();
        const guests = $('#pbe-sp-guests-val').val() || 2;
        const platformSource = $widget.attr('data-platform-source') || 'guesty';
        
        let bookingDomain = $widget.attr('data-booking-domain');
        if (!bookingDomain) {
            bookingDomain = (platformSource === 'hostaway') ? '' : 'guestybookings.com';
        }

        // Cleanup: Remove https:// or http:// if user entered it accidentally
        bookingDomain = bookingDomain.replace(/^https?:\/\//, '').replace(/\/$/, '');
        
        if (!checkin || !checkout) {
            alert('Please select both check-in and check-out dates.');
            return;
        }

        if (platformSource === 'hostfully') {
            const $btn = $(this);
            const originalText = $btn.text();
            $btn.text('Preparing Booking...').prop('disabled', true);

            $.ajax({
                url: pbe_ajax.url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'pbe_create_hostfully_lead',
                    property_id: propertyId,
                    checkin: checkin,
                    checkout: checkout,
                    guests: guests
                },
                success: function(response) {
                    console.log('Hostfully Lead API Response:', response);
                    if (response && response.success) {
                        if (response.data && response.data.is_local) {
                            console.log('Local bypass: Lead creation skipped. Redirecting to base property page.');
                            window.location.href = `https://book.hostfully.com/${bookingDomain}/property/${platformId}`;
                        } else if (response.data && response.data.lead_id) {
                            window.location.href = `https://book.hostfully.com/${bookingDomain}/payment/overview?l=${response.data.lead_id}`;
                        }
                    } else {
                        const errMsg = (response && response.data && response.data.message) ? response.data.message : 'Unknown error occurred or invalid response.';
                        alert('Error preparing booking: ' + errMsg);
                        $btn.text(originalText).prop('disabled', false);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', status, error, xhr.responseText);
                    alert('An error occurred while preparing the booking.');
                    $btn.text(originalText).prop('disabled', false);
                }
            });
            return;
        }

        let bookingUrl = '';
        if (platformSource === 'hostaway') {
            // Holiday Future Format: https://{domain}/checkout/{id}?start=YYYY-MM-DD&end=YYYY-MM-DD&numberOfGuests=X
            bookingUrl = `https://${bookingDomain}/checkout/${platformId}?start=${checkin}&end=${checkout}&numberOfGuests=${guests}`;
        } else {
            // Guesty Format: https://{domain}/en/properties/{id}?minOccupancy=X&checkIn=YYYY-MM-DD&checkOut=YYYY-MM-DD
            bookingUrl = `https://${bookingDomain}/en/properties/${platformId}?minOccupancy=${guests}&checkIn=${checkin}&checkOut=${checkout}`;
        }
        
        window.location.href = bookingUrl;
    });
});
