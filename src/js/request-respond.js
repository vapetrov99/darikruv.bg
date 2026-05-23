/**
 * Donor respond flow: pledge ("Ще се отзова") / confirm ("Отзовах се").
 */

function getApiUrl(route, params = {}) {
    const url = new URL("/api/index.php", window.location.origin);
    url.searchParams.set("route", route);
    Object.entries(params).forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== "") {
            url.searchParams.set(key, String(value));
        }
    });
    return url.toString();
}

function formatRequestStatusLabel(status) {
    const labels = {
        active: "Активна",
        waiting: "Изчаква",
        fulfilled: "Изпълнена",
        closed: "Затворена"
    };
    return labels[status] || status || "--";
}

function buildRequestStatusBoxHtml(status) {
    const label = formatRequestStatusLabel(status);
    const safeStatus = String(status || "active").replace(/[^a-z]/gi, "");

    return `
        <div class="request-status-box ${safeStatus}">
            <span class="request-status-box__label">Статус на заявката</span>
            <strong class="request-status-box__value">${label}</strong>
        </div>
    `.trim();
}

function updateRequestStatusBox(element, status) {
    if (!element) {
        return;
    }

    const safeStatus = String(status || "active").replace(/[^a-z]/gi, "");
    element.className = `request-status-box ${safeStatus}`;
    element.hidden = false;
    element.innerHTML = `
        <span class="request-status-box__label">Статус на заявката</span>
        <strong class="request-status-box__value">${formatRequestStatusLabel(status)}</strong>
    `;
}

function parseRequestDateTime(value) {
    if (!value) {
        return null;
    }
    return new Date(String(value).replace(" ", "T"));
}

function getWaitingTimeLeft(waitingUntil) {
    const untilDate = parseRequestDateTime(waitingUntil);
    if (!untilDate || Number.isNaN(untilDate.getTime())) {
        return "";
    }

    const diffMs = untilDate.getTime() - Date.now();
    if (diffMs <= 0) {
        return "Изтича скоро";
    }

    const totalMinutes = Math.floor(diffMs / (1000 * 60));
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;

    if (hours > 0) {
        return `${hours}ч ${minutes}м`;
    }
    return `${minutes}м`;
}

function canShowRespondButton(user, request) {
    if (!user?.id) {
        return false;
    }

    const ownerId = request.created_by !== null && request.created_by !== undefined
        ? Number(request.created_by)
        : null;

    if (ownerId !== null && ownerId === Number(user.id)) {
        return false;
    }

    if (request.my_response_status === "confirmed") {
        return false;
    }

    if (["fulfilled", "closed"].includes(request.status)) {
        return false;
    }

    return true;
}

function getRespondButtonConfig(request) {
    if (request.my_response_status === "pending") {
        return {
            text: "Отзовах се",
            action: "confirm",
            disabled: false
        };
    }

    if (request.status === "waiting") {
        return {
            hidden: true
        };
    }

    return {
        text: "Ще се отзова",
        action: "pledge",
        disabled: false
    };
}

async function submitRequestResponse(requestId, action) {
    const user = typeof getCurrentUser === "function" ? getCurrentUser() : null;
    if (!user) {
        throw new Error("Трябва да си влязъл в профила си.");
    }

    const response = await fetch(getApiUrl("respond_to_request"), {
        method: "POST",
        headers: {
            "Content-Type": "application/json"
        },
        body: JSON.stringify({
            request_id: requestId,
            donor_user_id: Number(user.id),
            action
        })
    });

    let result;
    try {
        result = await response.json();
    } catch (_error) {
        throw new Error("Сървърът върна неочакван отговор. Опитай отново.");
    }

    if (!response.ok || result.status !== "success") {
        throw new Error(result.message || "Неуспешно отзоваване.");
    }

    return result.data;
}
