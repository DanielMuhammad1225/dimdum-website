/**
 * Mobile navigation DIMDUM.
 *
 * Vanilla JS seminimal mungkin: toggle panel, sinkronisasi aria-expanded,
 * tutup lewat Escape / klik tautan / kembali ke viewport desktop.
 */
const toggle = document.getElementById('menu-toggle');
const menu = document.getElementById('menu-mobile');

if (toggle && menu) {
    const iconOpen = toggle.querySelector('[data-menu-icon="open"]');
    const iconClose = toggle.querySelector('[data-menu-icon="close"]');
    const label = toggle.querySelector('.sr-only');
    const desktop = window.matchMedia('(min-width: 768px)');

    const setMenu = (open) => {
        menu.classList.toggle('hidden', !open);
        toggle.setAttribute('aria-expanded', String(open));
        iconOpen?.classList.toggle('hidden', open);
        iconClose?.classList.toggle('hidden', !open);

        if (label) {
            label.textContent = open ? 'Tutup menu navigasi' : 'Buka menu navigasi';
        }
    };

    toggle.addEventListener('click', () => {
        setMenu(toggle.getAttribute('aria-expanded') !== 'true');
    });

    menu.addEventListener('click', (event) => {
        if (event.target.closest('a')) {
            setMenu(false);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
            setMenu(false);
            toggle.focus();
        }
    });

    desktop.addEventListener('change', (event) => {
        if (event.matches) {
            setMenu(false);
        }
    });
}
