/* Comportements communs SOTRAMA (jQuery fourni par le thème). */
(function ($) {
    'use strict';

    // Listes déroulantes avec recherche.
    $('select.js-select2').each(function () {
        $(this).select2({ width: '100%', language: { noResults: () => 'Aucun résultat' } });
    });

    // Confirmation avant les actions sensibles : <form data-confirm="…">.
    $(document).on('submit', 'form[data-confirm]', function (e) {
        if (!window.confirm(this.dataset.confirm)) {
            e.preventDefault();
        }
    });

    // Empêche le double envoi des formulaires.
    $(document).on('submit', 'form:not([data-multi-submit])', function () {
        const form = this;
        if (form.dataset.envoye) {
            return false;
        }
        form.dataset.envoye = '1';
        $(form).find('button[type=submit]').prop('disabled', true).addClass('disabled');
        setTimeout(() => { delete form.dataset.envoye; $(form).find('button[type=submit]').prop('disabled', false).removeClass('disabled'); }, 8000);
    });

    // Formats d'affichage.
    window.formatMontant = function (v) {
        return (Math.round(v * 100) / 100).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };

    // ---------- Saisie des articles d'un colis ----------
    const $liste = $('#articles-liste');
    if ($liste.length) {
        const tarifs = $liste.data('tarifs') || {};
        let index = $liste.children('.article-ligne').length;

        const recalculer = function () {
            let poids = 0, total = 0, n = 0;
            $liste.children('.article-ligne').each(function () {
                n++;
                poids += parseFloat($(this).find('.js-poids').val().replace(',', '.')) || 0;
                total += parseFloat($(this).find('.js-prix').val().replace(',', '.')) || 0;
                $(this).find('.numero-article').text(n);
            });
            $('#total-articles').text(n);
            $('#total-poids').text(formatMontant(poids));
            $('#total-prix').text(formatMontant(total));
        };

        // Liste des marchandises avec recherche (select2).
        const initialiserTypes = function ($zone) {
            $zone.find('select.js-type-marchandise').select2({ width: '100%', language: { noResults: () => 'Aucun résultat' } });
        };
        initialiserTypes($liste);

        const ajouter = function () {
            const html = $liste.data('prototype').replace(/__name__/g, index++);
            const $ligne = $(html);
            $liste.append($ligne);
            initialiserTypes($ligne);
            recalculer();
        };

        $('#ajouter-article').on('click', ajouter);
        $liste.on('click', '.btn-retirer', function () {
            if ($liste.children('.article-ligne').length <= 1) {
                toastr.warning('Un colis contient au moins un article.');
                return;
            }
            $(this).closest('.article-ligne').remove();
            recalculer();
        });
        $liste.on('input change', '.js-poids, .js-prix', recalculer);

        // Prix suggéré = poids × tarif du type de marchandise (si le prix n'a pas été saisi à la main).
        $liste.on('input change', '.js-poids, .js-type-marchandise', function () {
            const $ligne = $(this).closest('.article-ligne');
            const tarif = tarifs[($ligne.find('.js-type-marchandise').val() || '').trim().toLowerCase()];
            const $prix = $ligne.find('.js-prix');
            if (tarif && !$prix.data('manuel')) {
                const poids = parseFloat($ligne.find('.js-poids').val().replace(',', '.')) || 0;
                $prix.val((poids * tarif / 100).toFixed(2));
                recalculer();
            }
        });
        $liste.on('keydown', '.js-prix', function () { $(this).data('manuel', true); });

        // Aperçu des photos.
        $liste.on('change', 'input[type=file]', function () {
            const $ligne = $(this).closest('.article-ligne');
            $ligne.find('.photo-apercu').remove();
            if (this.files && this.files[0]) {
                $('<img class="photo-apercu mt-2" alt="">').attr('src', URL.createObjectURL(this.files[0])).insertAfter(this);
            }
        });

        if (!$liste.children('.article-ligne').length) {
            ajouter();
        }
        recalculer();
    }
})(jQuery);

