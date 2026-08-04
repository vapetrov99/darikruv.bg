/**
 * Registration form: client-side validation for email/password confirmation, optional donor fields, then POST register.
 */

const registerForm = document.getElementById("registerForm");
const roleRadios = document.querySelectorAll('input[name="account_role"]');
const donorAvailabilityRadios = document.querySelectorAll('input[name="donor_availability"]');
const donorFields = document.getElementById("donorFields");
const messageBox = document.getElementById("message");
const submitBtn = registerForm?.querySelector(".auth-submit");

const emailInput = document.getElementById("email");
const emailConfirm = document.getElementById("email_confirm");
const emailMismatch = document.getElementById("emailMismatch");
const emailInvalid = document.getElementById("emailInvalid");

const passwordInput = document.getElementById("password");
const passwordConfirm = document.getElementById("password_confirm");
const passwordMismatch = document.getElementById("passwordMismatch");
const passwordRule = document.getElementById("passwordRule");
const acceptTermsCheckbox = document.getElementById("accept_terms");
const emailNotifyBlock = document.getElementById("emailNotifyBlock");

function setAuthMessage(text, type = "info") {
    if (!messageBox) {
        return;
    }
    messageBox.textContent = text;
    messageBox.className = "auth-message";
    if (text) {
        messageBox.classList.add(`auth-message--${type}`);
    }
}

function checkEmailMatch() {
    if (emailConfirm.value === "" || emailInput.value === emailConfirm.value) {
        emailMismatch.classList.add("hidden");
        emailConfirm.classList.remove("input-error");
        return true;
    }
    emailMismatch.classList.remove("hidden");
    emailConfirm.classList.add("input-error");
    return false;
}

function isValidEmailFormat(value) {
    // Practical client-side format check; backend remains the source of truth.
    return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value);
}

function checkEmailFormat() {
    const emailValue = emailInput.value.trim();
    const isValid = emailValue !== "" && isValidEmailFormat(emailValue);

    if (isValid || emailValue === "") {
        emailInvalid?.classList.add("hidden");
        emailInput.classList.remove("input-error");
        return emailValue !== "";
    }

    emailInvalid?.classList.remove("hidden");
    emailInput.classList.add("input-error");
    return false;
}

function checkPasswordMatch() {
    if (passwordConfirm.value === "" || passwordInput.value === passwordConfirm.value) {
        passwordMismatch.classList.add("hidden");
        passwordConfirm.classList.remove("input-error");
        return true;
    }
    passwordMismatch.classList.remove("hidden");
    passwordConfirm.classList.add("input-error");
    return false;
}

function isStrongPassword(value) {
    return /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/.test(value);
}

function checkPasswordStrength() {
    const passwordValue = passwordInput.value;
    const isValid = isStrongPassword(passwordValue);

    if (passwordValue === "" || isValid) {
        passwordRule?.classList.add("hidden");
        passwordInput.classList.remove("input-error");
        return passwordValue !== "";
    }

    passwordRule?.classList.remove("hidden");
    passwordInput.classList.add("input-error");
    return false;
}

emailConfirm.addEventListener("input", checkEmailMatch);
emailInput.addEventListener("input", () => {
    checkEmailFormat();
    checkEmailMatch();
});
passwordConfirm.addEventListener("input", checkPasswordMatch);
passwordInput.addEventListener("input", () => {
    checkPasswordStrength();
    checkPasswordMatch();
});

function syncDonorEmailNotifyVisibility() {
    const isAvailable = getDonorAvailability() === "available";
    const showEmail = isDonorSelected() && isAvailable;
    const emailCheckbox = document.getElementById("email_notifications");

    if (emailNotifyBlock) {
        emailNotifyBlock.classList.toggle("hidden", !showEmail);
    }

    if (showEmail && emailCheckbox) {
        emailCheckbox.checked = true;
    }
}

function getSelectedRole() {
    return document.querySelector('input[name="account_role"]:checked')?.value || "requester";
}

function isDonorSelected() {
    return getSelectedRole() === "donor";
}

function getDonorAvailability() {
    return document.querySelector('input[name="donor_availability"]:checked')?.value || "available";
}

roleRadios.forEach((radio) => {
    radio.addEventListener("change", () => {
        donorFields.classList.toggle("hidden", !isDonorSelected());
        syncDonorEmailNotifyVisibility();
    });
});

donorAvailabilityRadios.forEach((radio) => {
    radio.addEventListener("change", syncDonorEmailNotifyVisibility);
});

donorFields.classList.toggle("hidden", !isDonorSelected());
syncDonorEmailNotifyVisibility();
registerForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    setAuthMessage("");

    const emailOk = checkEmailMatch();
    const emailFormatOk = checkEmailFormat();
    const passStrengthOk = checkPasswordStrength();
    const passOk = checkPasswordMatch();

    if (!emailFormatOk || !emailOk || !passStrengthOk || !passOk) {
        if (!emailFormatOk) {
            setAuthMessage("Моля, въведи валиден имейл адрес.", "error");
            return;
        }
        if (!passStrengthOk) {
            setAuthMessage("Паролата трябва да е минимум 8 символа и да съдържа малка буква, главна буква и цифра.", "error");
            return;
        }
        setAuthMessage("Моля, коригирай полетата маркирани в червено.", "error");
        return;
    }

    const city = document.getElementById("city").value.trim();
    if (!city) {
        setAuthMessage("Моля, избери град.", "error");
        return;
    }

    if (!acceptTermsCheckbox?.checked) {
        setAuthMessage("Трябва да приемеш правилата и поверителността на сайта.", "error");
        acceptTermsCheckbox?.focus();
        return;
    }

    const payload = {
        first_name: document.getElementById("first_name").value.trim(),
        last_name: document.getElementById("last_name").value.trim(),
        email: document.getElementById("email").value.trim(),
        password: document.getElementById("password").value,
        website: document.getElementById("website")?.value || "",
        phone: document.getElementById("phone").value.trim(),
        city,
        is_donor: isDonorSelected(),
        accept_terms: true
    };

    if (isDonorSelected()) {
        const bloodType = document.getElementById("blood_type").value;
        if (!bloodType) {
            setAuthMessage("Моля, избери кръвна група, ако си дарител.", "error");
            return;
        }
        payload.blood_type = bloodType;
        payload.last_donation_date = document.getElementById("last_donation_date").value;
        payload.is_available = getDonorAvailability() === "available";
        payload.email_notifications =
            payload.is_available &&
            document.getElementById("email_notifications")?.checked;
    }

    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = "Регистриране…";
    }

    let redirecting = false;

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
            setAuthMessage(result.message || "Грешка при регистрация.", "error");
            return;
        }

        redirecting = true;
        setAuthMessage(
            "Успешна регистрация! Изпратихме имейл с линк за потвърждение — пренасочване...",
            "success"
        );

        if (submitBtn) {
            submitBtn.textContent = "Пренасочване…";
        }

        setTimeout(() => {
            window.location.href = "welcome.html";
        }, 1500);
        return;
    } catch (_error) {
        setAuthMessage("Възникна грешка при връзка със сървъра.", "error");
    } finally {
        if (!redirecting && submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = "Създай акаунт";
        }
    }
});
