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

/* Bandeau d'information « vie privée » : affiché à la première visite, choix mémorisé 6 mois dans le navigateur. */
(function () {
    'use strict';
    const bandeau = document.getElementById('bandeau-cookies');
    if (!bandeau) { return; }
    const CLE = 'sotrama_choix_cookies';
    const SIX_MOIS = 182 * 24 * 3600 * 1000;
    const lire = function () {
        try {
            const choix = JSON.parse(localStorage.getItem(CLE) || 'null');
            return choix && Date.now() - choix.date < SIX_MOIS ? choix : null;
        } catch (e) { return null; }
    };
    const ouvrir = function () { bandeau.hidden = false; document.body.classList.add('bandeau-cookies-ouvert'); };
    const fermer = function () { bandeau.hidden = true; document.body.classList.remove('bandeau-cookies-ouvert'); };
    bandeau.addEventListener('click', function (e) {
        const bouton = e.target.closest('[data-cookies-choix]');
        if (!bouton) { return; }
        try { localStorage.setItem(CLE, JSON.stringify({ choix: bouton.dataset.cookiesChoix, date: Date.now() })); } catch (err) { /* navigation privée */ }
        fermer();
    });
    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-cookies-ouvrir]')) { e.preventDefault(); ouvrir(); }
    });
    if (!lire()) { ouvrir(); }
})();

/* Compte à rebours du prochain départ (bandeau en haut des pages). */
(function () {
    'use strict';
    const zone = document.querySelector('[data-compte-rebours]');
    if (!zone) { return; }
    const fin = new Date(zone.dataset.compteRebours).getTime();
    if (isNaN(fin)) { return; }
    const cases = {};
    zone.querySelectorAll('[data-unite]').forEach(b => { cases[b.dataset.unite] = b; });
    const deux = n => String(n).padStart(2, '0');
    let minuterie = null;
    const afficher = function () {
        const reste = Math.max(0, Math.floor((fin - Date.now()) / 1000));
        cases.j.textContent = Math.floor(reste / 86400);
        cases.h.textContent = deux(Math.floor(reste % 86400 / 3600));
        cases.m.textContent = deux(Math.floor(reste % 3600 / 60));
        cases.s.textContent = deux(reste % 60);
        if (reste === 0) {
            clearInterval(minuterie);
            const bandeau = zone.closest('.compte-rebours');
            if (bandeau) { bandeau.hidden = true; }
        }
    };
    afficher();
    minuterie = setInterval(afficher, 1000);
})();

/* Choix du contact (Yacouba, Daby, Mamadou) pour les boutons WhatsApp et « Appeler » du site. */
(function () {
    'use strict';
    const fenetre = document.getElementById('choix-contact');
    if (!fenetre) { return; }
    let retour = null;
    const fermer = function () {
        fenetre.hidden = true;
        document.body.classList.remove('choix-contact-ouvert');
        if (retour) { retour.focus(); }
    };
    const ouvrir = function (lien, appel, contact) {
        retour = lien;
        // Le message déjà préparé (« Bonjour SOTRAMA, je souhaite… ») est gardé pour chaque membre.
        const texte = appel ? null : new URL(lien.href).searchParams.get('text');
        fenetre.querySelectorAll('[data-choix-whatsapp]').forEach(function (a) {
            a.href = 'https://wa.me/' + a.dataset.choixWhatsapp + (texte ? '?text=' + encodeURIComponent(texte) : '');
        });
        fenetre.querySelector('.js-choix-whatsapp').hidden = appel || contact;
        fenetre.querySelector('.js-choix-appel').hidden = !appel || contact;
        fenetre.querySelector('.js-choix-contact').hidden = !contact;
        fenetre.hidden = false;
        document.body.classList.add('choix-contact-ouvert');
        const premier = fenetre.querySelector(appel ? 'a[href^="tel:"]' : '[data-choix-whatsapp]');
        if (premier) { premier.focus(); }
    };
    document.addEventListener('click', function (e) {
        const lien = e.target.closest('a[href^="https://wa.me/"], a[href^="tel:"]');
        if (!lien || lien.hasAttribute('data-direct') || fenetre.contains(lien)) { return; }
        e.preventDefault();
        ouvrir(lien, lien.getAttribute('href').startsWith('tel:'), 'contact' === lien.dataset.choix);
    });
    fenetre.addEventListener('click', function (e) {
        if (e.target === fenetre || e.target.closest('[data-choix-fermer]')) { fermer(); }
        else if (e.target.closest('a')) { setTimeout(fermer, 300); }
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !fenetre.hidden) { fermer(); } });
})();