/* ---------- Notifications du navigateur (rappels des ramassages) ---------- */
(function () {
    'use strict';
    const corps = document.body.dataset;
    const boutons = document.querySelectorAll('.js-push-bouton');
    if (!corps.pushCle || !boutons.length || !('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
        return;
    }

    const cleServeur = (function (base64) {
        const b = (base64 + '='.repeat((4 - base64.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/');
        return Uint8Array.from(atob(b), c => c.charCodeAt(0));
    })(corps.pushCle);

    const envoyer = (url, donnees) => fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': corps.pushToken },
        body: JSON.stringify(donnees || {})
    }).then(r => r.json().then(j => { if (!r.ok) { throw new Error(j.error || 'Erreur'); } return j; }));

    const messageErreur = function (e) {
        const m = (e && e.message) || '';
        if (/push service not available|not supported|incognito/i.test(m)) {
            return 'Ce navigateur ne prend pas en charge les notifications (navigation privée ?). Utilisez Chrome, Edge ou Firefox en mode normal.';
        }
        if ((e && e.name === 'NotAllowedError') || /permission denied/i.test(m)) {
            return 'Autorisation refusée : autorisez les notifications pour ce site dans les réglages du navigateur.';
        }
        return m || 'Impossible d\'activer les notifications.';
    };

    const afficher = function (abonne) {
        boutons.forEach(b => {
            b.classList.remove('d-none');
            const enTete = !b.closest('.card');
            b.classList.toggle('btn-primary', !abonne && !enTete);
            b.classList.toggle('btn-outline-primary', !abonne && enTete);
            b.classList.toggle('btn-success', abonne);
            const lib = b.querySelector('.js-push-libelle');
            if (lib) { lib.textContent = abonne ? 'Notifications activées' : 'Activer les notifications'; }
            b.title = abonne ? 'Cliquer pour désactiver les notifications dans ce navigateur' : 'Recevoir les rappels des ramassages dans ce navigateur';
        });
        document.querySelectorAll('.js-push-tester').forEach(b => b.classList.toggle('d-none', !abonne));
        document.querySelectorAll('.js-push-etat').forEach(e => {
            e.textContent = Notification.permission === 'denied'
                ? 'Les notifications sont bloquées pour ce site : autorisez-les dans les réglages du navigateur (icône à gauche de l\'adresse).'
                : (abonne ? 'Ce navigateur reçoit les rappels.' : 'Ce navigateur ne reçoit pas encore les rappels.');
        });
    };

    navigator.serviceWorker.register('/sw.js').then(function (reg) {
        return reg.pushManager.getSubscription().then(function (abonnement) {
            // Réenregistre l'abonnement (clés renouvelées, changement d'utilisateur sur le poste).
            if (abonnement) { envoyer(corps.pushAbonner, abonnement.toJSON()).catch(() => {}); }
            afficher(!!abonnement);

            boutons.forEach(b => b.addEventListener('click', function () {
                reg.pushManager.getSubscription().then(function (actuel) {
                    if (actuel) {
                        return envoyer(corps.pushDesabonner, { endpoint: actuel.endpoint })
                            .then(() => actuel.unsubscribe())
                            .then(() => { afficher(false); toastr.info('Notifications désactivées pour ce navigateur.'); });
                    }
                    return Notification.requestPermission().then(function (permission) {
                        if (permission !== 'granted') {
                            afficher(false);
                            throw new Error('Autorisation refusée : activez les notifications pour ce site dans le navigateur.');
                        }
                        return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: cleServeur });
                    }).then(function (nouveau) {
                        return envoyer(corps.pushAbonner, nouveau.toJSON()).then(() => {
                            afficher(true);
                            toastr.success('Notifications activées : vous recevrez les rappels des ramassages.');
                        });
                    });
                }).catch(e => toastr.error(messageErreur(e)));
            }));
        });
    }).catch(() => {});

    document.querySelectorAll('.js-push-tester').forEach(b => b.addEventListener('click', function () {
        envoyer(corps.pushTest).then(r => toastr.success(r.envoyees + ' notification(s) envoyée(s).')).catch(e => toastr.error(e.message));
    }));
})();

/* ---------- Fiche client : ville déduite du code postal ---------- */
(function () {
    'use strict';
    const cp = document.querySelector('.js-cp-code');
    const ville = document.querySelector('.js-cp-ville');
    const pays = document.querySelector('.js-cp-pays');
    const liste = document.getElementById('villes-cp');
    const form = cp ? cp.closest('form') : null;
    if (!cp || !ville || !pays || !liste || !form || !form.dataset.villesUrl) {
        return;
    }
    const aide = ville.parentElement.querySelector('.form-text');
    const aideInitiale = aide ? aide.textContent : '';
    let dernier = '', minuterie = null, requete = 0;

    const indiquer = function (texte, classe) {
        if (!aide) { return; }
        aide.textContent = texte || aideInitiale;
        aide.classList.remove('text-success', 'text-warning');
        if (classe) { aide.classList.add(classe); }
    };

    const chercher = function () {
        const code = cp.value.trim();
        const cle = pays.value + '|' + code;
        if (code.length < 4 || cle === dernier) { return; }
        dernier = cle;
        const numero = ++requete;
        fetch(form.dataset.villesUrl + '?' + new URLSearchParams({ pays: pays.value, cp: code }))
            .then(r => r.ok ? r.json() : { villes: [] })
            .then(function (donnees) {
                if (numero !== requete) { return; } // réponse d'une saisie précédente
                const villes = donnees.villes || [];
                liste.innerHTML = '';
                villes.forEach(v => { const o = document.createElement('option'); o.value = v; liste.appendChild(o); });
                const saisieManuelle = ville.value && !ville.dataset.auto && !villes.includes(ville.value);
                if (villes.length === 1) {
                    if (saisieManuelle) {
                        indiquer('Ce code postal correspond à « ' + villes[0] + ' » : vérifiez la ville.', 'text-warning');
                    } else {
                        ville.value = villes[0]; ville.dataset.auto = '1';
                        indiquer('Ville trouvée pour ce code postal.', 'text-success');
                    }
                } else if (villes.length > 1) {
                    if (!saisieManuelle && !villes.includes(ville.value)) { ville.value = ''; delete ville.dataset.auto; }
                    indiquer(villes.length + ' communes pour ce code postal : choisissez dans la liste.', 'text-warning');
                    ville.focus();
                    if (typeof ville.showPicker === 'function') { try { ville.showPicker(); } catch (e) { /* non pris en charge */ } }
                } else {
                    indiquer('Aucune ville trouvée : saisissez-la manuellement.', 'text-warning');
                }
            })
            .catch(() => indiquer('Recherche indisponible : saisissez la ville manuellement.', 'text-warning'));
    };

    const programmer = function () { clearTimeout(minuterie); minuterie = setTimeout(chercher, 350); };
    cp.addEventListener('input', programmer);
    cp.addEventListener('change', chercher);
    pays.addEventListener('change', function () { dernier = ''; chercher(); });
    ville.addEventListener('input', function () { delete ville.dataset.auto; });
})();

