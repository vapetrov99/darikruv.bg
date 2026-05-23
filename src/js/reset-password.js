const resetPasswordForm = document.getElementById("resetPasswordForm");
const messageBox = document.getElementById("message");
const submitBtn = resetPasswordForm?.querySelector(".auth-submit");
const passwordInput = document.getElementById("password");
const passwordConfirm = document.getElementById("password_confirm");
const passwordMismatch = document.getElementById("passwordMismatch");
const passwordRule = document.getElementById("passwordRule");
const token = new URLSearchParams(window.location.search).get("token") || "";

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

function checkPasswordMatch() {
    if (!passwordConfirm || !passwordInput || !passwordMismatch) {
        return true;
    }
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
    const passwordValue = passwordInput?.value || "";
    const isValid = isStrongPassword(passwordValue);

    if (passwordValue === "" || isValid) {
        passwordRule?.classList.add("hidden");
        passwordInput?.classList.remove("input-error");
        return passwordValue !== "";
    }

    passwordRule?.classList.remove("hidden");
    passwordInput?.classList.add("input-error");
    return false;
}

passwordInput?.addEventListener("input", () => {
    checkPasswordStrength();
    checkPasswordMatch();
});
passwordConfirm?.addEventListener("input", checkPasswordMatch);

if (!token) {
    setAuthMessage("Липсва или е невалиден линк за смяна на парола.", "error");
    if (submitBtn) {
        submitBtn.disabled = true;
    }
}

resetPasswordForm?.addEventListener("submit", async (event) => {
    event.preventDefault();
    setAuthMessage("");

    if (!token) {
        setAuthMessage("Липсва или е невалиден линк за смяна на парола.", "error");
        return;
    }

    if (!checkPasswordMatch()) {
        setAuthMessage("Паролите не съвпадат.", "error");
        return;
    }

    const password = passwordInput?.value || "";
    if (!checkPasswordStrength()) {
        setAuthMessage("Паролата трябва да е минимум 8 символа и да съдържа малка буква, главна буква и цифра.", "error");
        return;
    }

    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = "Записване…";
    }

    try {
        const response = await fetch("../api/index.php?route=reset_password", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify({
                token,
                password
            })
        });
        const result = await response.json();

        if (!response.ok) {
            setAuthMessage(result.message || "Неуспешна смяна на парола.", "error");
            return;
        }

        setAuthMessage("Паролата е сменена успешно. Пренасочване към вход...", "success");
        resetPasswordForm.reset();
        setTimeout(() => {
            window.location.href = "login.html";
        }, 1200);
    } catch (_error) {
        setAuthMessage("Възникна грешка при връзка със сървъра.", "error");
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = "Запази нова парола";
        }
    }
});
