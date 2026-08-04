/**
 * Single request view: loads request_details + request_comments, submits create_request_comment.
 */

document.addEventListener("DOMContentLoaded", () => {
    if (!requireAuth()) {
        return;
    }
    initAuthUI();

    const urlParams = new URLSearchParams(window.location.search);
    const requestId = Number(urlParams.get("id"));
    const currentUser = getCurrentUser();
    const commentAuthorName = getDisplayName(currentUser);
    const isCurrentUserDonor = currentUser?.role === "donor";
    const commentAuthorPhone = currentUser?.phone || "";
    let comments = [];

    function buildTelHref(phone) {
        const digitsOnly = String(phone || "").replace(/\D/g, "");
        return digitsOnly ? `tel:${digitsOnly}` : "";
    }

    const commentForm = document.getElementById("commentForm");
    const commentAuthorPreview = document.getElementById("commentAuthorPreview");
    let currentRequestId = null;
    let currentRequest = null;

    if (commentAuthorPreview) {
        commentAuthorPreview.textContent = `Публикуваш като: ${commentAuthorName}`;
    }

    function showNotFound(message = "Заявката не е намерена") {
        const detailsCard = document.querySelector(".details-card");
        if (!detailsCard) {
            return;
        }

        detailsCard.textContent = "";
        const title = document.createElement("h2");
        title.textContent = String(message || "Заявката не е намерена");
        const subtitle = document.createElement("p");
        subtitle.textContent = "Върни се към списъка със заявки.";
        detailsCard.appendChild(title);
        detailsCard.appendChild(subtitle);
    }

    async function loadRequest() {
        if (!Number.isInteger(requestId) || requestId < 1) {
            showNotFound("Невалиден номер на заявка");
            return;
        }

        try {
            const response = await authFetch(getApiUrl("request_details", {
                id: requestId
            }));
            const result = await response.json();

            if (!response.ok || result.status !== "success" || !result.data) {
                throw new Error(result.message || "Заявката не е намерена");
            }

            const request = result.data;
            currentRequestId = Number(request.id);
            currentRequest = request;
            renderRequest(request);
            setupEditButton(request);
            setupRespondButton(request);
            await loadComments(currentRequestId);
        } catch (error) {
            showNotFound(error.message || "Заявката не е намерена");
        }
    }

    commentForm?.addEventListener("submit", async (event) => {
        event.preventDefault();

        if (!currentRequestId) {
            return;
        }

        const text = document.getElementById("commentText").value.trim();

        if (!text) {
            return;
        }

        try {
            const response = await fetch(getApiUrl("create_request_comment"), {
                method: "POST",
                headers: {
                    "Content-Type": "application/json"
                },
                body: JSON.stringify({
                    request_id: currentRequestId,
                    name: commentAuthorName,
                    is_donor: isCurrentUserDonor,
                    contact_phone: isCurrentUserDonor ? commentAuthorPhone : "",
                    text
                })
            });
            const result = await response.json();

            if (!response.ok || result.status !== "success") {
                throw new Error(result.message || "Коментарът не беше записан.");
            }

            await loadComments(currentRequestId);
            commentForm.reset();
        } catch (error) {
            alert(error.message || "Възникна грешка при запис на коментар.");
        }
    });

    async function loadComments(targetRequestId) {
        try {
            const response = await fetch(getApiUrl("request_comments", { request_id: targetRequestId }));
            const result = await response.json();

            if (!response.ok || result.status !== "success") {
                throw new Error(result.message || "Коментарите не могат да бъдат заредени.");
            }

            comments = Array.isArray(result.data) ? result.data : [];
            renderComments(targetRequestId);
        } catch (_error) {
            comments = [];
            renderComments(targetRequestId);
        }
    }

    function setupRespondButton(request) {
        const respondBtn = document.getElementById("respondBtn");
        if (!respondBtn) {
            return;
        }

        const showRespond = typeof canShowRespondButton === "function"
            && canShowRespondButton(currentUser, request);
        const config = showRespond && typeof getRespondButtonConfig === "function"
            ? getRespondButtonConfig(request)
            : { hidden: true };

        if (config.hidden) {
            respondBtn.hidden = true;
        } else {
            respondBtn.hidden = false;
            respondBtn.textContent = config.text;
            respondBtn.dataset.action = config.action;
            respondBtn.disabled = Boolean(config.disabled);
        }

        respondBtn.onclick = async (event) => {
            event.preventDefault();
            event.stopPropagation();

            if (!currentRequestId || !respondBtn.dataset.action) {
                return;
            }

            const originalText = respondBtn.textContent;
            respondBtn.disabled = true;
            respondBtn.textContent = "Изпращане...";

            try {
                const data = await submitRequestResponse(
                    currentRequestId,
                    respondBtn.dataset.action
                );

                if (data?.request?.status === "fulfilled") {
                    alert("Заявката е изпълнена. Благодарим за помощта!");
                    window.location.href = "request.html";
                    return;
                }

                await loadRequest();
            } catch (error) {
                alert(error.message || "Възникна грешка.");
                respondBtn.disabled = false;
                respondBtn.textContent = originalText;
            }
        };
    }

    function setupEditButton(request) {
        const editBtn = document.getElementById("editRequestBtn");
        if (!editBtn || !currentUser) {
            return;
        }

        const canEdit = typeof canShowEditRequestButton === "function"
            && canShowEditRequestButton(currentUser, request);

        if (canEdit) {
            editBtn.href = `create-request.html?id=${encodeURIComponent(String(request.id ?? ""))}`;
            editBtn.hidden = false;
        } else {
            editBtn.hidden = true;
        }
    }

    function renderRequest(request) {
        document.getElementById("patientName").textContent = request.patient_name;
        document.getElementById("createdAt").textContent = `Публикувана на: ${request.created_at}`;
        document.getElementById("bloodType").textContent = request.blood_type;
        document.getElementById("city").textContent = request.city;
        document.getElementById("hospital").textContent = request.hospital;
        document.getElementById("contactName").textContent = request.contact_name;
        document.getElementById("contactPhone").textContent = request.contact_phone;
        document.getElementById("description").textContent = request.description || "Няма допълнително описание.";

        const callButton = document.getElementById("callButton");
        const telHref = buildTelHref(request.contact_phone);
        if (callButton) {
            if (telHref) {
                callButton.href = telHref;
                callButton.removeAttribute("aria-disabled");
            } else {
                callButton.removeAttribute("href");
                callButton.setAttribute("aria-disabled", "true");
            }
        }

        const required = request.required_units_count;
        const fulfilled = request.fulfilled_units_count;
        const progressPercent = Math.min((fulfilled / required) * 100, 100);

        document.getElementById("progressText").textContent = `${fulfilled} / ${required} банки`;
        document.getElementById("progressFill").style.width = `${progressPercent}%`;

        if (typeof updateRequestStatusBox === "function") {
            updateRequestStatusBox(document.getElementById("requestStatusBox"), request.status);
        }
    }

    function renderComments(requestId) {
        const commentsList = document.getElementById("commentsList");
        const noCommentsMessage = document.getElementById("noCommentsMessage");

        commentsList.innerHTML = "";

        const requestComments = comments.filter(comment => Number(comment.request_id) === Number(requestId));

        if (requestComments.length === 0) {
            noCommentsMessage.style.display = "block";
            return;
        }

        noCommentsMessage.style.display = "none";

        requestComments.forEach(comment => {
            const commentCard = document.createElement("div");
            commentCard.className = "comment-card";
            if (Number(comment.is_donor) === 1) {
                commentCard.classList.add("comment-card-donor");
            }

            const telHref = buildTelHref(comment.contact_phone);
            const header = document.createElement("div");
            header.className = "comment-card-header";

            const name = document.createElement("strong");
            name.textContent = String(comment.name || "");
            header.appendChild(name);

            if (Number(comment.is_donor) === 1) {
                const donorBadge = document.createElement("span");
                donorBadge.className = "donor-badge";
                donorBadge.textContent = "Донор";
                header.appendChild(donorBadge);
            }

            const createdAt = document.createElement("span");
            createdAt.textContent = String(comment.created_at || "");
            header.appendChild(createdAt);

            const text = document.createElement("p");
            text.textContent = String(comment.text || "");

            commentCard.appendChild(header);
            commentCard.appendChild(text);

            if (Number(comment.is_donor) === 1 && telHref) {
                const donorPhone = document.createElement("a");
                donorPhone.className = "comment-phone";
                donorPhone.href = telHref;
                donorPhone.textContent = `Телефон: ${String(comment.contact_phone || "")}`;
                commentCard.appendChild(donorPhone);
            }

            commentsList.appendChild(commentCard);
        });
    }

    loadRequest();
});