/**
 * Profile dashboard: loads "my requests" and "my responses", donor calendar + push enablement, edit-profile modal.
 * Depends on auth-guard.js and optionally firebase-notifications.js (registerPushIfEligible).
 */

document.addEventListener("DOMContentLoaded", () => {
    if (!requireAuth()) {
        return;
    }

    initAuthUI();

    const user = getCurrentUser();
    if (!user) {
        window.location.href = "login.html";
        return;
    }

    renderUser(user);
    loadMyRequests();
    loadMyResponses();
    setupNotificationControls(user);
    setupDonationControls(user);
    setupDeleteAccountControls(user);

    function buildRequestDetailsHref(requestId) {
        return `request-details.html?id=${encodeURIComponent(String(requestId ?? ""))}`;
    }

    /** Fetches blood_requests created by this user (requester activity). */
    async function loadMyRequests() {
        try {
            const response = await authFetch("../api/index.php?route=my_requests");
            const result = await response.json();

            if (result.status === "success") {
                renderMyRequests(result.data);
            } else {
                renderMyRequests([]);
            }
        } catch (error) {
            console.error("Грешка при зареждане на заявки:", error);
            renderMyRequests([]);
        }
    }

    /** Fetches request_responses for this donor user. */
    async function loadMyResponses() {
        try {
            const response = await authFetch("../api/index.php?route=my_responses");
            const result = await response.json();

            if (result.status === "success") {
                renderMyResponses(result.data);
            } else {
                renderMyResponses([]);
            }
        } catch (error) {
            console.error("Грешка при зареждане на отзовавания:", error);
            renderMyResponses([]);
        }
    }

    /** Writes summary fields into the profile header and info panel. */
    function renderUser(user) {
        const fullName = user.name || `${user.first_name || ""} ${user.last_name || ""}`.trim() || "Потребител";
        const initials = getInitials(fullName);

        document.getElementById("profileInitials").textContent = initials;
        document.getElementById("profileName").textContent = fullName;
        document.getElementById("profileEmail").textContent = user.email || "--";

        document.getElementById("profileBloodType").textContent = user.blood_type || "--";
        document.getElementById("profileCity").textContent = user.city || "--";

        document.getElementById("infoName").textContent = fullName;
        document.getElementById("infoEmail").textContent = user.email || "--";
        document.getElementById("infoPhone").textContent = user.phone || "--";
        document.getElementById("infoCity").textContent = user.city || "--";
        document.getElementById("infoBloodType").textContent = user.blood_type || "--";
        document.getElementById("infoRole").textContent = formatUserRole(user.role);
    }

    function formatActivityDate(dateString) {
        if (!dateString) return "";
        const date = new Date(dateString);
        return date.toLocaleDateString("bg-BG", {
            day: "numeric",
            month: "long",
            year: "numeric"
        });
    }

    function formatRequestStatus(status) {
        const labels = {
            active: "Активна",
            waiting: "Изчаква",
            fulfilled: "Изпълнена",
            closed: "Затворена"
        };
        return labels[status] || status || "--";
    }

    function formatUserRole(role) {
        const labels = {
            donor: "Кръводарител",
            request: "Нуждаещ се",
            requester: "Нуждаещ се"
        };
        return labels[role] || role || "Потребител";
    }

    function buildLabeledParagraph(label, value) {
        const p = document.createElement("p");
        const strong = document.createElement("strong");
        strong.textContent = `${label}:`;
        p.appendChild(strong);
        p.append(` ${String(value ?? "")}`);
        return p;
    }

    function renderMyRequests(requests) {
        const list = document.getElementById("myRequestsList");
        const empty = document.getElementById("noMyRequests");

        list.innerHTML = "";

        if (!requests.length) {
            empty.style.display = "block";
            return;
        }

        empty.style.display = "none";

        requests.forEach(request => {
            const card = document.createElement("div");
            card.className = "activity-card";
            const canEdit = typeof canShowEditRequestButton === "function"
                && canShowEditRequestButton(user, request);
            const detailsHref = buildRequestDetailsHref(request.id);
            const header = document.createElement("div");
            header.className = "activity-card-header";

            const title = document.createElement("h4");
            title.textContent = String(request.patient_name || "");
            const date = document.createElement("span");
            date.className = "activity-date";
            date.textContent = formatActivityDate(request.created_at);

            header.appendChild(title);
            header.appendChild(date);

            const blood = buildLabeledParagraph("Кръвна група", request.blood_type);
            const city = buildLabeledParagraph("Град", request.city);
            const hospital = buildLabeledParagraph("Болница", request.hospital);
            const status = buildLabeledParagraph("Статус", formatRequestStatus(request.status));

            const actions = document.createElement("div");
            actions.className = "activity-card-actions";
            const detailsLink = document.createElement("a");
            detailsLink.href = detailsHref;
            detailsLink.textContent = "Виж заявката";
            actions.appendChild(detailsLink);

            if (canEdit) {
                const editLink = document.createElement("a");
                editLink.href = `create-request.html?id=${encodeURIComponent(String(request.id ?? ""))}`;
                editLink.className = "activity-edit-btn";
                editLink.textContent = "Редактирай";
                actions.appendChild(editLink);
            }

            card.appendChild(header);
            card.appendChild(blood);
            card.appendChild(city);
            card.appendChild(hospital);
            card.appendChild(status);
            card.appendChild(actions);

            list.appendChild(card);
        });
    }

    function renderMyResponses(responses) {
        const list = document.getElementById("myResponsesList");
        const empty = document.getElementById("noMyResponses");

        list.innerHTML = "";

        if (!responses.length) {
            empty.style.display = "block";
            return;
        }

        empty.style.display = "none";

        responses.forEach(response => {
            const card = document.createElement("div");
            card.className = "activity-card";
            const header = document.createElement("div");
            header.className = "activity-card-header";

            const title = document.createElement("h4");
            title.textContent = String(response.patient_name || "");
            const date = document.createElement("span");
            date.className = "activity-date";
            date.textContent = formatActivityDate(response.created_at);

            header.appendChild(title);
            header.appendChild(date);

            const blood = buildLabeledParagraph("Кръвна група", response.blood_type);
            const city = buildLabeledParagraph("Град", response.city);
            const status = buildLabeledParagraph("Статус", response.response_status);
            const detailsLink = document.createElement("a");
            detailsLink.href = buildRequestDetailsHref(response.request_id);
            detailsLink.textContent = "Виж заявката";

            card.appendChild(header);
            card.appendChild(blood);
            card.appendChild(city);
            card.appendChild(status);
            card.appendChild(detailsLink);

            list.appendChild(card);
        });
    }

    function getInitials(name) {
        return name
            .split(" ")
            .filter(Boolean)
            .slice(0, 2)
            .map(part => part[0].toUpperCase())
            .join("");
    }

    /** Donor-only: wires the "enable notifications" button to registerPushIfEligible. */
    function setupNotificationControls(user) {
        const button = document.getElementById("enableNotificationsBtn");
        const status = document.getElementById("notificationStatus");

        if (!button || !status) {
            return;
        }

        if (user.role !== "donor") {
            button.style.display = "none";
            status.textContent = "Известията са налични само за донорски профили.";
            return;
        }

        if (!("Notification" in window)) {
            button.disabled = true;
            status.textContent = "Този браузър не поддържа известия.";
            return;
        }

        if (Notification.permission === "granted") {
            status.textContent = "Известията вече са разрешени в браузъра.";
        }

        button.addEventListener("click", async () => {
            button.disabled = true;
            status.textContent = "Активиране на известия...";

            const result = typeof registerPushIfEligible === "function"
                ? await registerPushIfEligible(user)
                : { ok: false, message: "Липсва модул за push известия." };

            status.textContent = result?.message || "Неуспешно активиране.";
            button.disabled = false;

            if (result?.ok) {
                button.textContent = "Известията са активирани";
            }
        });
    }

    /**
     * Donor calendar: pick last donation date, show next eligible date (60-day spacing in UI logic),
     * POST update_last_donation on save.
     */
    function setupDonationControls(user) {
        function toLocalDateString(date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, "0");
            const day = String(date.getDate()).padStart(2, "0");
            return `${year}-${month}-${day}`;
        }

        function formatDate(dateString) {
            return new Date(dateString + "T12:00:00").toLocaleDateString("bg-BG");
        }

        function getNextDonationDate(dateString) {
            const date = new Date(dateString + "T12:00:00");
            date.setDate(date.getDate() + 60);
            return toLocalDateString(date);
        }

        function normalizeDateString(value) {
            if (!value) {
                return null;
            }
            const match = String(value).match(/^(\d{4}-\d{2}-\d{2})/);
            return match ? match[1] : null;
        }

        let savedDonationDate = normalizeDateString(user.last_donation_date);
        let selectedDonationDate = savedDonationDate;
        const todayString = toLocalDateString(new Date());
        let currentCalendarDate = selectedDonationDate
            ? new Date(`${selectedDonationDate}T12:00:00`)
            : new Date();

        const calendarGrid = document.getElementById("donationCalendarGrid");
        const calendarMonthTitle = document.getElementById("calendarMonthTitle");
        const lastDonationDateElement = document.getElementById("lastDonationDate");
        const nextDonationDateElement = document.getElementById("nextDonationDate");
        const prevMonthBtn = document.getElementById("prevMonthBtn");
        const nextMonthBtn = document.getElementById("nextMonthBtn");
        const saveDonationDateBtn = document.getElementById("saveDonationDateBtn");
        const todayMonthBtn = document.getElementById("todayMonthBtn");
        const clearDonationDateBtn = document.getElementById("clearDonationDateBtn");

        if (
            !calendarGrid ||
            !calendarMonthTitle ||
            !lastDonationDateElement ||
            !nextDonationDateElement ||
            !prevMonthBtn ||
            !nextMonthBtn ||
            !saveDonationDateBtn
        ) {
            return;
        }

        renderDonationCalendar();

        if (todayMonthBtn) {
            todayMonthBtn.addEventListener("click", () => {
                currentCalendarDate = new Date();
                selectedDonationDate = toLocalDateString(new Date());
                renderDonationCalendar();
            });
        }

        if (clearDonationDateBtn) {
            clearDonationDateBtn.addEventListener("click", () => {
                selectedDonationDate = null;
                renderDonationCalendar();
            });
        }

        prevMonthBtn.addEventListener("click", () => {
            currentCalendarDate.setMonth(currentCalendarDate.getMonth() - 1);
            renderDonationCalendar();
        });

        nextMonthBtn.addEventListener("click", () => {
            currentCalendarDate.setMonth(currentCalendarDate.getMonth() + 1);
            renderDonationCalendar();
        });

        saveDonationDateBtn.addEventListener("click", async () => {
            if (!selectedDonationDate) {
                alert("Моля, избери дата на последно даряване.");
                return;
            }

            try {
                const response = await authFetch("../api/index.php?route=update_last_donation", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json"
                    },
                    body: JSON.stringify({
                        last_donation_date: selectedDonationDate
                    })
                });
                const result = await response.json();

                if (!response.ok || result.status !== "success") {
                    throw new Error(result.message || "Грешка при запазване.");
                }

                user.last_donation_date = selectedDonationDate;
                localStorage.setItem("user", JSON.stringify(user));
                localStorage.setItem("currentUser", JSON.stringify(user));
                savedDonationDate = selectedDonationDate;
                renderDonationCalendar();
                alert("Последното даряване е запазено.");
            } catch (error) {
                console.error(error);
                alert(error.message || "Възникна грешка при запазване.");
            }
        });

        function renderDonationCalendar() {
            calendarGrid.innerHTML = "";

            const year = currentCalendarDate.getFullYear();
            const month = currentCalendarDate.getMonth();

            calendarMonthTitle.textContent = currentCalendarDate.toLocaleDateString("bg-BG", {
                month: "long",
                year: "numeric"
            });

            const firstDay = new Date(year, month, 1);
            const lastDay = new Date(year, month + 1, 0);

            let startWeekday = firstDay.getDay();
            if (startWeekday === 0) {
                startWeekday = 7;
            }

            for (let i = 1; i < startWeekday; i++) {
                const emptyCell = document.createElement("button");
                emptyCell.className = "calendar-day empty";
                emptyCell.disabled = true;
                calendarGrid.appendChild(emptyCell);
            }

            const nextAvailableDate = selectedDonationDate
                ? getNextDonationDate(selectedDonationDate)
                : null;

            for (let day = 1; day <= lastDay.getDate(); day++) {
                const date = new Date(year, month, day);
                const dateString = toLocalDateString(date);
                const dayButton = document.createElement("button");

                dayButton.className = "calendar-day";
                dayButton.textContent = day;
                dayButton.type = "button";

                if (dateString === todayString) {
                    dayButton.classList.add("today");
                }

                if (selectedDonationDate && dateString === selectedDonationDate) {
                    dayButton.classList.add("selected");
                }

                if (nextAvailableDate && dateString === nextAvailableDate) {
                    dayButton.classList.add("next-available");
                }

                if (
                    selectedDonationDate &&
                    nextAvailableDate &&
                    dateString > selectedDonationDate &&
                    dateString < nextAvailableDate
                ) {
                    dayButton.classList.add("recovery-period");
                    dayButton.title = "Период на възстановяване след избраното даряване";
                }

                if (dateString > todayString) {
                    dayButton.disabled = true;
                    dayButton.classList.add("future-day");
                }

                dayButton.addEventListener("click", () => {
                    if (dateString > todayString) {
                        return;
                    }
                    selectedDonationDate = dateString;
                    renderDonationCalendar();
                });

                calendarGrid.appendChild(dayButton);
            }

            updateDonationSummaryFields();
        }

        function updateDonationSummaryFields() {
            if (selectedDonationDate) {
                lastDonationDateElement.textContent = formatDate(selectedDonationDate);
                nextDonationDateElement.textContent = formatDate(getNextDonationDate(selectedDonationDate));
                return;
            }

            lastDonationDateElement.textContent = "--";
            nextDonationDateElement.textContent = "--";
        }

    }


    function setupDeleteAccountControls(currentUser) {
        const modal = document.getElementById("deleteAccountModal");
        const form = document.getElementById("deleteAccountForm");
        const openBtn = document.getElementById("openDeleteAccountBtn");
        const closeBtn = document.getElementById("closeDeleteAccountBtn");
        const cancelBtn = document.getElementById("cancelDeleteAccountBtn");
        const submitBtn = document.getElementById("confirmDeleteAccountBtn");
        const checkbox = document.getElementById("deleteConfirmCheckbox");
        const phraseInput = document.getElementById("deleteConfirmPhrase");
        const passwordInput = document.getElementById("deleteAccountPassword");
        const errorEl = document.getElementById("deleteAccountError");

        if (!modal || !form || !openBtn) {
            return;
        }

        function setDeleteError(message) {
            if (!errorEl) {
                return;
            }
            if (!message) {
                errorEl.textContent = "";
                errorEl.classList.add("hidden");
                return;
            }
            errorEl.textContent = message;
            errorEl.classList.remove("hidden");
        }

        function updateDeleteSubmitState() {
            if (!submitBtn) {
                return;
            }
            const phraseOk = phraseInput?.value.trim() === "ИЗТРИЙ";
            const checked = Boolean(checkbox?.checked);
            const hasPassword = Boolean(passwordInput?.value);
            submitBtn.disabled = !(phraseOk && checked && hasPassword);
        }

        function resetDeleteForm() {
            form.reset();
            setDeleteError("");
            updateDeleteSubmitState();
        }

        function openDeleteModal() {
            resetDeleteForm();
            modal.classList.add("active");
        }

        function closeDeleteModal() {
            modal.classList.remove("active");
            resetDeleteForm();
        }

        openBtn.addEventListener("click", openDeleteModal);
        closeBtn?.addEventListener("click", closeDeleteModal);
        cancelBtn?.addEventListener("click", closeDeleteModal);

        modal.addEventListener("click", (event) => {
            if (event.target === modal) {
                closeDeleteModal();
            }
        });

        [checkbox, phraseInput, passwordInput].forEach((el) => {
            el?.addEventListener("input", updateDeleteSubmitState);
            el?.addEventListener("change", updateDeleteSubmitState);
        });

        form.addEventListener("submit", async (event) => {
            event.preventDefault();
            setDeleteError("");

            if (phraseInput?.value.trim() !== "ИЗТРИЙ") {
                setDeleteError("Въведи ИЗТРИЙ в полето за потвърждение.");
                return;
            }

            if (!checkbox?.checked) {
                setDeleteError("Маркирай полето за съгласие.");
                return;
            }

            const password = passwordInput?.value || "";
            if (!password) {
                setDeleteError("Въведи паролата си.");
                return;
            }

            if (!window.confirm("Сигурен/а ли си? Профилът ще бъде изтрит завинаги.")) {
                return;
            }

            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = "Изтриване…";
            }

            try {
                const response = await authFetch("../api/index.php?route=delete_account", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json"
                    },
                    body: JSON.stringify({
                        password,
                        confirm_phrase: phraseInput.value.trim()
                    })
                });
                const result = await response.json();

                if (!response.ok || result.status !== "success") {
                    throw new Error(result.message || "Грешка при изтриване на профила.");
                }

                clearLoginSession();
                window.location.href = "welcome.html?account_deleted=1";
            } catch (error) {
                setDeleteError(error.message || "Възникна грешка при изтриване.");
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = "Изтрий завинаги";
                }
                updateDeleteSubmitState();
            }
        });
    }

    /* --- Edit profile modal (same DOMContentLoaded scope; uses `user` from outer closure) --- */

    const editProfileModal =
    document.getElementById("editProfileModal");

