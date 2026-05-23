/**
 * Interactive blood type compatibility animation.
 * Adapted from https://codepen.io/RominaMartin/pen/OJVdvRm by Romina Martin.
 */

const BLOOD_TYPE_ORDER = ["O−", "O+", "A−", "A+", "B−", "B+", "AB−", "AB+"];

const BLOOD_COMPATIBILITY = {
    "O−": ["O−", "O+", "A−", "A+", "B−", "B+", "AB−", "AB+"],
    "O+": ["O+", "A+", "B+", "AB+"],
    "A−": ["A−", "A+", "AB−", "AB+"],
    "A+": ["A+", "AB+"],
    "B−": ["B−", "B+", "AB−", "AB+"],
    "B+": ["B+", "AB+"],
    "AB−": ["AB−", "AB+"],
    "AB+": ["AB+"]
};

function createElement(tag, className, text) {
    const el = document.createElement(tag);
    if (className) {
        el.className = className;
    }
    if (text !== undefined) {
        el.textContent = text;
    }
    return el;
}

function buildHuman(type, side) {
    const human = createElement("div", `human ${side}`);
    const scribble = createElement("div", "scribble");
    const label = createElement("span", "blood_type", type);
    scribble.appendChild(label);
    scribble.appendChild(createElement("div", "head"));
    scribble.appendChild(createElement("div", "body"));
    human.appendChild(scribble);
    human.appendChild(createElement("div", "via"));
    human.appendChild(createElement("div", "blood_via"));
    return human;
}

function buildBloodCompatWidget(root) {
    root.innerHTML = "";

    const selector = createElement("div", "blood-selector");
    selector.id = "blood_selector";
    selector.setAttribute("role", "group");
    selector.setAttribute("aria-label", "Кръвна група на дарителя");

    BLOOD_TYPE_ORDER.forEach((type) => {
        const option = createElement("div", "", type);
        option.tabIndex = 0;
        option.setAttribute("role", "button");
        selector.appendChild(option);
    });

    const bloodContent = createElement("div", "blood-content");
    bloodContent.id = "blood_content";
    bloodContent.appendChild(createElement("div", "blood-bar"));
    const mainBag = createElement("div", "main_bag");
    mainBag.appendChild(createElement("div", "blood"));
    bloodContent.appendChild(mainBag);

    const centerWrap = createElement("div", "center-via-c");
    centerWrap.id = "center_via_c";
    const centerVia = createElement("div", "center_via");
    centerVia.appendChild(createElement("div", "blood_via"));
    centerWrap.appendChild(centerVia);

    const humans = createElement("div", "blood-humans");
    humans.id = "humans";
    BLOOD_TYPE_ORDER.forEach((type, index) => {
        humans.appendChild(buildHuman(type, index % 2 === 0 ? "left" : "right"));
    });

    root.appendChild(selector);
    root.appendChild(bloodContent);
    root.appendChild(centerWrap);
    root.appendChild(humans);

    return {
        selector,
        bloodVias: humans.querySelectorAll(".blood_via"),
        bloodBag: mainBag.querySelector(".blood"),
        centerVia: centerVia.querySelector(".blood_via"),
        bloodTypes: humans.querySelectorAll(".blood_type")
    };
}

function delay(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

function initBloodCompatAnimation() {
    const root = document.getElementById("blood_compat");
    if (!root || root.dataset.initialized === "true") {
        return;
    }

    const parts = buildBloodCompatWidget(root);
    root.dataset.initialized = "true";

    let lastSelection = null;

    function resetFlow() {
        if (lastSelection) {
            lastSelection.classList.remove("highlight");
            lastSelection = null;
        }

        parts.bloodBag.style.height = "100px";
        parts.centerVia.style.height = "0px";

        parts.bloodVias.forEach((via) => {
            via.style.width = "0px";
        });
        parts.bloodTypes.forEach((label) => {
            label.classList.remove("highlightText");
        });
    }

    async function showRecipients(donorType, target) {
        resetFlow();
        target.classList.add("highlight");
        lastSelection = target;

        const recipients = BLOOD_COMPATIBILITY[donorType] || [];

        for (const recipientType of recipients) {
            const recipientIndex = BLOOD_TYPE_ORDER.indexOf(recipientType);
            if (recipientIndex < 0) {
                continue;
            }

            const centerHeight = 50 + 50 * Math.floor(recipientIndex / 2);
            const bagHeight = 125 - 25 * Math.floor(recipientIndex / 2);

            parts.bloodBag.style.height = `${bagHeight}px`;
            parts.centerVia.style.height = `${centerHeight}px`;

            await delay(100);

            parts.bloodVias[recipientIndex].style.width = "100%";
            parts.bloodTypes[recipientIndex].classList.add("highlightText");
        }
    }

    function onSelect(event) {
        const target = event.target.closest("#blood_selector > div");
        if (!target || !parts.selector.contains(target)) {
            return;
        }
        showRecipients(target.textContent.trim(), target);
    }

    parts.selector.addEventListener("click", onSelect);
    parts.selector.addEventListener("keydown", (event) => {
        if (event.key === "Enter" || event.key === " ") {
            event.preventDefault();
            onSelect(event);
        }
    });
}

document.addEventListener("DOMContentLoaded", initBloodCompatAnimation);
