const loginForm = document.getElementById("loginForm");
const messageBox = document.getElementById("message");

loginForm.addEventListener("submit", async (event) => {
    event.preventDefault();

    const payload = {
        email: document.getElementById("email").value.trim(),
        password: document.getElementById("password").value
    };

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
            messageBox.textContent = result.message || "Грешка при вход.";
            return;
        }

        messageBox.textContent = `Успешен вход. Добре дошъл, ${result.data.first_name}!`;
    } catch (error) {
        messageBox.textContent = "Възникна грешка при връзка със сървъра.";
    }
});