document.addEventListener('DOMContentLoaded', () => {
    const addTimeBtn = document.querySelector('.add-time-btn');
    const timeContainer = document.querySelector('.time-container');

    if (addTimeBtn && timeContainer) {
        // console.log('PillPalNow: Dynamic Time Script Loaded');
        addTimeBtn.addEventListener('click', (e) => {
            e.preventDefault();

            // Clone the first item
            const items = timeContainer.querySelectorAll('.time-item');
            if (items.length > 0) {
                const clone = items[0].cloneNode(true);

                // Reset values
                const inputs = clone.querySelectorAll('input');
                inputs.forEach(input => {
                    if (input.type === 'number') {
                        input.value = '1'; // Default dosage
                    } else if (input.type === 'time') {
                        input.value = ''; // Reset time
                    } else {
                        input.value = '';
                    }
                });

                timeContainer.appendChild(clone);
            }
        });
    }
});
