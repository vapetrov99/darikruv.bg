/**
 * Admin panel: users list and donors list.
 */

document.addEventListener("DOMContentLoaded", () => {
    if (!requireAdmin("welcome.html")) {
        return;
    }

    initAuthUI();

    const statusEl = document.getElementById("adminStatus");
    const usersTableBody = document.getElementById("usersTableBody");
    const donorsTableBody = document.getElementById("donorsTableBody");
    const reloadUsersBtn = document.getElementById("reloadUsersBtn");
    const reloadDonorsBtn = document.getElementById("reloadDonorsBtn");

    function escapeHtml(value) {
        return String(value ?? "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#39;");
    }

    function setStatus(message, isError = false) {
        if (!statusEl) {
            return;
        }
        statusEl.textContent = message;
        statusEl.style.color = isError ? "#b3261e" : "#1f6f43";
    }

    function getApiUrl(route) {
        return `../api/index.php?route=${encodeURIComponent(route)}`;
    }

    function renderUsers(users) {
        usersTableBody.innerHTML = "";
        if (!Array.isArray(users) || users.length === 0) {
            usersTableBody.innerHTML = '<tr><td colspan="5" style="padding:8px;">Няма данни.</td></tr>';
            return;
        }

        users.forEach((user) => {
            const row = document.createElement("tr");
            row.style.borderBottom = "1px solid #eee";
            row.innerHTML = `
                <td style="padding:8px;">${escapeHtml(user.public_id)}</td>
                <td style="padding:8px;">${escapeHtml(`${user.first_name || ""} ${user.last_name || ""}`.trim())}</td>
                <td style="padding:8px;">${escapeHtml(user.email)}</td>
                <td style="padding:8px;">${escapeHtml(user.city)}</td>
                <td style="padding:8px;">${escapeHtml(user.role)}</td>
            `;
            usersTableBody.appendChild(row);
        });
    }

    function renderDonors(donors) {
        donorsTableBody.innerHTML = "";
        if (!Array.isArray(donors) || donors.length === 0) {
            donorsTableBody.innerHTML = '<tr><td colspan="5" style="padding:8px;">Няма данни.</td></tr>';
            return;
        }

        donors.forEach((donor) => {
            const row = document.createElement("tr");
            row.style.borderBottom = "1px solid #eee";
            row.innerHTML = `
                <td style="padding:8px;">${escapeHtml(donor.user_public_id)}</td>
                <td style="padding:8px;">${escapeHtml(`${donor.first_name || ""} ${donor.last_name || ""}`.trim())}</td>
                <td style="padding:8px;">${escapeHtml(donor.blood_type)}</td>
                <td style="padding:8px;">${escapeHtml(donor.city)}</td>
                <td style="padding:8px;">${escapeHtml(donor.email)}</td>
            `;
            donorsTableBody.appendChild(row);
        });
    }

    async function loadUsers() {
        reloadUsersBtn.disabled = true;
        try {
            const response = await authFetch(getApiUrl("users"));
            const result = await response.json();
            if (!response.ok || result.status !== "success") {
                throw new Error(result.message || "Неуспешно зареждане на потребители.");
            }
            renderUsers(result.data);
            setStatus("Списъкът с потребители е обновен.");
        } catch (error) {
            renderUsers([]);
            setStatus(error.message || "Грешка при зареждане на потребители.", true);
        } finally {
            reloadUsersBtn.disabled = false;
        }
    }

    async function loadDonors() {
        reloadDonorsBtn.disabled = true;
        try {
            const response = await authFetch(getApiUrl("donors"));
            const result = await response.json();
            if (!response.ok || result.status !== "success") {
                throw new Error(result.message || "Неуспешно зареждане на донори.");
            }
            renderDonors(result.data);
            setStatus("Списъкът с донори е обновен.");
        } catch (error) {
            renderDonors([]);
            setStatus(error.message || "Грешка при зареждане на донори.", true);
        } finally {
            reloadDonorsBtn.disabled = false;
        }
    }

    reloadUsersBtn.addEventListener("click", loadUsers);
    reloadDonorsBtn.addEventListener("click", loadDonors);

    loadUsers();
    loadDonors();
});
