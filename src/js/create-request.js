/**
 * Create / edit blood request form: POST create_request or update_request.
 * Edit mode: ?id=<request_id> — loads request_details and allows update only for the owner.
 */

document.addEventListener("DOMContentLoaded", () => {
    if (!requireAuth()) {
        return;
    }
    initAuthUI();

    const form = document.getElementById("createRequestForm");
    const formMessage = document.getElementById("formMessage");
    const submitButton = form.querySelector("button[type='submit']");
    const formPageTitle = document.getElementById("formPageTitle");
    const formPageSubtitle = document.getElementById("formPageSubtitle");

    const urlParams = new URLSearchParams(window.location.search);
    const editRequestId = Number(urlParams.get("id"));
    const isEditMode = Number.isInteger(editRequestId) && editRequestId > 0;
    const currentUser = getCurrentUser();

    if (isEditMode) {
        document.title = "Редактирай заявка";
        if (formPageTitle) {
            formPageTitle.textContent = "Редактирай заявка за кръв";
        }
        if (formPageSubtitle) {
            formPageSubtitle.textContent = "Актуализирай информацията за твоята заявка.";
        }
        submitButton.textContent = "Запази промените";
        loadRequestForEdit(editRequestId);
    }

    form.addEventListener("submit", async (event) => {
        event.preventDefault();

        const formData = new FormData(form);

        const requestData = {
            patient_name: formData.get("patient_name").trim(),
            blood_type: formData.get("blood_type"),
            city: formData.get("city"),
            hospital: formData.get("hospital").trim(),
            contact_name: formData.get("contact_name").trim(),
            contact_phone: formData.get("contact_phone").trim(),
            description: formData.get("description").trim(),
            required_units_count: Number(formData.get("required_units_count"))
        };

        if (
            !requestData.patient_name ||
            !requestData.blood_type ||
            !requestData.city ||
            !requestData.hospital ||
            !requestData.contact_name ||
            !requestData.contact_phone ||
            requestData.required_units_count < 1
        ) {
            showMessage("Моля, попълни всички задължителни полета.", "error");
            return;
        }

        if (!currentUser || !Number.isInteger(Number(currentUser.id))) {
            showMessage("Трябва да си влязъл в профила си.", "error");
            return;
        }

        submitButton.disabled = true;
        submitButton.textContent = isEditMode ? "Запазване..." : "Публикуване...";
        showMessage(isEditMode ? "Запазване на промените..." : "Изпращане на заявката...", "success");

        try {
            let response;
            if (isEditMode) {
                response = await fetch("../api/index.php?route=update_request", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json"
                    },
                    body: JSON.stringify({
                        request_id: editRequestId,
                        user_id: Number(currentUser.id),
                        ...requestData
                    })
                });
            } else {
                requestData.created_by = Number(currentUser.id);
                response = await fetch("../api/index.php?route=create_request", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json"
                    },
                    body: JSON.stringify(requestData)
                });
            }

            const result = await response.json();

            if (!response.ok || result.status !== "success") {
                throw new Error(result.message || "Възникна грешка при обработка на заявката.");
            }

            showMessage(
                isEditMode ? "Заявката беше обновена успешно." : "Заявката беше публикувана успешно.",
                "success"
            );

            if (!isEditMode) {
                form.reset();
                document.getElementById("requiredUnits").value = 1;
            }

            setTimeout(() => {
                window.location.href = isEditMode
                    ? `request-details.html?id=${editRequestId}`
                    : "request.html";
            }, 900);
        } catch (error) {
            showMessage(error.message || "Възникна грешка при връзка със сървъра.", "error");
        } finally {
            submitButton.disabled = false;
            submitButton.textContent = isEditMode ? "Запази промените" : "Публикувай заявка";
        }
    });

    async function loadRequestForEdit(requestId) {
        try {
            const response = await fetch(`../api/index.php?route=request_details&id=${requestId}`);
            const result = await response.json();

            if (!response.ok || result.status !== "success" || !result.data) {
                throw new Error(result.message || "Заявката не е намерена.");
            }

            const request = result.data;
            if (typeof isRequestOwner === "function" && !isRequestOwner(currentUser, request)) {
                showMessage("Нямаш право да редактираш тази заявка.", "error");
                submitButton.disabled = true;
                return;
            }

            if (typeof canEditBloodRequest === "function" && !canEditBloodRequest(request)) {
                const reason = request.status !== "active"
                    ? "Само активни заявки могат да бъдат редактирани."
                    : "Изтекоха 72 часа от публикуването. Заявката вече не може да бъде редактирана.";
                showMessage(reason, "error");
                submitButton.disabled = true;
                return;
            }

            document.getElementById("patientName").value = request.patient_name || "";
            document.getElementById("bloodType").value = request.blood_type || "";
            document.getElementById("city").value = request.city || "";
            document.getElementById("hospital").value = request.hospital || "";
            document.getElementById("contactName").value = request.contact_name || "";
            document.getElementById("contactPhone").value = request.contact_phone || "";
            document.getElementById("requiredUnits").value = request.required_units_count || 1;
            document.getElementById("description").value = request.description || "";

            const minUnits = Number(request.fulfilled_units_count) || 1;
            document.getElementById("requiredUnits").min = String(minUnits);
        } catch (error) {
            showMessage(error.message || "Заявката не може да бъде заредена.", "error");
            submitButton.disabled = true;
        }
    }

    function showMessage(message, type) {
        formMessage.textContent = message;
        formMessage.className = `form-message ${type}`;
    }
});
