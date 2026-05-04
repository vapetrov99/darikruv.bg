document.addEventListener("DOMContentLoaded", () => {
    const cityButtons = document.querySelectorAll(".city-btn");
    const selectedCityTitle = document.getElementById("selectedCityTitle");
    const requestsList = document.getElementById("requestsList");
    const emptyMessage = document.getElementById("emptyMessage");
    let requests = [];
    const REQUEST_VISIBLE_HOURS = 48;

    function parseMySqlDateTime(value) {
        if (!value) {
            return null;
        }
        // Converts "YYYY-MM-DD HH:mm:ss" to a Date that JS parses consistently.
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

            card.innerHTML = `
                <h4>${request.patient_name}</h4>

                <div class="request-info">
                    <span class="request-badge">Кръв: ${request.blood_type}</span>
                    <span class="request-badge city">${request.city}</span>
                    <span class="request-status ${request.status}">${request.status}</span>
                </div>

                <p><strong>Болница:</strong> ${request.hospital}</p>

                <p><strong>Контакт:</strong> ${request.contact_name}</p>
                <p><strong>Телефон:</strong> ${request.contact_phone}</p>

                <p>${request.description || "Няма допълнително описание."}</p>

                <div class="request-progress">
                    <span>${fulfilledUnits} / ${requiredUnits} дарения</span>
                    <div class="progress-bar">
                        <div class="progress-fill"
                            style="width: ${progressWidth}%">
                        </div>
                    </div>
                </div>

                <span class="request-date">
                    Публикувана: ${request.created_at}
                </span>
                <span class="request-timer">
                    Затваря след: ${timeUntilClose}
                </span>
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

            requestsList.appendChild(card);
        });
    }

    async function loadRequests() {
        try {
            const response = await fetch("../api/index.php?route=requests");
            const result = await response.json();

            if (!response.ok || result.status !== "success") {
                throw new Error(result.message || "Неуспешно зареждане на заявките.");
            }

            requests = Array.isArray(result.data) ? result.data : [];
            const activeCity = document.querySelector(".city-btn.active")?.dataset.city || "София";
            renderRequests(activeCity);
        } catch (_error) {
            requests = [];
            requestsList.innerHTML = "";
            emptyMessage.textContent = "Грешка при зареждане на заявките.";
            emptyMessage.style.display = "block";
        }
    }

    cityButtons.forEach(button => {
        button.addEventListener("click", () => {
            cityButtons.forEach(btn => btn.classList.remove("active"));
            button.classList.add("active");

            const selectedCity = button.dataset.city;
            renderRequests(selectedCity);
        });
    });

    loadRequests();
    setInterval(() => {
        const activeCity = document.querySelector(".city-btn.active")?.dataset.city || "София";
        renderRequests(activeCity);
    }, 60000);
});