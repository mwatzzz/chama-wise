document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.toggle-password').forEach((button) => {
        button.addEventListener('click', () => {
            const input = button.parentElement.querySelector('input');
            if (!input) {
                return;
            }
            const hidden = input.getAttribute('type') === 'password';
            input.setAttribute('type', hidden ? 'text' : 'password');
            button.setAttribute('aria-label', hidden ? 'Hide password' : 'Show password');
        });
    });
});
