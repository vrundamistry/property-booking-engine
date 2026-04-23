/**
 * Property Booking Engine — Map Scripts
 *
 * Handles Leaflet maps for:
 *   - Single property page  (#pbe-single-map)
 *   - Property listing page (#pbe-property-map using window.pbeMapData)
 *
 * Enqueued by PBE_Template_Loader only on property pages.
 * Leaflet CSS/JS loaded as dependencies.
 */

document.addEventListener('DOMContentLoaded', function () {
    var singleMap, listMap;

    // ── 1. Single Property Map ──────────────────────────────────
    var singleMapEl = document.getElementById('pbe-single-map');
    if (singleMapEl) {
        var lat = parseFloat(singleMapEl.getAttribute('data-lat')) || 40.7128;
        var lng = parseFloat(singleMapEl.getAttribute('data-lng')) || -74.0060;

        singleMap = L.map('pbe-single-map', { 
            attributionControl: false,
            fullscreenControl: true
        }).setView([lat, lng], 14);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19
        }).addTo(singleMap);

        // Fuzzy marker — show neighbourhood, not exact address for privacy
        var circle = L.circle([lat, lng], {
            color: getComputedStyle(document.documentElement)
                       .getPropertyValue('--pbe-primary').trim() || '#e61e4d',
            fillColor: getComputedStyle(document.documentElement)
                       .getPropertyValue('--pbe-primary').trim() || '#e61e4d',
            fillOpacity: 0.15,
            radius: 300
        }).addTo(singleMap);

        L.marker([lat, lng]).addTo(singleMap);
    }

    // ── 2. Listing Map — Multiple Markers from pbeMapData ───────
    var listMapEl = document.getElementById('pbe-property-map');
    if (listMapEl && typeof window.pbeMapData !== 'undefined' && window.pbeMapData.length > 0) {

        listMap = L.map('pbe-property-map', { 
            attributionControl: false, 
            fullscreenControl: true 
        }).setView([20, 0], 2);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19
        }).addTo(listMap);

        var bounds       = new L.LatLngBounds();
        var markersGroup = L.markerClusterGroup({
            showCoverageOnHover: false,
            spiderfyOnMaxZoom: true,
            zoomToBoundsOnClick: true
        });
        var markerObjects = []; // To keep track for card sync

        var markerColor = getComputedStyle(document.documentElement)
                              .getPropertyValue('--pbe-marker-color').trim() || '#2563eb';

        // Custom Home Marker Icon
        var customHomeIcon = L.divIcon({
            className: 'pbe-custom-marker',
            html: '<div class="pbe-marker-icon" style="background-color:' + markerColor + ';">' +
                  '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>' +
                  '</div>',
            iconSize: [36, 42],
            iconAnchor: [18, 42],
            popupAnchor: [0, -42]
        });

        window.pbeMapData.forEach(function (item, idx) {
            if (!item.lat || !item.lng) return;

            // Compact listing-style popup HTML
            var popupHtml =
                '<div class="pbe-map-popup-card">' +
                    (item.img ? '<div class="pbe-map-popup-img-wrap"><img src="' + item.img + '" class="pbe-map-popup-img" loading="lazy"></div>' : '') +
                    '<div class="pbe-map-popup-body">' +
                        '<a href="' + item.url + '" class="pbe-map-popup-title-link"><span class="pbe-map-popup-title">' + item.title + '</span></a>' +
                        '<div class="pbe-map-popup-meta">' +
                            '<span class="pbe-meta-item"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--pbe-icon-color)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path><path d="M6 8v9"></path></svg> ' + (item.beds || 0) + '</span>' +
                            '<span class="pbe-meta-item"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--pbe-icon-color)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6 6.5 3.5a1.5 1.5 0 0 0-1-.5C4.6 3 4 3.6 4 4.5V17a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H9"></path><line x1="7" y1="21" x2="7" y2="19"></line><line x1="17" y1="21" x2="17" y2="19"></line></svg> ' + (item.baths || 0) + '</span>' +
                            '<span class="pbe-meta-item"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--pbe-icon-color)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg> ' + (item.guests || 0) + '</span>' +
                        '</div>' +
                        (item.address ? '<div class="pbe-map-popup-address">' + item.address + '</div>' : '') +
                        '<div class="pbe-map-popup-footer">' +
                            '<div class="pbe-map-popup-price">' + (item.price || '') + '<span>/night</span></div>' +
                            '<a href="' + item.url + '" class="pbe-map-popup-link">Details &rarr;</a>' +
                        '</div>' +
                    '</div>' +
                '</div>';

            var marker = L.marker([item.lat, item.lng], { icon: customHomeIcon }).bindPopup(popupHtml, {
                maxWidth: 320,
                minWidth: 300,
                className: 'pbe-custom-popup'
            });

            markersGroup.addLayer(marker);
            bounds.extend([item.lat, item.lng]);
            markerObjects.push(marker);
        });

        listMap.addLayer(markersGroup);

        if (markerObjects.length > 0) {
            listMap.fitBounds(bounds, { padding: [40, 40] });
        }

        // Marker -> Card Sync (Highlight card when marker clicked)
        markerObjects.forEach(function (marker, idx) {
            marker.on('popupopen', function () {
                var cards = document.querySelectorAll('.pbe-property-card');
                cards.forEach(function (c) { c.classList.remove('pbe-card-active'); });
                if (cards[idx]) {
                    cards[idx].classList.add('pbe-card-active');
                    // We keep scrollIntoView so users can find the card when clicking the map
                    cards[idx].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            });
            marker.on('popupclose', function () {
                document.querySelectorAll('.pbe-property-card').forEach(function (c) {
                    c.classList.remove('pbe-card-active');
                });
            });
        });
 
        // Mobile Split View Toggle Logic (< 991px)
        var mapToggleBtn = document.getElementById('pbe-mobile-map-toggle');
        var mapCol       = document.querySelector('.pbe-listing-col-map');
        var closeBtn     = document.querySelector('.pbe-mobile-map-close-btn');
 
        if (mapToggleBtn && mapCol) {
            mapToggleBtn.addEventListener('click', function() {
                var isActive = mapCol.classList.toggle('active');
                if (isActive) {
                    document.body.classList.add('pbe-map-view-active');
                    setTimeout(function() {
                        if (listMap) {
                            listMap.invalidateSize();
                            // If markers exist, refit bounds to be safe
                            if (typeof bounds !== 'undefined' && bounds.isValid()) {
                                listMap.fitBounds(bounds, { padding: [20, 20] });
                            }
                        }
                    }, 100);
                } else {
                    document.body.classList.remove('pbe-map-view-active');
                }
            });
        }
    }

    // --- 3. Shared Resize / Invalidate Logic ---
    var pbeResizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(pbeResizeTimer);
        pbeResizeTimer = setTimeout(function() {
            if (typeof singleMap !== 'undefined' && singleMap) singleMap.invalidateSize();
            if (typeof listMap !== 'undefined' && listMap) listMap.invalidateSize();
        }, 300);
    });

    // Forced refresh after init (fixes blank tile issue)
    setTimeout(function() {
        if (typeof singleMap !== 'undefined' && singleMap) singleMap.invalidateSize();
        if (typeof listMap !== 'undefined' && listMap) listMap.invalidateSize();
    }, 500);

});