/* ---------- Fenêtre récapitulative des erreurs ---------- */
(function () {
    'use strict';
    // Contrôles faits par le serveur : toutes les erreurs arrivent d'un coup dans la fenêtre,
    // au lieu des bulles du navigateur une par une.
    document.querySelectorAll('.content-body form[method="post"], .content-body form[name]').forEach(f => { f.noValidate = true; });
    const modalEl = document.getElementById('modal-erreurs');
    if (!modalEl || typeof bootstrap === 'undefined') {
        return;
    }
    const texte = el => (el ? el.textContent.replace(/\s+/g, ' ').replace(/\*$/, '').trim() : '');

    // Où se trouve le champ : « Section › Article n° 2 › Prix ».
    const localiser = function (el) {
        const morceaux = [];
        const carte = el.closest('.card');
        const titreCarte = carte ? texte(carte.querySelector('.card-header .card-title')) : '';
        if (titreCarte) { morceaux.push(titreCarte); }
        const article = el.closest('.article-ligne');
        if (article) { morceaux.push('Article n° ' + (texte(article.querySelector('.numero-article')) || '?')); }
        const bloc = el.closest('.mb-3, fieldset, .form-group');
        const libelle = bloc ? texte(bloc.querySelector('label, legend')) : '';
        if (libelle && libelle !== titreCarte) { morceaux.push(libelle); }
        return { lieu: morceaux.join(' › ') || 'Formulaire', champ: bloc ? bloc.querySelector('.is-invalid, input:not([type=hidden]), select, textarea') : null };
    };

    const erreurs = [];
    document.querySelectorAll('form .invalid-feedback, form .form-error-message').forEach(function (fb) {
        const message = texte(fb);
        if (!message || getComputedStyle(fb).display === 'none') { return; }
        const { lieu, champ } = localiser(fb);
        erreurs.push({ lieu, message, champ });
    });
    // Erreurs générales du formulaire et opérations refusées (messages rouges).
    document.querySelectorAll('.content-body form > .alert-danger, .content-body > .container-fluid > .alert-danger').forEach(function (a) {
        const message = texte(a).replace(/×$/, '').trim();
        if (message) { erreurs.push({ lieu: a.closest('form') ? 'Formulaire' : 'Opération refusée', message, champ: null }); }
    });
    if (!erreurs.length) {
        return;
    }

    const liste = modalEl.querySelector('.js-erreurs-liste');
    const formulaire = erreurs.some(e => e.champ);
    modalEl.querySelector('.js-erreurs-titre').textContent = formulaire
        ? (erreurs.length > 1 ? erreurs.length + ' erreurs à corriger' : 'Une erreur à corriger')
        : 'Opération impossible';
    modalEl.querySelector('.js-erreurs-intro').classList.toggle('d-none', !formulaire);

    const modal = new bootstrap.Modal(modalEl);
    erreurs.forEach(function (e) {
        const li = document.createElement('li');
        const bouton = document.createElement('button');
        bouton.type = 'button';
        bouton.className = 'erreur-item';
        bouton.innerHTML = '<span class="erreur-lieu"></span><span class="erreur-message"></span>';
        bouton.querySelector('.erreur-lieu').textContent = e.lieu;
        bouton.querySelector('.erreur-message').textContent = e.message;
        bouton.addEventListener('click', function () {
            modal.hide();
            if (!e.champ) { return; }
            modalEl.addEventListener('hidden.bs.modal', function () {
                const cible = e.champ.classList.contains('select2-hidden-accessible') ? e.champ.nextElementSibling : e.champ;
                cible.scrollIntoView({ behavior: 'smooth', block: 'center' });
                cible.classList.remove('champ-signale'); void cible.offsetWidth; cible.classList.add('champ-signale');
                setTimeout(() => (e.champ.classList.contains('select2-hidden-accessible') ? jQuery(e.champ).select2('open') : e.champ.focus({ preventScroll: true })), 400);
            }, { once: true });
        });
        li.appendChild(bouton);
        liste.appendChild(li);
    });
    // Après l'écran de chargement du thème, sinon il masque la fenêtre.
    const afficher = () => setTimeout(() => modal.show(), 350);
    if (document.readyState === 'complete') { afficher(); } else { window.addEventListener('load', afficher, { once: true }); }
})();
