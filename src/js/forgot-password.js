const forgotPasswordForm = document.getElementById("forgotPasswordForm");
const messageBox = document.getElementById("message");
const submitBtn = forgotPasswordForm?.querySelector(".auth-submit");

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

forgotPasswordForm?.addEventListener("submit", async (event) => {
    event.preventDefault();
    setAuthMessage("");

    const email = document.getElementById("email")?.value.trim() || "";
    const website = document.getElementById("website")?.value || "";
    if (!email) {
        setAuthMessage("Моля, въведи имейл адрес.", "error");
        return;
    }

    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = "Изпращане…";
    }

    try {
        const response = await fetch("../api/index.php?route=request_password_reset", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify({ email, website })
        });

        const result = await response.json();
        if (!response.ok) {
            setAuthMessage(result.message || "Възникна грешка.", "error");
            return;
        }

        setAuthMessage(
            result.message || "Ако имейлът е регистриран, ще получиш линк за смяна на парола.",
            "success"
        );
        forgotPasswordForm.reset();
    } catch (_error) {
        setAuthMessage("Възникна грешка при връзка със сървъра.", "error");
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = "Изпрати линк";
        }
    }
});
