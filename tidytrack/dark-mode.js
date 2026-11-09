<script>
document.addEventListener('DOMContentLoaded', () => {
    const html = document.documentElement;

    // Apply dark mode if enabled in localStorage
    if(localStorage.getItem('darkMode') === 'enabled'){
        html.classList.add('dark-mode');
    }

    // Toggle button only exists on index page
    const toggle = document.getElementById('darkModeToggle');
    if(toggle){
        // Set button text based on current mode
        toggle.textContent = html.classList.contains('dark-mode') ? "☀️ Light Mode" : "🌙 Dark Mode";

        toggle.addEventListener('click', () => {
            if(html.classList.contains('dark-mode')){
                html.classList.remove('dark-mode');
                localStorage.setItem('darkMode', 'disabled');
                toggle.textContent = "🌙 Dark Mode";
            } else {
                html.classList.add('dark-mode');
                localStorage.setItem('darkMode', 'enabled');
                toggle.textContent = "☀️ Light Mode";
            }
        });
    }
});
</script>
