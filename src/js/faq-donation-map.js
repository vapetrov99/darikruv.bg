/**
 * Blood donation locator map – data from NCTH (via local API proxy).
 * @see https://ncth.bg/contacts/
 */

let donationMapIframe = null;
let allStores = [];
let mapInitialized = false;
let selectedStoreId = null;
let mapFocus = null;

function escapeHtml(text) {
    return String(text || "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function storeSearchText(store) {
    return [store.title, store.address, store.city, store.state, store.phone, store.description]
        .join(" ")
        .toLowerCase();
}

function setLocatorStatus(message) {
    const el = document.getElementById("locator_status");
    if (el) {
        el.textContent = message;
    }
}

function getDirectionsUrl(store) {
    const lat = Number(store?.lat);
    const lng = Number(store?.lng);
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
        return "https://www.google.com/maps";
    }
    return `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(`${lat},${lng}`)}`;
}

function normalizePhoneForHref(phone) {
    const digits = String(phone || "").replace(/\D/g, "");
    return digits ? `tel:${digits}` : "";
}

function normalizeEmailForHref(email) {
    const trimmed = String(email || "").trim();
    if (!trimmed || /[\r\n]/.test(trimmed)) {
        return "";
    }
    return `mailto:${encodeURIComponent(trimmed)}`;
}

function findStoreById(storeId) {
    return allStores.find((store) => Number(store.id) === Number(storeId)) || null;
}

function buildStoreDetailHtml(store) {
    const telHref = normalizePhoneForHref(store.phone);
    const emailHref = normalizeEmailForHref(store.email);
    const phone = store.phone
        ? `<p><strong>Телефон:</strong> <a href="${telHref}">${escapeHtml(store.phone)}</a></p>`
        : "";
    const email = emailHref
        ? `<p><strong>Имейл:</strong> <a href="${emailHref}">${escapeHtml(store.email)}</a></p>`
        : "";
    const description = store.description
        ? `<div class="faq-locator-selected-desc">${escapeHtml(store.description).replace(/\n/g, "<br>")}</div>`
        : "";

    return `
        <div class="faq-locator-selected-inner">
            <div class="faq-locator-selected-main">
                <h5>${escapeHtml(store.title)}</h5>
                <p class="faq-locator-selected-address">${escapeHtml(store.address)}</p>
                ${description}
                ${phone}
                ${email}
            </div>
            <a href="${getDirectionsUrl(store)}" class="faq-store-directions faq-locator-selected-directions" target="_blank" rel="noopener noreferrer">Упътване</a>
        </div>
    `;
}

function renderSelectedStore(store) {
    const el = document.getElementById("locator_selected");
    if (!el) {
        return;
    }

    if (!store) {
        el.classList.remove("has-store");
        el.textContent = "";
        const placeholder = document.createElement("p");
        placeholder.className = "faq-locator-selected-placeholder";
        placeholder.textContent = "Изберете пункт от списъка или картата, за да видите подробности.";
        el.appendChild(placeholder);
        return;
    }

    el.classList.add("has-store");
    el.innerHTML = buildStoreDetailHtml(store);
}

function selectStore(store) {
    if (!store) {
        selectedStoreId = null;
        renderSelectedStore(null);
        highlightStoreItem(null);
        return;
    }

    selectedStoreId = store.id;
    renderSelectedStore(store);
    highlightStoreItem(store.id);
}

function initDonationMap() {
    const mapEl = document.getElementById("donation_map");
    if (!mapEl || mapInitialized) {
        return;
    }

    const iframe = document.createElement("iframe");
    iframe.id = "donation_map_iframe";
    iframe.title = "Карта с пунктове за кръводаряване";
    iframe.loading = "lazy";
    iframe.referrerPolicy = "no-referrer-when-downgrade";

    mapEl.innerHTML = "";
    mapEl.appendChild(iframe);
    donationMapIframe = iframe;
    mapInitialized = true;
}

function clampLat(lat) {
    return Math.max(-85.0511, Math.min(85.0511, lat));
}

function clampLng(lng) {
    return Math.max(-180, Math.min(180, lng));
}

