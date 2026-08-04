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
            const status = document.createElement("span");
            status.style.color = "#2e7d32";
            status.style.fontWeight = "600";
            status.textContent = " Профилът е изтрит успешно.";
            greetingEl.appendChild(status);
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
            el.textContent = "";
            el.append("Здравей, ");
            const strong = document.createElement("strong");
            strong.textContent = name;
            el.appendChild(strong);
            el.append(blood);
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

        card.textContent = "";

        const inCity = allRequests.filter((r) => r.city === city);
        const latest = inCity[0];

        const header = document.createElement("div");
        header.className = "welcome-card-header";

        if (!latest) {
            const title = document.createElement("h2");
            title.textContent = `Последна заявка · ${city}`;
            header.appendChild(title);

            const emptyWrap = document.createElement("div");
            emptyWrap.className = "welcome-request-empty";
            const line1 = document.createElement("p");
            line1.textContent = `Няма активна заявка в ${city} през последните 48 часа.`;
            const line2 = document.createElement("p");
            line2.style.marginTop = "8px";
            line2.textContent = "Можеш да следиш други градове или да се включиш в кръвна акция.";
            const requestsLink = document.createElement("a");
            requestsLink.href = "request.html";
            requestsLink.className = "welcome-link-btn";
            requestsLink.style.display = "inline-block";
            requestsLink.style.marginTop = "12px";
            requestsLink.textContent = "Всички заявки →";

            emptyWrap.appendChild(line1);
            emptyWrap.appendChild(line2);
            emptyWrap.appendChild(requestsLink);

            card.appendChild(header);
            card.appendChild(emptyWrap);
            return;
        }

        const user = typeof getCurrentUser === "function" ? getCurrentUser() : null;
        const required = Number(latest.required_units_count) || 0;
        const fulfilled = Number(latest.fulfilled_units_count) || 0;
        const progress = required > 0 ? Math.min(100, (fulfilled / required) * 100) : 0;
        const detailsHref = latest.id
            ? `request-details.html?id=${encodeURIComponent(String(latest.id))}`
            : "request.html";
        const title = document.createElement("h2");
        title.textContent = `Спешна нужда · ${city}`;
        header.appendChild(title);
        if (user?.blood_type && user.blood_type === latest.blood_type) {
            const matchPill = document.createElement("span");
            matchPill.className = "welcome-pill welcome-pill--match";
            matchPill.textContent = "Съвпада с вашата група";
            header.appendChild(matchPill);
        }

        const body = document.createElement("div");
        body.className = "welcome-request-body";

        const requestTitle = document.createElement("h3");
        requestTitle.textContent = String(latest.patient_name || "Заявка за кръв");

        const meta = document.createElement("div");
        meta.className = "welcome-request-meta";
        const bloodBadge = document.createElement("span");
        bloodBadge.className = "welcome-badge welcome-badge--blood";
        bloodBadge.textContent = String(latest.blood_type || "—");
        const statusBadge = document.createElement("span");
        statusBadge.className = "welcome-badge";
        statusBadge.textContent = String(latest.status || "активна");
        meta.appendChild(bloodBadge);
        meta.appendChild(statusBadge);

        const hospital = document.createElement("p");
        const hospitalLabel = document.createElement("strong");
        hospitalLabel.textContent = "Болница:";
        hospital.appendChild(hospitalLabel);
        hospital.append(` ${String(latest.hospital || "—")}`);

        const progressWrap = document.createElement("div");
        progressWrap.className = "welcome-progress";
        const progressText = document.createElement("span");
        progressText.textContent = `${fulfilled} / ${required} дарения`;
        const progressBar = document.createElement("div");
        progressBar.className = "welcome-progress-bar";
        const progressFill = document.createElement("div");
        progressFill.className = "welcome-progress-fill";
        progressFill.style.width = `${progress}%`;
        progressBar.appendChild(progressFill);
        progressWrap.appendChild(progressText);
        progressWrap.appendChild(progressBar);

        const footer = document.createElement("div");
        footer.className = "welcome-request-footer";
        const timer = document.createElement("span");
        timer.className = "welcome-timer";
        timer.textContent = `⏱ Затваря след: ${getTimeUntilClose(latest.created_at)}`;
        const detailsLink = document.createElement("a");
        detailsLink.href = detailsHref;
        detailsLink.className = "welcome-link-btn";
        detailsLink.textContent = "Виж детайли →";
        footer.appendChild(timer);
        footer.appendChild(detailsLink);

        body.appendChild(requestTitle);
        body.appendChild(meta);
        body.appendChild(hospital);
        body.appendChild(progressWrap);
        body.appendChild(footer);

        if (typeof isLoggedIn === "function" && !isLoggedIn()) {
            const loginNote = document.createElement("p");
            loginNote.style.marginTop = "8px";
            const loginLink = document.createElement("a");
            loginLink.href = "login.html";
            loginLink.className = "welcome-link-btn";
            loginLink.textContent = "Влез";
            loginNote.appendChild(loginLink);
            loginNote.append(", за да отговориш на заявката.");
            body.appendChild(loginNote);
        }

        card.appendChild(header);
        card.appendChild(body);
    }

    function renderCampaigns(city) {
        const list = document.getElementById("welcomeCampaignsList");
        if (!list || !window.CAMPAIGNS_DATA) {
            return;
        }

        const campaigns = window.CAMPAIGNS_DATA
            .filter((c) => c.city === city)
            .slice(0, 4);

        list.textContent = "";

        if (!campaigns.length) {
            const empty = document.createElement("div");
            empty.className = "welcome-campaigns-empty";
            empty.style.flex = "1";
            empty.append(`Няма въведени акции за ${city}.`);

            const link = document.createElement("a");
            link.href = "campaigns.html";
            link.className = "welcome-link-btn";
            link.style.display = "block";
            link.style.marginTop = "8px";
            link.textContent = "Виж препоръчани акции →";
            empty.appendChild(link);

            list.appendChild(empty);
            return;
        }

        campaigns.forEach((campaign) => {
            const card = document.createElement("a");
            card.className = "welcome-campaign-mini";
            card.href = sanitizeHttpUrl(campaign.href, "campaigns.html");
            card.target = "_blank";
            card.rel = "noopener noreferrer";

            const image = document.createElement("img");
            image.src = sanitizeHttpUrl(campaign.image, "");
            image.alt = String(campaign.title || "Кампания");

            const body = document.createElement("div");
            body.className = "welcome-campaign-mini-body";

            const date = document.createElement("p");
            date.className = "welcome-campaign-mini-date";
            date.textContent = String(campaign.date || "");

            const title = document.createElement("h3");
            title.textContent = String(campaign.title || "");

            const description = document.createElement("p");
            description.textContent = String(campaign.description || "");

            body.appendChild(date);
            body.appendChild(title);
            body.appendChild(description);

            card.appendChild(image);
            card.appendChild(body);
            list.appendChild(card);
        });
    }

    function renderDonorCard() {
        const card = document.getElementById("donorStatusCard");
        if (!card) {
            return;
        }

        card.textContent = "";

        const user = typeof getCurrentUser === "function" ? getCurrentUser() : null;
        const loggedIn = typeof isLoggedIn === "function" && isLoggedIn();

        if (!loggedIn || !user) {
            const header = document.createElement("div");
            header.className = "welcome-card-header";
            const title = document.createElement("h2");
            title.textContent = "Готов ли си да дариш?";
            header.appendChild(title);
            const status = document.createElement("p");
            status.className = "welcome-donor-status";
            status.textContent = "45 минути · 3 живота";
            const hint = document.createElement("p");
            hint.className = "welcome-donor-hint";
            hint.textContent = "Регистрирай се, за да получаваш известия при нова заявка в твоя град и кръвна група.";
            const link = document.createElement("a");
            link.href = "register.html";
            link.className = "btn";
            link.textContent = "Стани дарител";
            card.appendChild(header);
            card.appendChild(status);
            card.appendChild(hint);
            card.appendChild(link);
            return;
        }

        const lastDonation = normalizeDate(user.last_donation_date);
        if (!lastDonation) {
            const header = document.createElement("div");
            header.className = "welcome-card-header";
            const title = document.createElement("h2");
            title.textContent = "Твоят статус";
            header.appendChild(title);
            const status = document.createElement("p");
            status.className = "welcome-donor-status";
            status.textContent = "Последно даряване не е въведено";
            const hint = document.createElement("p");
            hint.className = "welcome-donor-hint";
            hint.textContent = "В профила можеш да отбележиш дата и да следиш кога отново можеш да дариш (мин. 60 дни).";
            const link = document.createElement("a");
            link.href = "profile.html";
            link.className = "welcome-link-btn";
            link.textContent = "Към профила →";
            card.appendChild(header);
            card.appendChild(status);
            card.appendChild(hint);
            card.appendChild(link);
            return;
        }

        const nextDate = addDays(lastDonation, DONATION_INTERVAL_DAYS);
        const today = toLocalDateString(new Date());
        const canDonate = today >= nextDate;

        if (canDonate) {
            const header = document.createElement("div");
            header.className = "welcome-card-header";
            const title = document.createElement("h2");
            title.textContent = "Твоят статус";
            const pill = document.createElement("span");
            pill.className = "welcome-pill welcome-pill--match";
            pill.textContent = "Можеш да дариш";
            header.appendChild(title);
            header.appendChild(pill);
            const status = document.createElement("p");
            status.className = "welcome-donor-status";
            status.textContent = "Готов/а си за следващо даряване";
            const hint = document.createElement("p");
            hint.className = "welcome-donor-hint";
            hint.textContent = `Виж кръвните акции в ${activeCity} или отговори на активна заявка.`;
            const link = document.createElement("a");
            link.href = "campaigns.html";
            link.className = "btn";
            link.textContent = "Кръвни акции";
            card.appendChild(header);
            card.appendChild(status);
            card.appendChild(hint);
            card.appendChild(link);
            return;
        }

        const daysLeft = daysBetween(today, nextDate);
        const header = document.createElement("div");
        header.className = "welcome-card-header";
        const title = document.createElement("h2");
        title.textContent = "Твоят статус";
        const pill = document.createElement("span");
        pill.className = "welcome-pill welcome-pill--wait";
        pill.textContent = "Изчакване";
        header.appendChild(title);
        header.appendChild(pill);
        const status = document.createElement("p");
        status.className = "welcome-donor-status";
        status.textContent = `Следващо даряване след ~${daysLeft} дни`;
        const hint = document.createElement("p");
        hint.className = "welcome-donor-hint";
        hint.textContent = `Ориентировъчно от ${formatBgDate(nextDate)}`;
        const link = document.createElement("a");
        link.href = "faq.html#where";
        link.className = "welcome-link-btn";
        link.textContent = "Къде да даря →";
        card.appendChild(header);
        card.appendChild(status);
        card.appendChild(hint);
        card.appendChild(link);
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

function sanitizeHttpUrl(value, fallback) {
    const normalizedFallback = String(fallback || "");
    const raw = String(value || "").trim();
    if (!raw) {
        return normalizedFallback;
    }

    try {
        const url = new URL(raw, window.location.origin);
        const isHttp = url.protocol === "http:" || url.protocol === "https:";
        return isHttp ? url.toString() : normalizedFallback;
    } catch (_error) {
        return normalizedFallback;
    }
}
