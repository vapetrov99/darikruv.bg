/**
 * Blood donation locator map – data from NCTH (via local API proxy).
 * @see https://ncth.bg/contacts/
 */

const DONATION_MAP_DEFAULT = { lat: 42.6954108, lng: 23.2539071, zoom: 7 };

let donationMap = null;
let donationMarkers = null;
let allStores = [];
let mapInitialized = false;
let selectedStoreId = null;

function escapeHtml(text) {
    return String(text || "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");
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
    return `https://www.google.com/maps/dir/?api=1&destination=${store.lat},${store.lng}`;
}

function findStoreById(storeId) {
    return allStores.find((store) => Number(store.id) === Number(storeId)) || null;
}

function buildStoreDetailHtml(store) {
    const phone = store.phone
        ? `<p><strong>Телефон:</strong> <a href="tel:${store.phone.replace(/\s/g, "")}">${escapeHtml(store.phone)}</a></p>`
        : "";
    const email = store.email
        ? `<p><strong>Имейл:</strong> <a href="mailto:${escapeHtml(store.email)}">${escapeHtml(store.email)}</a></p>`
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
        el.innerHTML =
            '<p class="faq-locator-selected-placeholder">Изберете пункт от списъка или картата, за да видите подробности.</p>';
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

function buildPopupContent(store) {
    const phone = store.phone
        ? `<p><strong>Тел.:</strong> <a href="tel:${store.phone.replace(/\s/g, "")}">${escapeHtml(store.phone)}</a></p>`
        : "";
    const email = store.email
        ? `<p><strong>Email:</strong> <a href="mailto:${escapeHtml(store.email)}">${escapeHtml(store.email)}</a></p>`
        : "";
    const description = store.description
        ? `<p>${escapeHtml(store.description).replace(/\n/g, "<br>")}</p>`
        : "";

    return `
        <div class="faq-map-popup">
            <h4>${escapeHtml(store.title)}</h4>
            <p>${escapeHtml(store.address)}</p>
            ${description}
            ${phone}
            ${email}
            <a href="${getDirectionsUrl(store)}" class="faq-directions-link" target="_blank" rel="noopener noreferrer">Упътване</a>
        </div>
    `;
}

function initDonationMap() {
    const mapEl = document.getElementById("donation_map");
    if (!mapEl || mapInitialized || typeof L === "undefined") {
        return;
    }

    donationMap = L.map(mapEl).setView(
        [DONATION_MAP_DEFAULT.lat, DONATION_MAP_DEFAULT.lng],
        DONATION_MAP_DEFAULT.zoom
    );

    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        maxZoom: 18,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(donationMap);

    donationMarkers = L.layerGroup().addTo(donationMap);
    mapInitialized = true;
}

function renderStoreMarkers(stores) {
    if (!donationMap || !donationMarkers) {
        return;
    }

    donationMarkers.clearLayers();
    const bounds = [];

    stores.forEach((store) => {
        const marker = L.marker([store.lat, store.lng]);
        marker.bindPopup(buildPopupContent(store));
        marker.on("click", () => selectStore(store));
        donationMarkers.addLayer(marker);
        bounds.push([store.lat, store.lng]);
    });

    if (bounds.length === 1) {
        donationMap.setView(bounds[0], 12);
    } else if (bounds.length > 1) {
        donationMap.fitBounds(bounds, { padding: [30, 30] });
    }
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
        li.innerHTML = `
            <div class="faq-store-item-body">
                <strong>${escapeHtml(store.title)}</strong>
                <span>${escapeHtml(store.address)}</span>
                ${store.phone ? `<span class="faq-store-phone">${escapeHtml(store.phone)}</span>` : ""}
            </div>
            <a href="${getDirectionsUrl(store)}" class="faq-store-directions" target="_blank" rel="noopener noreferrer">Упътване</a>
        `;

        const directionsBtn = li.querySelector(".faq-store-directions");
        directionsBtn.addEventListener("click", (event) => {
            event.stopPropagation();
        });

        li.addEventListener("click", () => {
            selectStore(store);
            donationMap.setView([store.lat, store.lng], 13);
            donationMarkers.eachLayer((layer) => {
                if (layer.getLatLng().lat === store.lat && layer.getLatLng().lng === store.lng) {
                    layer.openPopup();
                }
            });
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
        setLocatorStatus("Неуспешно зареждане. Опитайте отново или вижте ncth.bg/contacts");
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
                    if (donationMap) {
                        donationMap.setView([latitude, longitude], 10);
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
        if (!section.hidden && donationMap) {
            setTimeout(() => donationMap.invalidateSize(), 200);
        }
    });

    observer.observe(section, { attributes: true, attributeFilter: ["hidden"] });

    if (!section.hidden) {
        loadDonationStores();
    }
}

window.loadDonationStores = loadDonationStores;
window.invalidateDonationMap = () => {
    if (donationMap) {
        donationMap.invalidateSize();
    }
};

document.addEventListener("DOMContentLoaded", initDonationLocator);