function setIframeMapByBounds(minLat, minLng, maxLat, maxLng, markerLat = null, markerLng = null) {
    if (!donationMapIframe) {
        return;
    }

    const west = clampLng(minLng);
    const south = clampLat(minLat);
    const east = clampLng(maxLng);
    const north = clampLat(maxLat);
    const params = new URLSearchParams({
        bbox: `${west},${south},${east},${north}`,
        layer: "mapnik"
    });

    if (Number.isFinite(markerLat) && Number.isFinite(markerLng)) {
        params.set("marker", `${clampLat(markerLat)},${clampLng(markerLng)}`);
    }

    donationMapIframe.src = `https://www.openstreetmap.org/export/embed.html?${params.toString()}`;
}

function renderStoreMarkers(stores) {
    if (!donationMapIframe) {
        return;
    }

    if (!stores.length) {
        const minLat = 41.0;
        const minLng = 22.0;
        const maxLat = 44.5;
        const maxLng = 28.8;
        setIframeMapByBounds(minLat, minLng, maxLat, maxLng);
        return;
    }

    let minLat = Infinity;
    let minLng = Infinity;
    let maxLat = -Infinity;
    let maxLng = -Infinity;

    stores.forEach((store) => {
        minLat = Math.min(minLat, store.lat);
        minLng = Math.min(minLng, store.lng);
        maxLat = Math.max(maxLat, store.lat);
        maxLng = Math.max(maxLng, store.lng);
    });

    if (mapFocus && Number.isFinite(mapFocus.lat) && Number.isFinite(mapFocus.lng)) {
        minLat = Math.min(minLat, mapFocus.lat);
        minLng = Math.min(minLng, mapFocus.lng);
        maxLat = Math.max(maxLat, mapFocus.lat);
        maxLng = Math.max(maxLng, mapFocus.lng);
    }

    const padLat = Math.max(0.2, (maxLat - minLat) * 0.2);
    const padLng = Math.max(0.2, (maxLng - minLng) * 0.2);
    const boundsMinLat = minLat - padLat;
    const boundsMinLng = minLng - padLng;
    const boundsMaxLat = maxLat + padLat;
    const boundsMaxLng = maxLng + padLng;

    const selectedStore = selectedStoreId != null
        ? stores.find((store) => Number(store.id) === Number(selectedStoreId)) || null
        : null;

    setIframeMapByBounds(
        boundsMinLat,
        boundsMinLng,
        boundsMaxLat,
        boundsMaxLng,
        selectedStore ? selectedStore.lat : (mapFocus?.lat ?? null),
        selectedStore ? selectedStore.lng : (mapFocus?.lng ?? null)
    );
}

function highlightStoreItem(storeId) {
    document.querySelectorAll(".faq-store-item").forEach((item) => {
        item.classList.toggle("active", storeId != null && Number(item.dataset.id) === Number(storeId));
    });
    if (storeId == null) {
        return;
    }
    const active = document.querySelector(`.faq-store-item[data-id="${storeId}"]`);
    if (active) {
        active.scrollIntoView({ block: "nearest", behavior: "smooth" });
    }
}

function renderStoreList(stores) {
    const list = document.getElementById("store_list");
    const count = document.getElementById("store_count");
    if (!list) {
        return;
    }

    list.innerHTML = "";

    if (count) {
        count.textContent = String(stores.length);
    }

    if (!stores.length) {
        list.innerHTML = '<li class="faq-store-empty">Няма намерени пунктове.</li>';
        return;
    }

    stores.forEach((store) => {
        const li = document.createElement("li");
        li.className = "faq-store-item";
        li.dataset.id = String(store.id);
        li.tabIndex = 0;

        const body = document.createElement("div");
        body.className = "faq-store-item-body";

        const title = document.createElement("strong");
        title.textContent = String(store.title || "");

        const address = document.createElement("span");
        address.textContent = String(store.address || "");

        body.appendChild(title);
        body.appendChild(address);

        if (store.phone) {
            const phone = document.createElement("span");
            phone.className = "faq-store-phone";
            phone.textContent = String(store.phone);
            body.appendChild(phone);
        }

        const directionsLink = document.createElement("a");
        directionsLink.href = getDirectionsUrl(store);
        directionsLink.className = "faq-store-directions";
        directionsLink.target = "_blank";
        directionsLink.rel = "noopener noreferrer";
        directionsLink.textContent = "Упътване";

        li.appendChild(body);
        li.appendChild(directionsLink);

        const directionsBtn = li.querySelector(".faq-store-directions");
        directionsBtn.addEventListener("click", (event) => {
            event.stopPropagation();
        });

        li.addEventListener("click", () => {
            selectStore(store);
            renderStoreMarkers([store]);
        });

        list.appendChild(li);
    });
}