document
    .getElementById("openEditProfileBtn")
    .addEventListener("click", openEditProfileModal);

document
    .getElementById("closeEditProfileBtn")
    .addEventListener("click", closeEditProfileModal);

function toggleDonorEmailNotifyField() {
    const role = getSelectedRadioValue("editRole", "requester");
    const emailField = document.getElementById("donorEmailNotifyField");
    const requestEmailCheckbox = document.getElementById("editEmailNotifications");
    const campaignEmailCheckbox = document.getElementById("editCampaignEmailNotifications");

    if (!emailField) {
        return;
    }

    const show = role === "donor";
    emailField.classList.toggle("hidden", !show);
    if (requestEmailCheckbox) {
        requestEmailCheckbox.disabled = !show;
    }
    if (campaignEmailCheckbox) {
        campaignEmailCheckbox.disabled = !show;
    }
}

function getSelectedRadioValue(name, fallback = "") {
    return document.querySelector(`input[name="${name}"]:checked`)?.value || fallback;
}

function setRadioValue(name, value) {
    const target = document.querySelector(`input[name="${name}"][value="${value}"]`);
    if (target) {
        target.checked = true;
    }
}

function toggleDonorFields() {
    const role = getSelectedRadioValue("editRole", "requester");
    const donorField = document.getElementById("donorOnlyField");
    const donorStatusRadios = document.querySelectorAll('input[name="editDonorStatus"]');

    if (role !== "donor") {
        donorField.classList.add("blurred");
        donorStatusRadios.forEach((radio) => {
            radio.disabled = true;
        });
    } else {
        donorField.classList.remove("blurred");
        donorStatusRadios.forEach((radio) => {
            radio.disabled = false;
        });
    }

    toggleDonorEmailNotifyField();
}

