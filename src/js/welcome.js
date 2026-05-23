/**
 * Welcome dashboard: locality-based latest request + blood drives + donor eligibility hint.
 */

const WELCOME_CITIES = [
    "София",
    "Пловдив",
    "Варна",
    "Бургас",
    "Стара Загора",
    "Плевен",
    "Русе",
    "Благоевград",
    "Велико Търново",
    "Добрич",
    "Перник",
    "Хасково"
];

const REQUEST_VISIBLE_HOURS = 48;
const DONATION_INTERVAL_DAYS = 60;
const WELCOME_CITY_KEY = "welcomeCity";

document.addEventListener("DOMContentLoaded", () => {
    initAuthUI();

    const citySelect = document.getElementById("welcomeCitySelect");
    const heroCity = document.getElementById("heroCity");
    const campaignsCityLabel = document.getElementById("campaignsCityLabel");
    const greetingEl = document.getElementById("welcomeGreeting");

    if (!citySelect) {
        return;
    }

    populateCitySelect(citySelect);

    let requests = [];
    let activeCity = resolveInitialCity();

    citySelect.value = activeCity;
    updateCityLabels(activeCity);

    if (greetingEl) {
        renderGreeting(greetingEl);
        const deleted = new URLSearchParams(window.location.search).get("account_deleted");
        if (deleted === "1") {
            greetingEl.innerHTML += ' <span style="color:#2e7d32;font-weight:600">Профилът е изтрит успешно.</span>';
            window.history.replaceState({}, "", window.location.pathname);
        }
    }

    renderDonorCard();
    renderCampaigns(activeCity);
    renderLatestRequest(activeCity, requests);

    citySelect.addEventListener("change", () => {
        activeCity = citySelect.value;
        localStorage.setItem(WELCOME_CITY_KEY, activeCity);
        updateCityLabels(activeCity);
        renderCampaigns(activeCity);
        renderLatestRequest(activeCity, requests);
    });

    loadRequests().then((data) => {
        requests = data;
        renderLatestRequest(activeCity, requests);
    });

    setInterval(() => {
        renderLatestRequest(activeCity, requests);
        renderDonorCard();
    }, 60000);

    function resolveInitialCity() {
        const user = typeof getCurrentUser === "function" ? getCurrentUser() : null;
        if (user?.city && WELCOME_CITIES.includes(user.city)) {
            return user.city;
        }
        const saved = localStorage.getItem(WELCOME_CITY_KEY);
        if (saved && WELCOME_CITIES.includes(saved)) {
            return saved;
        }
        return "София";
    }

    function populateCitySelect(select) {
        WELCOME_CITIES.forEach((city) => {
            const option = document.createElement("option");
            option.value = city;
            option.textContent = city;
            select.appendChild(option);
        });
    }

    function updateCityLabels(city) {
        if (heroCity) {
            heroCity.textContent = city;
        }
        if (campaignsCityLabel) {
            campaignsCityLabel.textContent = city;
        }
    }

    function renderGreeting(el) {
        const user = typeof getCurrentUser === "function" ? getCurrentUser() : null;
        if (typeof isLoggedIn === "function" && isLoggedIn() && user) {
            const name = typeof getDisplayName === "function" ? getDisplayName(user) : "Потребител";
            const blood = user.blood_type ? ` · ${user.blood_type}` : "";
            el.innerHTML = `Здравей, <strong>${escapeHtml(name)}</strong>${escapeHtml(blood)}`;
            return;
        }
        el.textContent = "Избери град, за да видиш актуални заявки и акции";
    }

    async function loadRequests() {
        try {
            const response = await fetch("../api/index.php?route=requests");
            const result = await response.json();
            if (!response.ok || result.status !== "success") {
                return [];
            }
            return Array.isArray(result.data) ? result.data : [];
        } catch (_error) {
            return [];
        }
    }

    function renderLatestRequest(city, allRequests) {
        const card = document.getElementById("latestRequestCard");
        if (!card) {
            return;
        }

        const inCity = allRequests.filter((r) => r.city === city);
        const latest = inCity[0];

        if (!latest) {
            card.innerHTML = `
                <div class="welcome-card-header">
                    <h2>Последна заявка · ${escapeHtml(city)}</h2>
                </div>
                <div class="welcome-request-empty">
                    <p>Няма активна заявка в ${escapeHtml(city)} през последните 48 часа.</p>
                    <p style="margin-top:8px">Можеш да следиш други градове или да се включиш в кръвна акция.</p>
                    <a href="request.html" class="welcome-link-btn" style="display:inline-block;margin-top:12px">Всички заявки →</a>
                </div>
            `;
            return;
        }

        const user = typeof getCurrentUser === "function" ? getCurrentUser() : null;
        const required = Number(latest.required_units_count) || 0;
        const fulfilled = Number(latest.fulfilled_units_count) || 0;
        const progress = required > 0 ? Math.min(100, (fulfilled / required) * 100) : 0;
        const matchPill = user?.blood_type && user.blood_type === latest.blood_type
            ? '<span class="welcome-pill welcome-pill--match">Съвпада с вашата група</span>'
            : "";
        const detailsHref = latest.id
            ? `request-details.html?id=${latest.id}`
            : "request.html";
        const loginNote = typeof isLoggedIn === "function" && !isLoggedIn()
            ? '<p style="margin-top:8px"><a href="login.html" class="welcome-link-btn">Влез</a>, за да отговориш на заявката.</p>'
            : "";

        card.innerHTML = `
            <div class="welcome-card-header">
                <h2>Спешна нужда · ${escapeHtml(city)}</h2>
                ${matchPill}
            </div>
            <div class="welcome-request-body">
                <h3>${escapeHtml(latest.patient_name || "Заявка за кръв")}</h3>
                <div class="welcome-request-meta">
                    <span class="welcome-badge welcome-badge--blood">${escapeHtml(latest.blood_type || "—")}</span>
                    <span class="welcome-badge">${escapeHtml(latest.status || "активна")}</span>
                </div>
                <p><strong>Болница:</strong> ${escapeHtml(latest.hospital || "—")}</p>
                <div class="welcome-progress">
                    <span>${fulfilled} / ${required} дарения</span>
                    <div class="welcome-progress-bar">
                        <div class="welcome-progress-fill" style="width:${progress}%"></div>
                    </div>
                </div>
                <div class="welcome-request-footer">
                    <span class="welcome-timer">⏱ Затваря след: ${escapeHtml(getTimeUntilClose(latest.created_at))}</span>
                    <a href="${detailsHref}" class="welcome-link-btn">Виж детайли →</a>
                </div>
                ${loginNote}
            </div>
        `;
    }

    function renderCampaigns(city) {
        const list = document.getElementById("welcomeCampaignsList");
        if (!list || !window.CAMPAIGNS_DATA) {
            return;
        }

        const campaigns = window.CAMPAIGNS_DATA
            .filter((c) => c.city === city)
            .slice(0, 4);

        if (!campaigns.length) {
            list.innerHTML = `
                <div class="welcome-campaigns-empty" style="flex:1">
                    Няма въведени акции за ${escapeHtml(city)}.
                    <a href="campaigns.html" class="welcome-link-btn" style="display:block;margin-top:8px">Виж препоръчани акции →</a>
                </div>
            `;
            return;
        }

        list.innerHTML = campaigns.map((c) => `
            <a class="welcome-campaign-mini"
               href="${escapeHtml(c.href)}"
               target="_blank"
               rel="noopener noreferrer">
                <img src="${escapeHtml(c.image)}" alt="${escapeHtml(c.title)}">
                <div class="welcome-campaign-mini-body">
                    <p class="welcome-campaign-mini-date">${escapeHtml(c.date)}</p>
                    <h3>${escapeHtml(c.title)}</h3>
                    <p>${escapeHtml(c.description)}</p>
                </div>
            </a>
        `).join("");
    }

    function renderDonorCard() {
        const card = document.getElementById("donorStatusCard");
        if (!card) {
            return;
        }

        const user = typeof getCurrentUser === "function" ? getCurrentUser() : null;
        const loggedIn = typeof isLoggedIn === "function" && isLoggedIn();

        if (!loggedIn || !user) {
            card.innerHTML = `
                <div class="welcome-card-header"><h2>Готов ли си да дариш?</h2></div>
                <p class="welcome-donor-status">45 минути · 3 живота</p>
                <p class="welcome-donor-hint">Регистрирай се, за да получаваш известия при нова заявка в твоя град и кръвна група.</p>
                <a href="register.html" class="btn">Стани дарител</a>
            `;
            return;
        }

        const lastDonation = normalizeDate(user.last_donation_date);
        if (!lastDonation) {
            card.innerHTML = `
                <div class="welcome-card-header"><h2>Твоят статус</h2></div>
                <p class="welcome-donor-status">Последно даряване не е въведено</p>
                <p class="welcome-donor-hint">В профила можеш да отбележиш дата и да следиш кога отново можеш да дариш (мин. 60 дни).</p>
                <a href="profile.html" class="welcome-link-btn">Към профила →</a>
            `;
            return;
        }

        const nextDate = addDays(lastDonation, DONATION_INTERVAL_DAYS);
        const today = toLocalDateString(new Date());
        const canDonate = today >= nextDate;

        if (canDonate) {
            card.innerHTML = `
                <div class="welcome-card-header">
                    <h2>Твоят статус</h2>
                    <span class="welcome-pill welcome-pill--match">Можеш да дариш</span>
                </div>
                <p class="welcome-donor-status">Готов/а си за следващо даряване</p>
                <p class="welcome-donor-hint">Виж кръвните акции в ${escapeHtml(activeCity)} или отговори на активна заявка.</p>
                <a href="campaigns.html" class="btn">Кръвни акции</a>
            `;
            return;
        }

        const daysLeft = daysBetween(today, nextDate);
        card.innerHTML = `
            <div class="welcome-card-header">
                <h2>Твоят статус</h2>
                <span class="welcome-pill welcome-pill--wait">Изчакване</span>
            </div>
            <p class="welcome-donor-status">Следващо даряване след ~${daysLeft} дни</p>
            <p class="welcome-donor-hint">Ориентировъчно от ${formatBgDate(nextDate)}</p>
            <a href="faq.html#where" class="welcome-link-btn">Къде да даря →</a>
        `;
    }
});

