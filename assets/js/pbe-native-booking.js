/**
 * Native Booking Widget JS
 * Multi-step flow handling.
 */
jQuery(document).ready(function($) {
    const $widget = $('#pbe-native-widget');
    if (!$widget.length) return;

    const propertyId = $widget.data('property-id');
    const platformId = $widget.data('platform-id');

    let propertyConfig = { min_nights: 1 };
    let propertyAvailability = {
        blocked: [],
        rules: {} // date -> { minNights: X }
    };
    
    // Stripe Variables
    let stripe = null;
    let elements = null;
    let cardElement = null;

    let picker = null;
    let currentQuote = null;
    let firstBlockedDateAfterCheckin = null; // Sticky Lock Prevention Tracking

    // --- INITIALIZATION ---
    function initBookingWidget() {
        // Fetch config and availability before picker init
        fetchPropertyConfig();
        fetchAvailability();
        initStripe();
        
        // Reveal widget after brief delay for smooth appearance
        setTimeout(() => {
            $widget.css({ 'opacity': '1', 'visibility': 'visible' });
        }, 50);
    }

    function initStripe() {
        if (typeof Stripe === 'undefined' || !pbe_ajax.stripe_key) {
            console.error('Stripe.js not loaded or API key missing.');
            return;
        }
        stripe = Stripe(pbe_ajax.stripe_key);
        elements = stripe.elements();

        const style = {
            base: {
                color: '#32325d',
                fontFamily: '"Inter", "Helvetica Neue", Helvetica, sans-serif',
                fontSmoothing: 'antialiased',
                fontSize: '16px',
                '::placeholder': {
                    color: '#aab7c4'
                }
            },
            invalid: {
                color: '#fa755a',
                iconColor: '#fa755a'
            }
        };

        cardElement = elements.create('card', { style: style, hidePostalCode: true });
        
        cardElement.on('change', function(event) {
            const displayError = document.getElementById('pbe-stripe-errors');
            if (event.error) {
                displayError.textContent = event.error.message;
            } else {
                displayError.textContent = '';
            }
        });
    }

    function fetchPropertyConfig() {
        $.ajax({
            url: pbe_ajax.url,
            type: 'GET',
            data: { action: 'pbe_get_property_config', property_id: propertyId },
            success: function(response) {
                if (response.success) {
                    propertyConfig = { ...propertyConfig, ...response.data };
                    initPicker();
                }
            }
        });
    }

    function fetchAvailability() {
        if (window.pbeAvailabilityData) {
            propertyAvailability.blocked = [];
            window.pbeAvailabilityData.forEach(day => {
                const d = new Date();
                const todayStr = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
                
                if (day.status !== 'available') {
                    propertyAvailability.blocked.push(day.date);
                }
                propertyAvailability.rules[day.date] = { 
                    minNights: day.min_nights ? parseInt(day.min_nights) : (propertyConfig.min_nights || 1),
                    maxNights: null,
                    cta: day.cta == 1,
                    ctd: day.ctd == 1,
                    status: day.status
                };
            });
            
            if (picker) {
                picker.render();
            } else {
                initPicker();
            }

            if ($('#pbe-nb-checkin').val() && $('#pbe-nb-checkout').val()) {
                fetchNativeQuote();
            }
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
                const guestyData = response.data.data || response.data;
                if (response.success && guestyData && guestyData.days) {
                    propertyAvailability.blocked = [];
                    guestyData.days.forEach(day => {
                        const d = new Date();
                        const todayStr = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
                        
                        if (day.status !== 'available') {
                            propertyAvailability.blocked.push(day.date);
                        }
                        propertyAvailability.rules[day.date] = { 
                            minNights: day.minNights ? parseInt(day.minNights) : (propertyConfig.min_nights || 1),
                            maxNights: day.maxNights ? parseInt(day.maxNights) : null,
                            cta: day.cta == 1,
                            ctd: day.ctd == 1,
                            status: day.status
                        };
                    });
                    
                    if (picker) {
                        picker.render();
                    } else {
                        initPicker();
                    }

                    if ($('#pbe-nb-checkin').val() && $('#pbe-nb-checkout').val()) {
                        fetchNativeQuote();
                    }
                }
            }
        });
    }

    function initPicker() {
        const checkinInput  = document.getElementById('pbe-nb-checkin');
        const checkoutInput = document.getElementById('pbe-nb-checkout');

        if (!checkinInput || !checkoutInput || typeof Litepicker === 'undefined') return;
        if (picker) return;

        const todayLocalMidnight = new Date();
        todayLocalMidnight.setHours(0, 0, 0, 0);

        picker = new Litepicker({
            element: checkinInput,
            elementEnd: checkoutInput,
            singleMode: false,
            firstDay: 0, // Set Sunday as the first day of the week
            numberOfMonths: 2,
            numberOfColumns: 2,
            minDate: todayLocalMidnight,
            format: 'YYYY-MM-DD',
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
            tooltipText: { one: 'night', other: 'nights' },
            tooltipNumber: (totalDays) => {
                return totalDays > 0 ? (totalDays - 1) : 0;
            },
            resetButton: { label: 'Reset Dates' },
            setup: (picker) => {
                picker.on('selected', (date1, date2) => {
                    $('#pbe-nb-dynamic-hover').remove();
                    updateStep1Button();
                    fetchNativeQuote();
                });

                picker.on('render', () => {
                    // Pass firstBlockedDateAfterCheckin so the boundary/turnover date
                    // stays uncrossed during renders (null = show all cross-outs when no check-in selected)
                    updateBlockedDatesStyle(firstBlockedDateAfterCheckin);

                    // Apply black check-in highlight while awaiting checkout selection.
                    // Uses local-midnight timestamp (same as crossouts) for reliable data-time matching.
                    if (picker.getStartDate() && !picker.getEndDate()) {
                        const startDateStr = formatDate(picker.getStartDate());
                        const parts = startDateStr.split('-');
                        const d = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]), 0, 0, 0, 0);
                        $(`.litepicker .day-item[data-time="${d.getTime()}"]`).addClass('pbe-nb-checkin-selected');
                    }
                });

                picker.on('clear:selection', () => {
                    firstBlockedDateAfterCheckin = null;
                    updateBlockedDatesStyle(null);
                    $('#pbe-nb-dynamic-hover').remove();
                    updateStep1Button();
                    $('#pbe-nb-price-breakdown').hide().empty();
                    currentQuote = null;
                    
                    // Reset inputs
                    $('#pbe-nb-checkin').val('');
                    $('#pbe-nb-checkout').val('');
                });

                picker.on('preselect', (date) => {
                    const dateStr = formatDate(date);
                    const rules = propertyAvailability.rules[dateStr];

                    // Identify if we are picking a FIRST date (Check-in) OR Hovering/Picking a SECOND (Check-out)
                    // Litepicker 'preselect' fires on click for the first date, and on hover for the second.
                    const isNewSearch = !picker.getStartDate() || picker.getEndDate();

                    if (isNewSearch) {
                        // --- START OF NEW SEARCH ---
                        $('#pbe-nb-checkin').val(dateStr);
                        $('#pbe-nb-checkout').val('');
                        $('#pbe-nb-price-breakdown').hide().empty();
                        currentQuote = null;
                        updateStep1Button();

                        // Manually paint the Check-in date highlight (Navigation Freedom)
                        $('#pbe-nb-dynamic-hover').remove();
                        $('<style id="pbe-nb-dynamic-hover">')
                            .text(`.litepicker .day-item[data-time="${date.getTime()}"] { background-color: var(--pbe-accent, #196ee6) !important; color: #fff !important; border-radius: 4px !important; }`)
                            .appendTo('head');

                        // Reset rule tracking and calculate the Hard Stop boundary relative to this check-in
                        firstBlockedDateAfterCheckin = null;
                        const sortedBlocked = [...propertyAvailability.blocked].sort();
                        for (let bDate of sortedBlocked) {
                            if (bDate > dateStr) {
                                firstBlockedDateAfterCheckin = bDate;
                                break;
                            }
                        }

                        // Set Min/Max days relative to this Check-in
                        const min = (rules && rules.minNights) ? parseInt(rules.minNights) : (propertyConfig.min_nights || 1);
                        picker.options.minDays = min + 1;
                        
                        let newMaxDays = null;
                        if (firstBlockedDateAfterCheckin) {
                            newMaxDays = calculateNights(new Date(dateStr), new Date(firstBlockedDateAfterCheckin)) + 1;
                        }
                        const maxRule = (rules && rules.maxNights) ? parseInt(rules.maxNights) : null;
                        if (maxRule) {
                            const ruleMaxDays = maxRule + 1;
                            newMaxDays = (newMaxDays === null) ? ruleMaxDays : Math.min(newMaxDays, ruleMaxDays);
                        }
                        
                        // Protective: ensure we never set a non-positive maxDays which would lock the whole calendar
                        picker.options.maxDays = (newMaxDays && newMaxDays > 0) ? newMaxDays : null;

                        // Reveal boundary for checkout selection
                        updateBlockedDatesStyle(firstBlockedDateAfterCheckin);

                        // FORCE VIEW REFRESH: Manually trigger Litepicker to re-evaluate the current month's dates
                        // against the new maxDays/minDays constraints without causing a "view jump".
                        if (typeof picker.render === 'function') {
                            picker.render();
                        }
                    } else {
                        // --- HOVERING / PICKING CHECK-OUT ---
                        // Reveal the turnover morning as a valid option by hiding its cross-out line
                        updateBlockedDatesStyle(dateStr); 
                    }
                });

                picker.on('hide', () => {
                    $('#pbe-cal-tooltip').hide();
                });

                // --- Custom Tooltip Handler ---
                if (!$('#pbe-cal-tooltip').length) {
                    $('<div id="pbe-cal-tooltip" class="pbe-cal-custom-tooltip"></div>').appendTo('body').hide();
                }
                const $tooltip = $('#pbe-cal-tooltip');

                $widget.on('mouseenter', '.litepicker .day-item', function() {
                    const time = parseInt($(this).data('time'));
                    if (!time) return;
                    
                    const dateStr = formatDate(time);
                    const rules = propertyAvailability.rules[dateStr];
                    
                    // Basic rule check (Must have status or we fallback to available if not in blocked)
                    const status = (rules && rules.status) ? rules.status : (propertyAvailability.blocked.includes(dateStr) ? 'booked' : 'available');
                    
                    if (status !== 'available') return;

                    let msg = '';
                    const hasCheckin = $('#pbe-nb-checkin').val();
                    const hasCheckout = $('#pbe-nb-checkout').val();

                    if (hasCheckin && !hasCheckout) {
                        // Check-in selected, hovering for check-out
                        const nights = calculateNights(new Date(hasCheckin), new Date(time));
                        const minReq = propertyAvailability.rules[hasCheckin] ? parseInt(propertyAvailability.rules[hasCheckin].minNights) : (propertyConfig.min_nights || 1);

                        if (nights >= 0 && nights < minReq) {
                            msg = `Minimum ${minReq} Nights`;
                        } else if (nights > 0 && rules.ctd) {
                            msg = "Check-out is not allowed on this day";
                        } else if (nights > 0) {
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

    // --- Dynamic CSS Injection: Ensure booked dates remain crossed out even during Litepicker interactions ---
    function updateBlockedDatesStyle(excludeDateStr = null) {
        $('#pbe-nb-dynamic-crossouts').remove();
        if (!propertyAvailability.blocked || !propertyAvailability.blocked.length) return;
        
        let selectors = [];
        propertyAvailability.blocked.forEach(dateStr => {
            if (!dateStr || dateStr === excludeDateStr) return;
            
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
            $('<style id="pbe-nb-dynamic-crossouts">').text(css).appendTo('head');
        }
    }

    // Standard local date formatting (YYYY-MM-DD)
    function formatDate(date) {
        if (!date) return '';
        // If it's a Litepicker/DateTime object, it has a format method
        if (typeof date.format === 'function') return date.format('YYYY-MM-DD');
        
        const d = new Date(date);
        const year = d.getFullYear();
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    function getPrevDayStr(date) {
        const d = (date instanceof Date) ? new Date(date.getTime()) : (date.getTime ? new Date(date.getTime()) : new Date(date));
        d.setDate(d.getDate() - 1);
        return formatDate(d);
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

    // --- FORM FIELD HANDLERS ---
    $(document).on('input', '#pbe-nb-phone', function() {
        // Strict numeric restriction: only allow digits
        this.value = this.value.replace(/[^0-9]/g, '');
    });

    // --- GUESTS POPUP ---
    $(document).on('click', '.pbe-nb-guest-trigger', function() {
        $('.pbe-nb-guest-dropdown').toggleClass('active');
    });

    $(document).on('click', '.pbe-nb-guest-option', function() {
        const val = $(this).data('value');
        const label = val === 1 ? '1 Guest' : val + ' Guests';
        
        $('#pbe-nb-guests-val').val(val);
        $('#pbe-nb-guest-label').text(label);
        
        $('.pbe-nb-guest-option').removeClass('active');
        $(this).addClass('active');
        $('.pbe-nb-guest-dropdown').removeClass('active');
        
        if ($('#pbe-nb-checkin').val() && $('#pbe-nb-checkout').val()) {
            fetchNativeQuote();
        }
    });

    // Close guest dropdown on click outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.pbe-nb-guests').length) {
            $('.pbe-nb-guest-dropdown').removeClass('active');
        }
    });

    // --- PRICING ---
    function fetchNativeQuote() {
        const checkin  = $('#pbe-nb-checkin').val();
        const checkout = $('#pbe-nb-checkout').val();
        const guests   = $('#pbe-nb-guests-val').val();

        if (!checkin || !checkout) return;

        const $breakdown = $('#pbe-nb-price-breakdown');
        $breakdown.show().html('<div class="pbe-pb-loading">Calculating total...</div>');
        $('#pbe-nb-goto-2').prop('disabled', true);

        $.ajax({
            url: pbe_ajax.url,
            type: 'POST',
            data: { 
                action: 'pbe_get_stay_quote', 
                property_id: propertyId, 
                checkin: checkin, 
                checkout: checkout, 
                guests: guests 
            },
            success: function(response) {
                if (response.success) {
                    currentQuote = response.data;
                    let html = '';
                    (currentQuote.breakdown || []).forEach(item => {
                        html += `<div class="pbe-pb-row"><span>${item.label}</span><span>$${Number(item.amount).toLocaleString()}</span></div>`;
                    });
                    html += `<div class="pbe-pb-total"><span>Total</span><span>$${Number(currentQuote.total).toLocaleString()}</span></div>`;
                    $breakdown.html(html);
                    updateStep1Button();
                } else {
                    $breakdown.html(`<div class="pbe-pb-error">${response.data.message}</div>`);
                    $('#pbe-nb-goto-2').prop('disabled', true);
                }
            }
        });
    }

    function updateStep1Button() {
        const cin = $('#pbe-nb-checkin').val();
        const cout = $('#pbe-nb-checkout').val();
        const hasQuote = currentQuote !== null;
        
        $('#pbe-nb-goto-2').prop('disabled', !(cin && cout && hasQuote));
    }

    // --- NAVIGATION ---
    $('#pbe-nb-goto-2').on('click', function() {
        goToStep(2);
    });

    $('#pbe-nb-back-to-1').on('click', function() {
        goToStep(1);
    });

    $('#pbe-nb-goto-3').on('click', function() {
        if (validateStep2()) {
            updateSummary();
            goToStep(3);
        }
    });

    $('#pbe-nb-back-to-2').on('click', function() {
        goToStep(2);
    });

    function goToStep(step) {
        $('.pbe-nb-content').removeClass('active');
        
        if (step === 'success') {
            $('#pbe-step-success').addClass('active');
            $('.pbe-nb-step').addClass('completed').removeClass('active');
            return;
        }

        if (typeof step === 'number') {
            $(`.pbe-nb-content:nth-of-type(${step+1})`).addClass('active'); // +1 because steps header is 1st child
            
            // Mount Stripe if entering Step 3
            if (step === 3 && cardElement) {
                setTimeout(() => {
                    cardElement.mount('#pbe-stripe-card-element');
                }, 100);
            }
        }

        // Update Step Indicators
        $('.pbe-nb-step').removeClass('active completed');
        for (let i = 1; i <= step; i++) {
            const $s = $(`.pbe-nb-step[data-step="${i}"]`);
            if (i < step) $s.addClass('completed');
            if (i === step) $s.addClass('active');
        }
    }

    function validateStep2() {
        let valid = true;
        let errors = [];

        $('#pbe-step-2 input[required]').each(function() {
            if (!$(this).val()) {
                $(this).css('border-color', 'red');
                valid = false;
            } else {
                $(this).css('border-color', '');
            }
        });
        
        // Email check
        const email = $('#pbe-nb-email').val();
        if (email && !email.includes('@')) {
            $('#pbe-nb-email').css('border-color', 'red');
            valid = false;
            errors.push('Please enter a valid email address.');
        }

        // Phone Validation (Min 10 digits for most formats)
        const phone = $('#pbe-nb-phone').val();
        const numericPhone = phone.replace(/\D/g,'');
        if (phone && numericPhone.length < 10) {
            $('#pbe-nb-phone').css('border-color', 'red');
            valid = false;
            errors.push('Please enter a valid phone number (at least 10 digits).');
        }

        if (!valid) {
            if (errors.length > 0) {
                alert(errors.join('\n'));
            } else {
                alert('Please fill in all required fields.');
            }
        }
        return valid;
    }

    function updateSummary() {
        const cin = $('#pbe-nb-checkin').val();
        const cout = $('#pbe-nb-checkout').val();
        const guests = $('#pbe-nb-guests-val').val();
        const total = currentQuote ? `$${Number(currentQuote.total).toLocaleString()}` : '---';

        $('#pbe-nb-sum-dates').text(`${cin} to ${cout}`);
        $('#pbe-nb-sum-guests').text(`${guests} Guests`);
        $('#pbe-nb-sum-total').text(total);
    }

    // --- SUBMISSION ---
    $('#pbe-nb-submit').on('click', function() {
        const $btn = $(this);
        const $loader = $('#pbe-nb-loader');
        const $errorDisplay = $('#pbe-stripe-errors');

        if (!stripe || !cardElement) {
            alert('Payment system not initialized.');
            return;
        }

        $loader.fadeIn();
        $btn.prop('disabled', true);

        // 1. Create Payment Method with Stripe
        stripe.createPaymentMethod({
            type: 'card',
            card: cardElement,
            billing_details: {
                name: $('#pbe-nb-fname').val() + ' ' + $('#pbe-nb-lname').val(),
                email: $('#pbe-nb-email').val(),
                phone: $('#pbe-nb-phone').val()
            },
        }).then(function(result) {
            if (result.error) {
                $loader.fadeOut();
                $btn.prop('disabled', false);
                $errorDisplay.textContent = result.error.message;
                alert('Payment error: ' + result.error.message);
            } else {
                // 2. Send Token to Backend
                submitBookingWithPayment(result.paymentMethod.id);
            }
        });
    });

    function submitBookingWithPayment(paymentMethodId) {
        const $btn = $('#pbe-nb-submit');
        const $loader = $('#pbe-nb-loader');

        const guestData = {
            first_name: $('#pbe-nb-fname').val(),
            last_name:  $('#pbe-nb-lname').val(),
            email:      $('#pbe-nb-email').val(),
            phone:      $('#pbe-nb-phone').val()
        };

        $.ajax({
            url: pbe_ajax.url,
            type: 'POST',
            data: {
                action: 'pbe_submit_native_booking',
                property_id: propertyId,
                checkin: $('#pbe-nb-checkin').val(),
                checkout: $('#pbe-nb-checkout').val(),
                guests: $('#pbe-nb-guests-val').val(),
                guest_data: guestData,
                payment_method_id: paymentMethodId,
                total_amount: currentQuote ? currentQuote.total : 0
            },
            success: function(response) {
                $loader.fadeOut();
                if (response.success) {
                    const resId = response.data.reservation_id || 'REQ-' + Math.floor(Math.random()*10000);
                    $('#pbe-nb-success-id').text(resId);
                    
                    // Populate success details
                    const propertyName = $widget.data('property-name') || 'Property Details';
                    const cin = $('#pbe-nb-checkin').val();
                    const cout = $('#pbe-nb-checkout').val();
                    const guests = $('#pbe-nb-guests-val').val();
                    const total = currentQuote ? `$${Number(currentQuote.total).toLocaleString()}` : '---';
                    
                    const nights = calculateNights(new Date(cin), new Date(cout));
                    
                    const detailsHtml = `
                        <div class="pbe-nb-success-row"><span>Property:</span><span>${propertyName}</span></div>
                        <div class="pbe-nb-success-row"><span>Dates:</span><span>${cin} to ${cout}</span></div>
                        <div class="pbe-nb-success-row"><span>Duration:</span><span>${nights} Nights</span></div>
                        <div class="pbe-nb-success-row"><span>Guests:</span><span>${guests} Guests</span></div>
                        <div class="pbe-nb-success-row"><span>Total Paid:</span><span class="total">${total}</span></div>
                    `;
                    $('#pbe-nb-success-details').html(detailsHtml);
 
                    // Show receipt link if available
                    if (response.data.receipt_url) {
                        $('#pbe-step-success').append(`<a href="${response.data.receipt_url}" target="_blank" class="pbe-nb-receipt-link">View Stripe Receipt</a>`);
                    }
 
                    goToStep('success');
                } else {
                    $btn.prop('disabled', false);
                    alert('Booking failed: ' + response.data.message);
                }
            },
            error: function() {
                $loader.fadeOut();
                $btn.prop('disabled', false);
                alert('A system error occurred. Please try again.');
            }
        });
    }

    initBookingWidget();
});
