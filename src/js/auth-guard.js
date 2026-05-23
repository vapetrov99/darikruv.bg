/**
 * Client-side session helpers for the DariKruv MVP.
 *
 * The API does not issue JWTs here: "token" in localStorage is a placeholder string ("logged-in").
 * Real user fields live in localStorage under "user" / "currentUser" (duplicated for legacy pages).
 *
 * requireAuth / isLoggedIn gate pages that need a logged-in user; initAuthUI updates nav visibility.
 */

function getCurrentUser() {
    const rawUser = localStorage.getItem("user") || localStorage.getItem("currentUser");
    if (!rawUser) {
        return null;
    }

    try {
        return JSON.parse(rawUser);
    } catch (_error) {
        return null;
    }
}

function isLoggedIn() {
    return Boolean(localStorage.getItem("token")) && Boolean(getCurrentUser());
}

function saveLoginSession(user, token = "session-token") {
    const normalizedUser = user || {};
    localStorage.setItem("token", token);
    localStorage.setItem("user", JSON.stringify(normalizedUser));
    localStorage.setItem("currentUser", JSON.stringify(normalizedUser));
}

const AUTH_STORAGE_KEYS = [
    "token",
    "user",
    "currentUser",
    // Legacy/fallback auth keys from older UI versions.
    "authToken",
    "auth_token",
    "loggedInUser",
    "logged_in_user"
];

function clearLoginSession() {
    AUTH_STORAGE_KEYS.forEach((key) => {
        localStorage.removeItem(key);
        sessionStorage.removeItem(key);
    });
}

function logout(redirectTo = "login.html") {
    clearLoginSession();
    // Replace history entry so Back does not reopen a protected page snapshot.
    window.location.replace(redirectTo);
}

function requireAuth(redirectTo = "auth-register.html") {
    if (!isLoggedIn()) {
        window.location.href = redirectTo;
        return false;
    }
    return true;
}

function getDisplayName(user) {
    if (!user) {
        return "Потребител";
    }
    const fullName = `${user.first_name || ""} ${user.last_name || ""}`.trim();
    return user.name || fullName || user.email || "Потребител";
}

function setVisibilityByAuthState(isAuth) {
    document.querySelectorAll(".guest-only").forEach((el) => {
        el.style.display = isAuth ? "none" : "";
    });
    document.querySelectorAll(".auth-only").forEach((el) => {
        el.style.display = isAuth ? "" : "none";
    });
}

function initAuthUI() {
    const user = getCurrentUser();
    const auth = isLoggedIn();
    setVisibilityByAuthState(auth);

    const nav = document.querySelector(".nav");
    if (!nav) {
        return;
    }

    const existingInfo = document.getElementById("authUserInfo");
    if (existingInfo) {
        existingInfo.remove();
    }

    if (!auth || !user) {
        return;
    }

    const wrapper = document.createElement("span");
    wrapper.id = "authUserInfo";
    wrapper.className = "auth-user-nav";

    const nameSpan = document.createElement("span");
    nameSpan.textContent = `${getDisplayName(user)} | `;

    const logoutLink = document.createElement("a");
    logoutLink.href = "#";
    logoutLink.textContent = "Изход";
    logoutLink.addEventListener("click", (event) => {
        event.preventDefault();
        logout("login.html");
    });

    wrapper.appendChild(nameSpan);
    wrapper.appendChild(logoutLink);
    nav.appendChild(wrapper);
}
