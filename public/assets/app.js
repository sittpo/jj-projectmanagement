(() => {
    const root = document.documentElement;
    const system = window.matchMedia('(prefers-color-scheme: dark)');
    let preference;
    try { preference = localStorage.getItem('jj-theme'); } catch {}
    const apply = (theme) => {
        root.dataset.theme = theme;
        document.querySelectorAll('.theme-toggle').forEach(button => {
            button.setAttribute('aria-label', theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
            button.title = button.getAttribute('aria-label');
        });
    };
    apply(preference === 'dark' || preference === 'light' ? preference : system.matches ? 'dark' : 'light');
    system.addEventListener('change', event => { if (!preference) apply(event.matches ? 'dark' : 'light'); });
    document.querySelectorAll('.theme-toggle').forEach(button => button.addEventListener('click', () => {
        preference = root.dataset.theme === 'dark' ? 'light' : 'dark';
        apply(preference);
        try { localStorage.setItem('jj-theme', preference); } catch {}
    }));
    const menu = document.querySelector('.menu-toggle');
    const close = () => { document.body.classList.remove('nav-open'); menu?.setAttribute('aria-expanded', 'false'); };
    menu?.addEventListener('click', () => {
        const open = document.body.classList.toggle('nav-open');
        menu.setAttribute('aria-expanded', String(open));
    });
    document.querySelector('.nav-overlay')?.addEventListener('click', close);
    document.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
    document.querySelector('#user-filter')?.addEventListener('input', event => {
        const query = event.target.value.trim().toLowerCase();
        let count = 0;
        document.querySelectorAll('[data-user-row]').forEach(row => {
            row.hidden = !row.textContent.toLowerCase().includes(query);
            if (!row.hidden) count++;
        });
        document.querySelector('#no-users').hidden = count > 0;
    });
})();