document.querySelectorAll('input[name="editRole"]').forEach((radio) => {
    radio.addEventListener("change", toggleDonorFields);
});
document.querySelectorAll('input[name="editDonorStatus"]').forEach((radio) => {
    radio.addEventListener("change", () => {
        if (getSelectedRadioValue("editDonorStatus", "available") === "available") {
            const emailCheckbox = document.getElementById("editEmailNotifications");
            if (emailCheckbox) {
                emailCheckbox.checked = true;
            }
        }
        toggleDonorEmailNotifyField();
    });
});

function openEditProfileModal() {

    document.getElementById("editName").value =
        user.name || "";

    document.getElementById("editPhone").value =
        user.phone || "";

    document.getElementById("editEmail").value =
        user.email || "";

    document.getElementById("editCity").value =
        user.city || "";

    document.getElementById("editBloodType").value =
        user.blood_type || "";

    setRadioValue("editRole", user.role || "requester");

    const isAvailable =
        user.is_available === true ||
        user.is_available === 1 ||
        user.is_available === "1";

    setRadioValue("editDonorStatus", isAvailable ? "available" : "unavailable");

    const emailCheckbox = document.getElementById("editEmailNotifications");
    if (emailCheckbox) {
        emailCheckbox.checked = isAvailable;
    }
    const campaignEmailCheckbox = document.getElementById("editCampaignEmailNotifications");
    if (campaignEmailCheckbox) {
        campaignEmailCheckbox.checked =
            user.campaign_email_notifications === true ||
            user.campaign_email_notifications === 1 ||
            user.campaign_email_notifications === "1";
    }

    toggleDonorFields();

    editProfileModal.classList.add("active");
}

