/**
 * WPCalibrate RSS Importer - Admin Interactions
 * Native Vanilla JavaScript without heavy framework dependencies.
 */
document.addEventListener('DOMContentLoaded', function () {
    // Confirmation dialogs on destructive actions
    const deleteButtons = document.querySelectorAll('.wpcri-confirm-action');
    deleteButtons.forEach(function (button) {
        button.addEventListener('click', function (e) {
            const message = button.getAttribute('data-confirm') || 'Are you sure you want to perform this action?';
            if (!window.confirm(message)) {
                e.preventDefault();
            }
        });
    });

    // Dismissible notice helpers
    const notices = document.querySelectorAll('#wpcri-wrap .notice.is-dismissible');
    notices.forEach(function (notice) {
        const dismissBtn = notice.querySelector('.notice-dismiss');
        if (dismissBtn) {
            dismissBtn.addEventListener('click', function () {
                notice.style.display = 'none';
            });
        }
    });
});