function parseMySqlDateTime(value) {
    if (!value) {
        return null;
    }
    return new Date(String(value).replace(" ", "T"));
}

function getTimeUntilClose(createdAt) {
    const created = parseMySqlDateTime(createdAt);
    if (!created || Number.isNaN(created.getTime())) {
        return "—";
    }
    const closeAt = new Date(created.getTime() + REQUEST_VISIBLE_HOURS * 60 * 60 * 1000);
    const diffMs = closeAt.getTime() - Date.now();
    if (diffMs <= 0) {
        return "скоро";
    }
    const totalMinutes = Math.floor(diffMs / (1000 * 60));
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;
    if (hours >= 24) {
        const days = Math.floor(hours / 24);
        return `${days}д ${hours % 24}ч`;
    }
    return `${hours}ч ${minutes}м`;
}

function normalizeDate(value) {
    if (!value) {
        return null;
    }
    const match = String(value).match(/^(\d{4}-\d{2}-\d{2})/);
    return match ? match[1] : null;
}

function toLocalDateString(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, "0");
    const d = String(date.getDate()).padStart(2, "0");
    return `${y}-${m}-${d}`;
}

function addDays(dateString, days) {
    const date = new Date(`${dateString}T12:00:00`);
    date.setDate(date.getDate() + days);
    return toLocalDateString(date);
}

function daysBetween(from, to) {
    const a = new Date(`${from}T12:00:00`);
    const b = new Date(`${to}T12:00:00`);
    return Math.max(0, Math.ceil((b - a) / (1000 * 60 * 60 * 24)));
}

function formatBgDate(dateString) {
    return new Date(`${dateString}T12:00:00`).toLocaleDateString("bg-BG", {
        day: "numeric",
        month: "long",
        year: "numeric"
    });
}

function escapeHtml(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");
}
