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

/**
 * Filter kelompok lokasi pada halaman wilayah.
 *
 * Progressive enhancement: tanpa JavaScript seluruh kartu tetap terlihat
 * karena penyembunyian hanya dilakukan skrip ini. Pilihan disimpan pada
 * query string ringan (?filter=) supaya tautan bisa dibagikan, tetapi
 * canonical halaman dirakit server-side dan tidak pernah ikut berubah.
 */
const filterBar = document.querySelector('[data-location-filter]');
const locationList = document.querySelector('[data-location-list]');

if (filterBar && locationList) {
    const buttons = Array.from(filterBar.querySelectorAll('[data-filter-value]'));
    const cards = Array.from(locationList.querySelectorAll('[data-location-card]'));
    const status = filterBar.querySelector('[data-filter-status]');

    const activeClasses = ['bg-brand-orange'];
    const idleClasses = ['bg-white'];

    const apply = (value, { updateUrl = true } = {}) => {
        let shown = 0;

        cards.forEach((card) => {
            const item = card.closest('li') || card;
            const matches = value === '' || card.dataset.filter === value;

            item.hidden = !matches;

            if (matches) {
                shown += 1;
            }
        });

        buttons.forEach((button) => {
            const isActive = button.dataset.filterValue === value;

            button.setAttribute('aria-pressed', String(isActive));
            button.classList.toggle(...activeClasses, isActive);
            button.classList.toggle(...idleClasses, !isActive);
        });

        if (status) {
            status.textContent =
                shown === cards.length
                    ? `Menampilkan semua ${cards.length} lokasi.`
                    : `Menampilkan ${shown} dari ${cards.length} lokasi.`;
        }

        if (!updateUrl || typeof window.history?.replaceState !== 'function') {
            return;
        }

        const url = new URL(window.location.href);

        if (value === '') {
            url.searchParams.delete('filter');
        } else {
            url.searchParams.set('filter', value);
        }

        window.history.replaceState({}, '', url);
    };

    filterBar.addEventListener('click', (event) => {
        const button = event.target.closest('[data-filter-value]');

        if (button) {
            apply(button.dataset.filterValue);
        }
    });

    // Pulihkan pilihan dari URL, tapi hanya bila nilainya memang ada di daftar.
    const requested = new URL(window.location.href).searchParams.get('filter') || '';
    const known = buttons.some((button) => button.dataset.filterValue === requested);

    apply(known ? requested : '', { updateUrl: false });
}
