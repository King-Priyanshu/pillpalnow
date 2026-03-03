/**
 * Refill Actions JavaScript
 * Handles refill request and snooze functionality
 */

// Request Refill
function requestRefill(medicationId) {
    const quantity = prompt('Enter refill quantity:');
    if (!quantity || isNaN(quantity) || parseInt(quantity) <= 0) {
        alert('Please enter a valid quantity');
        return;
    }

    const notes = prompt('Enter any notes (optional):');

    PillPalNowAPI.post('/refill/request', {
        medication_id: medicationId,
        quantity: parseInt(quantity),
        notes: notes || ''
    })
    .then(data => {
        if (data.success) {
            alert('Refill request submitted successfully!');
            // Refresh the page to update the data
            window.location.reload();
        } else {
            alert('Error: ' + (data.message || 'Failed to submit refill request'));
        }
    })
    .catch(error => {
        console.error('Refill request error:', error);
        alert('Failed to submit refill request. Please try again.');
    });
}

// Snooze Refill
function snoozeRefill(medicationId) {
    const duration = prompt('Enter snooze duration in hours (default: 24):');
    const snoozeDuration = duration && !isNaN(duration) && parseInt(duration) > 0 ? parseInt(duration) : 24;

    PillPalNowAPI.post('/refill/snooze', {
        medication_id: medicationId,
        snooze_duration: snoozeDuration
    })
    .then(data => {
        if (data.success) {
            alert('Refill alert snoozed for ' + snoozeDuration + ' hours');
            // Refresh the page to update the data
            window.location.reload();
        } else {
            alert('Error: ' + (data.message || 'Failed to snooze refill alert'));
        }
    })
    .catch(error => {
        console.error('Snooze error:', error);
        alert('Failed to snooze refill alert. Please try again.');
    });
}

// Confirm Refill (for use when confirming a refill request)
function confirmRefill(medicationId) {
    const quantity = prompt('Enter refill quantity:');
    if (!quantity || isNaN(quantity) || parseInt(quantity) <= 0) {
        alert('Please enter a valid quantity');
        return;
    }

    PillPalNowAPI.post('/refill/confirm', {
        medication_id: medicationId,
        refill_quantity: parseInt(quantity)
    })
    .then(data => {
        if (data.success) {
            alert('Refill confirmed successfully!');
            // Refresh the page to update the data
            window.location.reload();
        } else {
            alert('Error: ' + (data.message || 'Failed to confirm refill'));
        }
    })
    .catch(error => {
        console.error('Confirm refill error:', error);
        alert('Failed to confirm refill. Please try again.');
    });
}
