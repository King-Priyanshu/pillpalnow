/**
 * Dynamic Refill Buttons
 * This script will dynamically add refill buttons to the page-refills.php file
 */

document.addEventListener('DOMContentLoaded', function () {
    // Get all medication cards
    const medicationCards = document.querySelectorAll('.dashboard-card');

    medicationCards.forEach(card => {
        // Get medication ID from card (we need to add this data attribute first)
        // For now, let's try to extract it from the card content
        const medicationId = extractMedicationId(card);

        if (medicationId) {
            // Create action buttons div
            const actionsDiv = document.createElement('div');
            actionsDiv.className = 'flex flex-col gap-2';

            // Add refill needed warning if stock is low
            const stockElement = card.querySelector('.text-xs.font-bold.px-2.py-1.rounded');
            if (stockElement && parseInt(stockElement.textContent) <= 7) {
                const warningP = document.createElement('p');
                warningP.className = 'text-danger text-xs font-semibold';
                warningP.textContent = '⚠️ Refill Needed';
                actionsDiv.appendChild(warningP);
            }

            // Add request refill button
            const requestBtn = document.createElement('button');
            requestBtn.className = 'btn btn-primary btn-sm';
            requestBtn.textContent = 'Request Refill';
            requestBtn.onclick = function () {
                requestRefill(medicationId);
            };
            actionsDiv.appendChild(requestBtn);

            // Add snooze button
            const snoozeBtn = document.createElement('button');
            snoozeBtn.className = 'btn btn-secondary btn-sm';
            snoozeBtn.textContent = 'Snooze (24h)';
            snoozeBtn.onclick = function () {
                snoozeRefill(medicationId);
            };
            actionsDiv.appendChild(snoozeBtn);

            // Add actions div to card
            const cardContent = card.querySelector('.flex.justify-between.items-start.mb-2');
            if (cardContent) {
                cardContent.appendChild(actionsDiv);
            }
        }
    });
});

// Helper function to extract medication ID from card (this may need to be adjusted)
function extractMedicationId(card) {
    // Try to extract medication ID from card
    // For example, if the card has a data-medication-id attribute
    const medicationId = card.getAttribute('data-medication-id');
    if (medicationId) {
        return parseInt(medicationId);
    }

    // Try to extract from the card's content (e.g., from a hidden input)
    const hiddenInput = card.querySelector('input[type="hidden"]');
    if (hiddenInput && hiddenInput.name === 'medication_id') {
        return parseInt(hiddenInput.value);
    }

    // If all else fails, return null
    return null;
}