function closeEditProfileModal() {
    editProfileModal.classList.remove("active");
}

document
    .getElementById("editProfileForm")
    .addEventListener("submit", async (event) => {

        event.preventDefault();

        const selectedRole = getSelectedRadioValue("editRole", "requester");
        const donorAvailability = getSelectedRadioValue("editDonorStatus", "available");

        const updatedData = {
            name:
                document.getElementById("editName").value.trim(),

            phone:
                document.getElementById("editPhone").value.trim(),

            email:
                document.getElementById("editEmail").value.trim(),

            city:
                document.getElementById("editCity").value.trim(),

            blood_type:
                document.getElementById("editBloodType").value,

            role:
                selectedRole,

            is_available:
                selectedRole === "donor"
                    ? donorAvailability === "available"
                    : false,
            email_notifications:
                selectedRole === "donor" &&
                donorAvailability === "available" &&
                document.getElementById("editEmailNotifications")?.checked,
            campaign_email_notifications:
                selectedRole === "donor" &&
                document.getElementById("editCampaignEmailNotifications")?.checked
        };

        const emailChanged =
            updatedData.email !== user.email;

        try {

            const response = await authFetch("../api/index.php?route=update_profile", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json"
                },
                body: JSON.stringify(updatedData)
            });
            const result = await response.json();

            if (!response.ok || result.status !== "success") {
                throw new Error(result.message || "Грешка при обновяване.");
            }

            Object.assign(user, result.data || updatedData);
            if (result.data) {
                user.is_available =
                    result.data.is_available === true ||
                    result.data.is_available === 1 ||
                    result.data.is_available === "1";
                user.email_notifications =
                    result.data.email_notifications === true ||
                    result.data.email_notifications === 1 ||
                    result.data.email_notifications === "1";
                user.campaign_email_notifications =
                    result.data.campaign_email_notifications === true ||
                    result.data.campaign_email_notifications === 1 ||
                    result.data.campaign_email_notifications === "1";
            }

            localStorage.setItem("user", JSON.stringify(user));
            localStorage.setItem("currentUser", JSON.stringify(user));

            renderUser(user);

            closeEditProfileModal();

            if (emailChanged) {

                alert(
                    "Имейлът е променен. Необходима е нова верификация."
                );

            } else {

                alert("Профилът е обновен.");

            }

        } catch (error) {

            console.error(error);

            alert("Възникна грешка.");
        }
    });
});
