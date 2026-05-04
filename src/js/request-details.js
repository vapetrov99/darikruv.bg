document.addEventListener("DOMContentLoaded", () => {
    const urlParams = new URLSearchParams(window.location.search);
    const requestId = Number(urlParams.get("id"));

    let comments = [
        {
            request_id: 1,
            name: "Петър",
            text: "Мога да даря утре сутрин. Ще се свържа по телефона.",
            created_at: "04.05.2026 12:30"
        },
        {
            request_id: 1,
            name: "Анна",
            text: "Има ли значение в кой кръвен център да се дари?",
            created_at: "04.05.2026 13:10"
        }
    ];

    const commentForm = document.getElementById("commentForm");
    let currentRequestId = null;

    function showNotFound(message = "Заявката не е намерена") {
        document.querySelector(".details-card").innerHTML = `
            <h2>${message}</h2>
            <p>Върни се към списъка със заявки.</p>
        `;
    }

    async function loadRequest() {
        if (!Number.isInteger(requestId) || requestId < 1) {
            showNotFound("Невалиден номер на заявка");
            return;
        }

        try {
            const response = await fetch(`../api/index.php?route=request_details&id=${requestId}`);
            const result = await response.json();

            if (!response.ok || result.status !== "success" || !result.data) {
                throw new Error(result.message || "Заявката не е намерена");
            }

            const request = result.data;
            currentRequestId = Number(request.id);
            renderRequest(request);
            renderComments(currentRequestId);
        } catch (error) {
            showNotFound(error.message || "Заявката не е намерена");
        }
    }

    commentForm.addEventListener("submit", (event) => {
        event.preventDefault();

        if (!currentRequestId) {
            return;
        }

        const name = document.getElementById("commentName").value.trim();
        const text = document.getElementById("commentText").value.trim();

        if (!name || !text) {
            return;
        }

        const newComment = {
            request_id: currentRequestId,
            name,
            text,
            created_at: new Date().toLocaleString("bg-BG")
        };

        comments.unshift(newComment);
        renderComments(currentRequestId);
        commentForm.reset();

        console.log("Нов коментар:", newComment);
    });

    function renderRequest(request) {
        document.getElementById("patientName").textContent = request.patient_name;
        document.getElementById("createdAt").textContent = `Публикувана на: ${request.created_at}`;
        document.getElementById("bloodType").textContent = request.blood_type;
        document.getElementById("city").textContent = request.city;
        document.getElementById("hospital").textContent = request.hospital;
        document.getElementById("contactName").textContent = request.contact_name;
        document.getElementById("contactPhone").textContent = request.contact_phone;
        document.getElementById("description").textContent = request.description || "Няма допълнително описание.";

        document.getElementById("callButton").href = `tel:${request.contact_phone}`;

        const required = request.required_units_count;
        const fulfilled = request.fulfilled_units_count;
        const progressPercent = Math.min((fulfilled / required) * 100, 100);

        document.getElementById("progressText").textContent = `${fulfilled} / ${required} банки`;
        document.getElementById("progressFill").style.width = `${progressPercent}%`;
    }

    function renderComments(requestId) {
        const commentsList = document.getElementById("commentsList");
        const noCommentsMessage = document.getElementById("noCommentsMessage");

        commentsList.innerHTML = "";

        const requestComments = comments.filter(comment => comment.request_id === requestId);

        if (requestComments.length === 0) {
            noCommentsMessage.style.display = "block";
            return;
        }

        noCommentsMessage.style.display = "none";

        requestComments.forEach(comment => {
            const commentCard = document.createElement("div");
            commentCard.className = "comment-card";

            commentCard.innerHTML = `
                <div class="comment-card-header">
                    <strong>${comment.name}</strong>
                    <span>${comment.created_at}</span>
                </div>
                <p>${comment.text}</p>
            `;

            commentsList.appendChild(commentCard);
        });
    }

    loadRequest();
});