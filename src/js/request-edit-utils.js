/**
 * Blood request edit: owner only, active status, within 72 hours of created_at.
 */
const REQUEST_EDIT_WINDOW_MS = 72 * 60 * 60 * 1000;

function isRequestOwner(user, request) {
    if (!request || typeof hasUserIdentity !== "function" || !hasUserIdentity(user)) {
        return false;
    }

    const userPublicId = typeof getCurrentUserPublicId === "function"
        ? getCurrentUserPublicId(user)
        : null;
    const ownerPublicId = String(request.created_by_public_id || "").trim().toLowerCase();
    if (userPublicId && ownerPublicId && userPublicId === ownerPublicId) {
        return true;
    }

    if (request.created_by === null || request.created_by === undefined) {
        return false;
    }

    const userInternalId = typeof getCurrentUserInternalId === "function"
        ? getCurrentUserInternalId(user)
        : null;
    if (!userInternalId) {
        return false;
    }

    return Number(request.created_by) === Number(userInternalId);
}

function canEditBloodRequest(request) {
    if (!request || request.status !== "active") {
        return false;
    }

    if (!request.created_at) {
        return false;
    }

    const createdAt = new Date(request.created_at).getTime();
    if (Number.isNaN(createdAt)) {
        return false;
    }

    return Date.now() - createdAt <= REQUEST_EDIT_WINDOW_MS;
}

function canShowEditRequestButton(user, request) {
    return isRequestOwner(user, request) && canEditBloodRequest(request);
}
