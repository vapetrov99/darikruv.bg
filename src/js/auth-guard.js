/**
 * Client-side session helpers for the DariKruv MVP.
 *
 * Real bearer token is persisted in localStorage under "token".
 * User fields live in localStorage under "user" / "currentUser" (duplicated for legacy pages).
 *
 * requireAuth / isLoggedIn gate pages that need a logged-in user; initAuthUI updates nav visibility.
 */

function isUuidV4(value) {
    return /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(
        String(value || "").trim()
    );
}

function normalizeSessionUser(user) {
    if (!user || typeof user !== "object") {
        return null;
    }

    const normalized = { ...user };
    const publicId = String(user.public_id || "").trim().toLowerCase();
    normalized.public_id = isUuidV4(publicId) ? publicId : null;

    const rawId = Number(user.id);
    normalized.id = Number.isInteger(rawId) && rawId > 0 ? rawId : null;

    return normalized;
}

function getCurrentUser() {
    const rawUser = localStorage.getItem("user") || localStorage.getItem("currentUser");
    if (!rawUser) {
        return null;
    }

    try {
        return normalizeSessionUser(JSON.parse(rawUser));
    } catch (_error) {
        return null;
    }
}

function getCurrentUserPublicId(user = null) {
    const resolved = normalizeSessionUser(user) || getCurrentUser();
    const publicId = String(resolved?.public_id || "").trim().toLowerCase();
    return isUuidV4(publicId) ? publicId : null;
}

function getCurrentUserInternalId(user = null) {
    const resolved = normalizeSessionUser(user) || getCurrentUser();
    const id = Number(resolved?.id);
    return Number.isInteger(id) && id > 0 ? id : null;
}

function hasUserIdentity(user = null) {
    return Boolean(getCurrentUserPublicId(user) || getCurrentUserInternalId(user));
}

function isLoggedIn() {
    return Boolean(getAuthToken()) && Boolean(getCurrentUser());
}

function saveLoginSession(user, token = "") {
    const normalizedUser = normalizeSessionUser(user) || {};
    localStorage.setItem("token", token);
    localStorage.setItem("auth_token", token);
    localStorage.setItem("authToken", token);
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

function getAuthToken() {
    return (
        localStorage.getItem("token") ||
        localStorage.getItem("auth_token") ||
        localStorage.getItem("authToken") ||
        ""
    );
}

function getAuthHeaders(extraHeaders = {}) {
    const headers = { ...extraHeaders };
    const token = getAuthToken();
    if (token) {
        headers.Authorization = `Bearer ${token}`;
    }
    return headers;
}

async function authFetch(url, options = {}) {
    const preparedOptions = { ...options };
    preparedOptions.headers = getAuthHeaders(options.headers || {});

    const response = await fetch(url, preparedOptions);
    if (response.status === 401) {
        clearLoginSession();
    }
    return response;
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

function isAdminUser(user = null) {
    const resolvedUser = user || getCurrentUser();
    return Boolean(resolvedUser) && String(resolvedUser.role || "").toLowerCase() === "admin";
}

function setVisibilityByAuthState(isAuth) {
    document.querySelectorAll(".guest-only").forEach((el) => {
        el.style.display = isAuth ? "none" : "";
    });
    document.querySelectorAll(".auth-only").forEach((el) => {
        el.style.display = isAuth ? "" : "none";
    });
}

function ensureAdminNavLink(nav, user) {
    const existingAdminLink = nav.querySelector("#adminNavLink");
    if (isAdminUser(user)) {
        if (existingAdminLink) {
            existingAdminLink.style.display = "";
            return;
        }

        const adminLink = document.createElement("a");
        adminLink.id = "adminNavLink";
        adminLink.className = "auth-only";
        adminLink.href = "admin.html";
        adminLink.textContent = "Админ";

        const profileLink = nav.querySelector('a[href="profile.html"]');
        if (profileLink && profileLink.parentNode === nav) {
            nav.insertBefore(adminLink, profileLink);
            return;
        }

        nav.appendChild(adminLink);
        return;
    }

    if (existingAdminLink) {
        existingAdminLink.remove();
    }
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
        ensureAdminNavLink(nav, null);
        return;
    }

    ensureAdminNavLink(nav, user);

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

function requireAdmin(redirectTo = "welcome.html") {
    if (!requireAuth("login.html")) {
        return false;
    }

    if (!isAdminUser()) {
        window.location.href = redirectTo;
        return false;
    }

    return true;
}

function initMobileNav() {
    const container = document.querySelector(".nav-container");
    const nav = container ? container.querySelector(".nav") : null;
    if (!container || !nav || container.querySelector(".nav-toggle")) {
        return;
    }

    const toggle = document.createElement("button");
    toggle.type = "button";
    toggle.className = "nav-toggle";
    toggle.setAttribute("aria-label", "Menu");
    toggle.setAttribute("aria-expanded", "false");
    toggle.setAttribute("aria-controls", "site-nav");
    if (!nav.id) {
        nav.id = "site-nav";
    }
    toggle.innerHTML = '<span class="nav-toggle-bars" aria-hidden="true"></span>';

    const logo = container.querySelector(".logo");
    if (logo && logo.nextSibling) {
        container.insertBefore(toggle, logo.nextSibling);
    } else {
        container.appendChild(toggle);
    }

    const setOpen = (open) => {
        nav.classList.toggle("is-open", open);
        toggle.setAttribute("aria-expanded", open ? "true" : "false");
        toggle.setAttribute("aria-label", open ? "Close menu" : "Menu");
    };

    toggle.addEventListener("click", () => {
        setOpen(!nav.classList.contains("is-open"));
    });

    nav.querySelectorAll("a").forEach((link) => {
        link.addEventListener("click", () => setOpen(false));
    });

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape") {
            setOpen(false);
        }
    });
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initMobileNav);
} else {
    initMobileNav();
}
