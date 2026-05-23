/**
 * Blood request listing: loads GET requests, filters by city tabs, shows a 48h countdown label per card.
 * Donors can pledge / confirm via respond buttons.
 */

document.addEventListener("DOMContentLoaded", () => {
    if (!isLoggedIn()) {
        window.location.href = "auth-required.html";
        return;
    }
    initAuthUI();

    const currentUser = getCurrentUser();
    const cityButtons = document.querySelectorAll(".city-btn");
    const selectedCityTitle = document.getElementById("selectedCityTitle");
    const requestsList = document.getElementById("requestsList");
    const emptyMessage = document.getElementById("emptyMessage");
    const latestRequestsList = document.getElementById("latestRequestsList");
    const latestRequestsEmpty = document.getElementById("latestRequestsEmpty");
    let requests = [];
    const REQUEST_VISIBLE_HOURS = 48;

    function parseMySqlDateTime(value) {
        if (!value) {
            return null;
        }
        return new Date(String(value).replace(" ", "T"));
    }

    function getTimeUntilClose(createdAt) {
        const createdAtDate = parseMySqlDateTime(createdAt);
        if (!createdAtDate || Number.isNaN(createdAtDate.getTime())) {
            return "Неизвестно време до затваряне";
        }

        const closeAt = new Date(createdAtDate.getTime() + REQUEST_VISIBLE_HOURS * 60 * 60 * 1000);
        const diffMs = closeAt.getTime() - Date.now();
        if (diffMs <= 0) {
            return "Изтича скоро";
        }

        const totalMinutes = Math.floor(diffMs / (1000 * 60));
        const days = Math.floor(totalMinutes / (60 * 24));
        const hours = Math.floor((totalMinutes % (60 * 24)) / 60);
        const minutes = totalMinutes % 60;

        if (days > 0) {
            return `${days}д ${hours}ч ${minutes}м`;
        }
        return `${hours}ч ${minutes}м`;
    }

    function renderLatestRequests() {
        if (!latestRequestsList) {
            return;
        }

        latestRequestsList.innerHTML = "";

        if (!requests.length) {
            if (latestRequestsEmpty) {
                latestRequestsEmpty.style.display = "block";
            }
            return;
        }

        if (latestRequestsEmpty) {
            latestRequestsEmpty.style.display = "none";
        }

        requests.forEach(request => {
            const item = document.createElement("article");
            item.className = "latest-request-item";
            item.setAttribute("role", "link");
            item.setAttribute("tabindex", "0");

            const statusLabel = typeof formatRequestStatusLabel === "function"
                ? formatRequestStatusLabel(request.status)
                : request.status;
            const timeUntilClose = getTimeUntilClose(request.created_at);
            const requiredUnits = Number(request.required_units_count) || 0;
            const fulfilledUnits = Number(request.fulfilled_units_count) || 0;

            item.innerHTML = `
                <div class="latest-request-item__top">
                    <h4>${request.patient_name}</h4>
                    <span class="request-status ${request.status}">${statusLabel}</span>
                </div>
                <p class="latest-request-item__meta">
                    <span>${request.blood_type}</span>
                    <span>${request.city}</span>
                </p>
                <p class="latest-request-item__hospital">${request.hospital}</p>
                <p class="latest-request-item__progress">${fulfilledUnits} / ${requiredUnits} дарения</p>
                <p class="latest-request-item__timer">Затваря след: ${timeUntilClose}</p>
            `;

            const openDetails = () => {
                if (request.id) {
                    window.location.href = `request-details.html?id=${request.id}`;
                }
            };

            item.addEventListener("click", openDetails);
            item.addEventListener("keydown", (event) => {
                if (event.key === "Enter" || event.key === " ") {
                    event.preventDefault();
                    openDetails();
                }
            });

            latestRequestsList.appendChild(item);
        });
    }

    function renderRequests(city) {
        selectedCityTitle.textContent = city;
        requestsList.innerHTML = "";

        const filteredRequests = requests.filter(request => request.city === city);

        if (filteredRequests.length === 0) {
            emptyMessage.style.display = "block";
            return;
        }

        emptyMessage.style.display = "none";

        filteredRequests.forEach(request => {
            const card = document.createElement("div");
            card.className = "request-card";
            card.style.cursor = "pointer";
            card.setAttribute("role", "link");
            card.setAttribute("tabindex", "0");

            const requiredUnits = Number(request.required_units_count) || 0;
            const fulfilledUnits = Number(request.fulfilled_units_count) || 0;
            const progressWidth = requiredUnits > 0
                ? Math.min(100, Math.max(0, (fulfilledUnits / requiredUnits) * 100))
                : 0;
            const timeUntilClose = getTimeUntilClose(request.created_at);
            const statusLabel = typeof formatRequestStatusLabel === "function"
                ? formatRequestStatusLabel(request.status)
                : request.status;
            const showRespond = typeof canShowRespondButton === "function"
                && canShowRespondButton(currentUser, request);
            const respondConfig = showRespond && typeof getRespondButtonConfig === "function"
                ? getRespondButtonConfig(request)
                : { hidden: true };
            const respondButtonHtml = !respondConfig.hidden
                ? `<button type="button" class="btn respond-btn" data-request-id="${request.id}" data-action="${respondConfig.action}">${respondConfig.text}</button>`
                : "";

            card.innerHTML = `
                <h4>${request.patient_name}</h4>

                <div class="request-info">
                    <span class="request-badge">Кръв: ${request.blood_type}</span>
                    <span class="request-badge city">${request.city}</span>
                    <span class="request-status ${request.status}">${statusLabel}</span>
                </div>

                <p><strong>Болница:</strong> ${request.hospital}</p>

                <p><strong>Контакт:</strong> ${request.contact_name}</p>
                <p><strong>Телефон:</strong> ${request.contact_phone}</p>

                <p>${request.description || "Няма допълнително описание."}</p>

                <div class="request-progress">
                    <span>${fulfilledUnits} / ${requiredUnits} дарения</span>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: ${progressWidth}%"></div>
                    </div>
                </div>

                <span class="request-date">Публикувана: ${request.created_at}</span>
                <span class="request-timer">Затваря след: ${timeUntilClose}</span>

                <div class="request-card-actions">
                    ${respondButtonHtml}
                </div>
            `;

            const openDetails = () => {
                if (!request.id) {
                    return;
                }
                window.location.href = `request-details.html?id=${request.id}`;
            };

            card.addEventListener("click", openDetails);
            card.addEventListener("keydown", (event) => {
                if (event.key === "Enter" || event.key === " ") {
                    event.preventDefault();
                    openDetails();
                }
            });

            const respondBtn = card.querySelector(".respond-btn");
            if (respondBtn) {
                respondBtn.addEventListener("click", async (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    event.stopImmediatePropagation();

                    const action = respondBtn.dataset.action;
                    const requestId = Number(respondBtn.dataset.requestId);
                    if (!action || !requestId) {
                        return;
                    }

                    const originalText = respondBtn.textContent;
                    respondBtn.disabled = true;
                    respondBtn.textContent = "Изпращане...";

                    try {
                        const data = await submitRequestResponse(requestId, action);
                        if (data?.request) {
                            const index = requests.findIndex(item => Number(item.id) === requestId);
                            if (index >= 0) {
                                if (data.request.status === "fulfilled") {
                                    requests.splice(index, 1);
                                } else {
                                    requests[index] = {
                                        ...requests[index],
                                        ...data.request,
                                        my_response_status: data.my_response_status
                                    };
                                }
                            }
                        } else {
                            await loadRequests();
                            return;
                        }
                        renderAll(city);
                    } catch (error) {
                        alert(error.message || "Възникна грешка.");
                        respondBtn.disabled = false;
                        respondBtn.textContent = originalText;
                    }
                }, true);
            }

            requestsList.appendChild(card);
        });
    }

    function renderAll(city) {
        renderLatestRequests();
        renderRequests(city);
    }

    async function loadRequests() {
        try {
            const response = await fetch(getApiUrl("requests", {
                user_id: currentUser?.id || undefined
            }));
            const result = await response.json();

            if (!response.ok || result.status !== "success") {
                throw new Error(result.message || "Неуспешно зареждане на заявките.");
            }

            requests = Array.isArray(result.data) ? result.data : [];
            const activeCity = document.querySelector(".city-btn.active")?.dataset.city || "София";
            renderAll(activeCity);
        } catch (_error) {
            requests = [];
            requestsList.innerHTML = "";
            if (latestRequestsList) {
                latestRequestsList.innerHTML = "";
            }
            if (latestRequestsEmpty) {
                latestRequestsEmpty.textContent = "Грешка при зареждане на заявките.";
                latestRequestsEmpty.style.display = "block";
            }
            emptyMessage.textContent = "Грешка при зареждане на заявките.";
            emptyMessage.style.display = "block";
        }
    }

    cityButtons.forEach(button => {
        button.addEventListener("click", () => {
            cityButtons.forEach(btn => btn.classList.remove("active"));
            button.classList.add("active");
            renderRequests(button.dataset.city);
        });
    });

    loadRequests();
    setInterval(() => {
        const activeCity = document.querySelector(".city-btn.active")?.dataset.city || "София";
        renderAll(activeCity);
    }, 60000);
});
