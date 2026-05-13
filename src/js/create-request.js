document.addEventListener("DOMContentLoaded", () => {
    const form = document.getElementById("createRequestForm");
    const formMessage = document.getElementById("formMessage");
    const submitButton = form.querySelector("button[type='submit']");

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

        const currentUserRaw = localStorage.getItem("currentUser");
        if (currentUserRaw) {
            try {
                const currentUser = JSON.parse(currentUserRaw);
                if (currentUser && Number.isInteger(Number(currentUser.id))) {
                    requestData.created_by = Number(currentUser.id);
                }
            } catch (_error) {
                // Ignore invalid localStorage data and continue as anonymous requester.
            }
        }

        submitButton.disabled = true;
        submitButton.textContent = "Публикуване...";
        showMessage("Изпращане на заявката...", "success");

        try {
            const response = await fetch("../api/index.php?route=create_request", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json"
                },
                body: JSON.stringify(requestData)
            });

            const result = await response.json();

            if (!response.ok || result.status !== "success") {
                throw new Error(result.message || "Възникна грешка при създаване на заявката.");
            }

            showMessage("Заявката беше публикувана успешно.", "success");
            form.reset();
            document.getElementById("requiredUnits").value = 1;
            setTimeout(() => {
                window.location.href = "request.html";
            }, 900);
        } catch (error) {
            showMessage(error.message || "Възникна грешка при връзка със сървъра.", "error");
        } finally {
            submitButton.disabled = false;
            submitButton.textContent = "Публикувай заявка";
        }
    });

    function showMessage(message, type) {
        formMessage.textContent = message;
        formMessage.className = `form-message ${type}`;
    }
});