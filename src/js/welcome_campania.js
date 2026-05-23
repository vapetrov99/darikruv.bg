/**
 * Campaigns page: filters static campaign cards by city; "featured" cards show when no city is selected.
 */

document.addEventListener("DOMContentLoaded", () => {
    const cityFilter = document.getElementById("cityFilter");
    const campaignCards = document.querySelectorAll(".campaign-card");
    const noCampaignsMessage = document.getElementById("noCampaignsMessage");

    function renderCampaigns() {
        const selectedCity = cityFilter.value;
        let visibleCount = 0;

        campaignCards.forEach(card => {
            const cardCity = card.dataset.city;
            const isFeatured = card.dataset.featured === "true";

            if (selectedCity === "") {
                if (isFeatured) {
                    card.style.display = "block";
                    visibleCount++;
                } else {
                    card.style.display = "none";
                }
            } else {
                if (cardCity === selectedCity) {
                    card.style.display = "block";
                    visibleCount++;
                } else {
                    card.style.display = "none";
                }
            }
        });

        noCampaignsMessage.style.display = visibleCount === 0 ? "block" : "none";
    }

    renderCampaigns();
    cityFilter.addEventListener("change", renderCampaigns);
});
