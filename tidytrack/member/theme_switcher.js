// js/theme_switcher.js
document.addEventListener('DOMContentLoaded', () => {
    const modeToggleBtn = document.getElementById('modeToggle');
    const body = document.body;

    // Function to apply the theme
    function applyTheme(theme) {
        if (theme === 'dark') {
            body.classList.add('dark-mode');
            if (modeToggleBtn) {
                modeToggleBtn.classList.remove('fa-moon');
                modeToggleBtn.classList.add('fa-sun');
            }
        } else {
            body.classList.remove('dark-mode');
            if (modeToggleBtn) {
                modeToggleBtn.classList.remove('fa-sun');
                modeToggleBtn.classList.add('fa-moon');
            }
        }
    }

    // Check for saved mode preference
    const savedMode = localStorage.getItem('mode') || 'light'; // Default to light
    applyTheme(savedMode);

    // Add event listener for the toggle button
    if (modeToggleBtn) {
        modeToggleBtn.addEventListener('click', () => {
            const currentTheme = body.classList.contains('dark-mode') ? 'dark' : 'light';
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            localStorage.setItem('mode', newTheme);
            applyTheme(newTheme);
        });
    }
});