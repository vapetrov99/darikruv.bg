document.addEventListener("DOMContentLoaded", () => {
    initMainSections();
    initInnerTabs();
});

function initMainSections() {
    const mainSelect = document.getElementById("faq_main_select");
    const mainSections = document.querySelectorAll(".faq-main-section");
    if (!mainSelect || !mainSections.length) {
        return;
    }

    function showMainSection(sectionId) {
        mainSections.forEach((section) => {
            const isActive = section.id === sectionId;
            section.classList.toggle("active", isActive);
            section.hidden = !isActive;
        });

        if (sectionId === "section-where" && typeof window.loadDonationStores === "function") {
            setTimeout(() => {
                window.loadDonationStores();
                if (typeof window.invalidateDonationMap === "function") {
                    window.invalidateDonationMap();
                }
            }, 150);
        }

        const hash = sectionId.replace("section-", "");
        if (history.replaceState) {
            history.replaceState(null, "", `${location.pathname}#${hash}`);
        }
    }

    mainSelect.addEventListener("change", () => {
        showMainSection(mainSelect.value);
    });

    const hash = location.hash.replace("#", "");
    const hashToSection = {
        why: "section-why",
        donation: "section-donation",
        where: "section-where"
    };

    if (hashToSection[hash]) {
        mainSelect.value = hashToSection[hash];
        showMainSection(hashToSection[hash]);
    } else {
        showMainSection(mainSelect.value);
    }
}

function initInnerTabs() {
    const sectionWhy = document.getElementById("section-why");
    if (!sectionWhy) {
        return;
    }

    const tabs = sectionWhy.querySelectorAll(".faq-tab");
    const panels = sectionWhy.querySelectorAll(".faq-panel");

    if (!tabs.length || !panels.length) {
        return;
    }

    function activateTab(tab) {
        const targetId = tab.getAttribute("data-panel");

        tabs.forEach((item) => {
            item.classList.toggle("active", item === tab);
            item.setAttribute("aria-selected", item === tab ? "true" : "false");
        });

        panels.forEach((panel) => {
            const isActive = panel.id === targetId;
            panel.classList.toggle("active", isActive);
            panel.hidden = !isActive;
        });
    }

    tabs.forEach((tab) => {
        tab.addEventListener("click", () => activateTab(tab));
    });

    activateTab(tabs[0]);
}
