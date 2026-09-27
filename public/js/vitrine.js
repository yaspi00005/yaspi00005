/* Site vitrine SOTRAMA : menu mobile. */
(function () {
    'use strict';
    const bouton = document.querySelector('.menu-bouton');
    const menu = document.getElementById('menu');
    if (!bouton || !menu) { return; }
    const basculer = function (ouvert) {
        menu.classList.toggle('ouvert', ouvert);
        bouton.setAttribute('aria-expanded', ouvert ? 'true' : 'false');
        bouton.setAttribute('aria-label', ouvert ? 'Fermer le menu' : 'Ouvrir le menu');
    };
    bouton.addEventListener('click', () => basculer(!menu.classList.contains('ouvert')));
    menu.addEventListener('click', e => { if (e.target.closest('a')) { basculer(false); } });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') { basculer(false); } });
})();
