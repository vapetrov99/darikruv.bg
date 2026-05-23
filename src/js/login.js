/**
 * Login page: POST credentials to api?route=login, then persist session and optionally register FCM push (donors).
 */

const loginForm = document.getElementById("loginForm");
const messageBox = document.getElementById("message");
const submitBtn = loginForm?.querySelector(".auth-submit");

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

if (isLoggedIn()) {
    window.location.href = "welcome.html";
}

loginForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    setAuthMessage("");

    const payload = {
        email: document.getElementById("email").value.trim(),
        password: document.getElementById("password").value
    };

    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = "Влизане…";
    }

    try {
        const response = await fetch("../api/index.php?route=login", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify(payload)
        });

        const result = await response.json();

        if (!response.ok) {
            setAuthMessage(result.message || "Грешка при вход.", "error");
            return;
        }

        saveLoginSession(result.data, "logged-in");
        if (typeof registerPushIfEligible === "function") {
            await registerPushIfEligible(result.data);
        }
        setAuthMessage(`Успешен вход. Добре дошъл, ${result.data.first_name}!`, "success");
        window.location.href = "welcome.html";
    } catch (_error) {
        setAuthMessage("Възникна грешка при връзка със сървъра.", "error");
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = "Влез в профила";
        }
    }
});
