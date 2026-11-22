document.addEventListener('DOMContentLoaded', function () {
    const entry = document.querySelector('.hpt_wp_entry_content');
    if (!entry) return;

    // Ensure wide tables inside WordPress content remain horizontally scrollable on mobile
    entry.querySelectorAll('table').forEach(function (tbl) {
        if (!tbl.parentElement || !tbl.parentElement.classList.contains('hpt_table_responsive')) {
            const wrapper = document.createElement('div');
            wrapper.className = 'hpt_table_responsive';
            tbl.parentNode.insertBefore(wrapper, tbl);
            wrapper.appendChild(tbl);
        }
    });
});