function filterStores(query) {
    const q = query.trim().toLowerCase();
    if (!q) {
        return allStores;
    }
    return allStores.filter((store) => storeSearchText(store).includes(q));
}

function applyStoreFilter(query) {
    const filtered = filterStores(query);
    renderStoreList(filtered);
    renderStoreMarkers(filtered);
    setLocatorStatus(filtered.length ? "" : "Няма резултати за търсенето.");

    if (selectedStoreId != null && !filtered.some((store) => store.id === selectedStoreId)) {
        selectStore(null);
    } else if (selectedStoreId != null) {
        renderSelectedStore(findStoreById(selectedStoreId));
    }
}

async function loadDonationStores() {
    setLocatorStatus("Зареждане на локации...");

    try {
        const response = await fetch("../api/index.php?route=ncth_stores");
        const data = await response.json();

        if (!response.ok || data.status !== "success" || !Array.isArray(data.stores)) {
            throw new Error(data.message || "Грешка при зареждане");
        }

        allStores = data.stores.sort((a, b) => a.title.localeCompare(b.title, "bg"));
        initDonationMap();
        applyStoreFilter("");
        setLocatorStatus("");
    } catch (error) {
        setLocatorStatus(`Неуспешно зареждане: ${error.message || "Опитайте отново."}`);
        console.error(error);
    }
}

function bindLocatorControls() {
    const search = document.getElementById("locator_search");
    const geo = document.getElementById("locator_geo");

    if (search) {
        search.addEventListener("input", () => applyStoreFilter(search.value));
    }

    if (geo) {
        geo.addEventListener("click", () => {
            if (!navigator.geolocation) {
                setLocatorStatus("Геолокацията не се поддържа от браузъра.");
                return;
            }

            setLocatorStatus("Определяне на местоположение...");
            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    const { latitude, longitude } = pos.coords;
                    if (donationMapIframe) {
                        mapFocus = { lat: latitude, lng: longitude };
                        const currentStores = filterStores(document.getElementById("locator_search")?.value || "");
                        renderStoreMarkers(currentStores);
                    }
                    setLocatorStatus("");
                },
                () => setLocatorStatus("Неуспешно определяне на местоположение."),
                { enableHighAccuracy: true, timeout: 10000 }
            );
        });
    }
}

function initDonationLocator() {
    const section = document.getElementById("section-where");
    if (!section || !document.getElementById("donation_map")) {
        return;
    }

    bindLocatorControls();

    const observer = new MutationObserver(() => {
        if (!section.hidden && !allStores.length) {
            loadDonationStores();
        }
        if (!section.hidden && donationMapIframe) {
            setTimeout(() => {
                if (selectedStoreId != null) {
                    const selectedStore = findStoreById(selectedStoreId);
                    if (selectedStore) {
                        renderStoreMarkers([selectedStore]);
                        return;
                    }
                }
                renderStoreMarkers(filterStores(document.getElementById("locator_search")?.value || ""));
            }, 200);
        }
    });

    observer.observe(section, { attributes: true, attributeFilter: ["hidden"] });

    if (!section.hidden) {
        loadDonationStores();
    }
}

window.loadDonationStores = loadDonationStores;
window.invalidateDonationMap = () => {
    if (donationMapIframe) {
        const filteredStores = filterStores(document.getElementById("locator_search")?.value || "");
        renderStoreMarkers(filteredStores);
    }
};

document.addEventListener("DOMContentLoaded", initDonationLocator);
