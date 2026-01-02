document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('waitlist-form');
    if (!form) return;

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = form.querySelector('button[type="submit"]');
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Submitting...';
        }
        
        const formData = new FormData(form);
        const data = Object.fromEntries(formData.entries());
        
        // Convert numeric strings to numbers
        if (data.adults) data.adults = parseInt(data.adults);
        if (data.kids) data.kids = parseInt(data.kids);
        if (data.intended_days) data.intended_days = parseInt(data.intended_days); // Parse as int for scoring

        try {
            const response = await fetch('/campo/api/waitlist', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });

            if (response.ok) {
                const result = await response.json();
                if (result.success) {
                    document.getElementById('form-content').style.display = 'none';
                    document.getElementById('success-message').style.display = 'block';
                } else {
                    throw new Error(result.message || 'Submission failed');
                }
            } else {
                throw new Error('Server error: ' + response.statusText);
            }
        } catch (err) {
            alert('Error submitting form: ' + err.message);
            if (btn) {
                btn.disabled = false;
                btn.textContent = 'Submit Application';
            }
        }
    });
});