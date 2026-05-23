/**
 * Email verification landing page — calls verify_email API with token from URL.
 */

document.addEventListener("DOMContentLoaded", () => {
    const loadingEl = document.getElementById("verifyLoading");
    const successEl = document.getElementById("verifySuccess");
    const alreadyEl = document.getElementById("verifyAlready");
    const errorEl = document.getElementById("verifyError");
    const errorTextEl = document.getElementById("verifyErrorText");
    const successTextEl = document.getElementById("verifySuccessText");

    const params = new URLSearchParams(window.location.search);
    const token = params.get("token");
    const status = params.get("status");

    if (status === "success") {
        showState(successEl);
        return;
    }

    if (status === "already") {
        showState(alreadyEl);
        return;
    }

    if (status === "error") {
        if (errorTextEl) {
            errorTextEl.textContent = params.get("message") || "Линкът е невалиден или изтекъл.";
        }
        showState(errorEl);
        return;
    }

    if (!token) {
        if (errorTextEl) {
            errorTextEl.textContent = "Липсва код за потвърждение. Отвори линка от имейла си.";
        }
        showState(errorEl);
        return;
    }

    verifyToken(token);

    async function verifyToken(verificationToken) {
        try {
            const response = await fetch(
                `../api/index.php?route=verify_email&token=${encodeURIComponent(verificationToken)}`
            );
            const result = await response.json();

            if (response.ok && result.status === "success") {
                const msg = (result.message || "").toLowerCase();
                if (msg.includes("already")) {
                    showState(alreadyEl);
                } else {
                    if (successTextEl) {
                        successTextEl.textContent = "Акаунтът ти е активен. Можеш да влезеш и да започнеш да помагаш.";
                    }
                    showState(successEl);
                    replaceUrlWithoutToken();
                }
                return;
            }

            if (errorTextEl) {
                errorTextEl.textContent = translateError(result.message) || "Линкът е невалиден или изтекъл.";
            }
            showState(errorEl);
        } catch (_error) {
            if (errorTextEl) {
                errorTextEl.textContent = "Възникна грешка при връзка със сървъра. Опитай отново по-късно.";
            }
            showState(errorEl);
        }
    }

    function showState(activeEl) {
        [loadingEl, successEl, alreadyEl, errorEl].forEach((el) => {
            if (!el) {
                return;
            }
            el.classList.toggle("hidden", el !== activeEl);
        });
    }

    function replaceUrlWithoutToken() {
        const cleanUrl = `${window.location.pathname}?status=success`;
        window.history.replaceState({}, "", cleanUrl);
    }

    function translateError(message) {
        const map = {
            "Verification token is required": "Липсва код за потвърждение.",
            "Invalid or expired verification token": "Линкът е невалиден или вече е използван.",
            "Email verification failed": "Грешка при потвърждение. Опитай отново."
        };
        return map[message] || message;
    }
});
