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
 * Dialog menu produk pada halaman lokasi.
 *
 * Dibangun di atas <dialog> asli. showModal() sudah menangani focus trap,
 * Escape, latar inert, dan pengembalian fokus ke elemen pemicu -- semuanya
 * dijamin browser, bukan ditiru dengan JavaScript yang mudah salah.
 *
 * Yang tersisa di sini hanya dua hal yang memang TIDAK ditangani <dialog>:
 *
 *   1. Klik overlay. Klik pada backdrop menargetkan elemen <dialog> itu
 *      sendiri, sehingga cukup dibandingkan dengan event.target. Isi dialog
 *      dibungkus elemen lain, jadi klik di dalamnya tidak pernah lolos.
 *   2. Kunci scroll halaman. <dialog> membuat latar inert tetapi tidak
 *      menghentikan scroll body, yang pada mobile membuat halaman ikut
 *      bergeser di belakang bottom sheet.
 *
 * Tanpa JavaScript, tombolnya tetap tidak menyesatkan: ia menaut ke id
 * dialognya lewat aria-controls dan tidak menjanjikan apa pun yang gagal.
 */
const menuModal = document.querySelector('[data-menu-modal]');

if (menuModal && typeof menuModal.showModal === 'function') {
    const openers = document.querySelectorAll('[data-menu-modal-open]');
    const body = document.body;

    const lockScroll = (locked) => {
        body.style.overflow = locked ? 'hidden' : '';
    };

    openers.forEach((opener) => {
        opener.addEventListener('click', () => {
            menuModal.showModal();
            lockScroll(true);
        });
    });

    menuModal.querySelectorAll('[data-menu-modal-close]').forEach((closer) => {
        closer.addEventListener('click', () => menuModal.close());
    });

    // Klik pada backdrop menargetkan <dialog> itu sendiri.
    menuModal.addEventListener('click', (event) => {
        if (event.target === menuModal) {
            menuModal.close();
        }
    });

    // Menutup lewat tombol, overlay, maupun Escape sama-sama berakhir di sini.
    menuModal.addEventListener('close', () => lockScroll(false));
}
