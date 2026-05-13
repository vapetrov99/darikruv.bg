const registerForm = document.getElementById("registerForm");
const isDonorCheckbox = document.getElementById("is_donor");
const donorFields = document.getElementById("donorFields");
const messageBox = document.getElementById("message");

isDonorCheckbox.addEventListener("change", () => {
    donorFields.classList.toggle("hidden", !isDonorCheckbox.checked);
});

registerForm.addEventListener("submit", async (event) => {
    event.preventDefault();

    const payload = {
        first_name: document.getElementById("first_name").value.trim(),
        last_name: document.getElementById("last_name").value.trim(),
        email: document.getElementById("email").value.trim(),
        password: document.getElementById("password").value,
        phone: document.getElementById("phone").value.trim(),
        city: document.getElementById("city").value.trim(),
        is_donor: isDonorCheckbox.checked
    };

    if (isDonorCheckbox.checked) {
        payload.blood_type = document.getElementById("blood_type").value;
        payload.last_donation_date = document.getElementById("last_donation_date").value;
        payload.is_available = document.getElementById("is_available").checked;
    }

    try {
        const response = await fetch("../api/index.php?route=register", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify(payload)
        });

        const result = await response.json();

        if (!response.ok) {
            messageBox.textContent = result.message || "Грешка при регистрация.";
            return;
        }

        messageBox.textContent = `Успешна регистрация. Потвърди имейла си: ${result.data.verification_link || ""}`;
        registerForm.reset();
        donorFields.classList.add("hidden");
    } catch (error) {
        messageBox.textContent = "Възникна грешка при връзка със сървъра.";
    }
});