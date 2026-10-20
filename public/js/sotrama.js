/*
 * SOTRAMA — comportements communs (jQuery fourni par le thème).
 * Auteur : Yaya DIALLO <bayayadiallo1@gmail.com>
 */
(function ($) {
    'use strict';

    // Listes déroulantes avec recherche.
    $('select.js-select2').each(function () {
        $(this).select2({ width: '100%', language: { noResults: () => 'Aucun résultat' } });
    });

    /**
     * Fenêtre de confirmation (remplace window.confirm). Retourne une promesse : true si l'utilisateur confirme.
     * options : message, titre, bouton (libellé du bouton), type ('danger', 'warning' ou 'primary'), alerte (texte en rouge).
     */
    window.sotramaConfirmer = function (options) {
        const el = document.getElementById('modal-confirmation');
        if (!el || !window.bootstrap) {
            return Promise.resolve(window.confirm(options.message));
        }
        const type = options.type || 'primary';
        el.querySelector('.js-confirmation-titre').textContent = options.titre || 'Confirmer';
        el.querySelector('.js-confirmation-message').textContent = options.message || '';
        const alerte = el.querySelector('.js-confirmation-alerte');
        alerte.textContent = options.alerte || '';
        alerte.classList.toggle('d-none', !options.alerte);
        const icone = el.querySelector('.js-confirmation-icone');
        icone.className = 'confirmation-icone js-confirmation-icone confirmation-' + type;
        icone.innerHTML = '<i class="fas ' + ({ danger: 'fa-trash-alt', warning: 'fa-exclamation-triangle', primary: 'fa-question' })[type] + '"></i>';
        const ok = el.querySelector('.js-confirmation-ok');
        ok.className = 'btn btn-rounded px-4 js-confirmation-ok btn-' + type;
        ok.textContent = options.bouton || 'Confirmer';
        const modal = bootstrap.Modal.getInstance(el) || new bootstrap.Modal(el); // Bootstrap du thème : pas de getOrCreateInstance
        return new Promise(function (resolve) {
            let choix = false;
            ok.onclick = function () { choix = true; modal.hide(); };
            el.addEventListener('hidden.bs.modal', function () { resolve(choix); }, { once: true });
            el.addEventListener('shown.bs.modal', function () { el.querySelector('.js-confirmation-annuler').focus(); }, { once: true });
            modal.show();
        });
    };

    // Confirmation avant les actions sensibles : <form data-confirm="Message" data-confirm-titre="…"
    // data-confirm-bouton="…" data-confirm-type="danger|warning|primary" data-confirm-alerte="…">.
    $(document).on('submit', 'form[data-confirm]', function (e) {
        const form = this;
        if (form.dataset.confirme === '1') {
            delete form.dataset.confirme;
            return;
        }
        e.preventDefault();
        e.stopImmediatePropagation();
        const bouton = e.originalEvent && e.originalEvent.submitter;
        window.sotramaConfirmer({
            message: form.dataset.confirm,
            titre: form.dataset.confirmTitre,
            bouton: form.dataset.confirmBouton,
            type: form.dataset.confirmType,
            alerte: form.dataset.confirmAlerte,
        }).then(function (oui) {
            if (oui) {
                form.dataset.confirme = '1';
                form.requestSubmit ? form.requestSubmit(bouton || undefined) : form.submit();
            }
        });
    });

    // Nouveau colis : avant l'envoi, vérifie qu'un colis semblable n'a pas déjà été saisi
    // (même expéditeur et même destinataire ces derniers jours) et demande confirmation.
    document.querySelectorAll('form[data-doublons-url]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            const confirme = form.querySelector('.js-confirmer-doublon');
            const client = form.querySelector('[name="colis[client]"]');
            const tel = form.querySelector('[name="colis[destinataireTelephone]"]');
            if (!confirme || confirme.value === '1' || !client || !client.value || !tel || !tel.value || (e.submitter && e.submitter.name === 'confirmer_doublon')) {
                return;
            }
            e.preventDefault();
            e.stopImmediatePropagation();
            const url = form.dataset.doublonsUrl + '?' + new URLSearchParams({ client: client.value, tel: tel.value });
            fetch(url, { credentials: 'same-origin' }).then(r => r.ok ? r.json() : []).catch(() => []).then(function (liste) {
                const envoyer = function () {
                    confirme.value = '1';
                    form.requestSubmit ? form.requestSubmit() : form.submit();
                };
                if (!liste.length) {
                    envoyer();
                    return;
                }
                window.sotramaConfirmer({
                    type: 'warning',
                    titre: 'Ce colis a peut-être déjà été enregistré',
                    message: liste.map(c => '• ' + c.numero + ' du ' + c.date + ' pour ' + c.destinataire + (c.agent ? ' (saisi par ' + c.agent + ')' : '')).join('\n'),
                    alerte: 'Même expéditeur et même destinataire ces derniers jours. Enregistrer quand même ce nouveau colis ?',
                    bouton: 'Oui, enregistrer',
                }).then(function (oui) { if (oui) { envoyer(); } });
            });
        }, true);
    });

    // Comportements sans attribut onclick/onchange (interdits par la CSP) :
    // <select data-envoi-auto> envoie son formulaire ; <input type="checkbox" data-tout-cocher=".classe"> coche tout.
    $(document).on('change', '[data-envoi-auto]', function () { this.form && this.form.submit(); });
    $(document).on('click', '[data-tout-cocher]', function () {
        const coche = this.checked;
        document.querySelectorAll(this.dataset.toutCocher).forEach(c => { c.checked = coche; });
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
        let index = $liste.children('.article-ligne').length;

        const recalculer = function () {
            let total = 0, n = 0, colis = 0;
            $liste.children('.article-ligne').each(function () {
                n++;
                colis += Math.max(1, parseInt($(this).find('.js-quantite').val(), 10) || 1);
                total += parseFloat($(this).find('.js-prix').val().replace(',', '.')) || 0;
                $(this).find('.numero-article').text(n);
            });
            $('#total-articles').text(colis);
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
        $liste.on('input change', '.js-quantite, .js-prix', recalculer);


        if (!$liste.children('.article-ligne').length) {
            ajouter();
        }
        recalculer();
    }
})(jQuery);

/* ---------- Photos : appareil photo du téléphone, compression avant l'envoi ---------- */
(function () {
    'use strict';
    const COTE_MAX = 1600;      // pixels (largeur ou hauteur)
    const QUALITE = 0.82;       // JPEG
    const bloc = el => el.closest('.js-photo');
    const taille = o => o < 1048576 ? Math.round(o / 1024) + ' Ko' : (o / 1048576).toFixed(1).replace('.', ',') + ' Mo';

    // Réduit les photos du téléphone (souvent 4 à 10 Mo) : envoi rapide en 4G et sous la limite du serveur.
    const compresser = file => new Promise(function (resolve) {
        if (!/^image\/(jpeg|png|webp)$/.test(file.type)) { resolve(file); return; }
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = function () {
            URL.revokeObjectURL(url);
            const echelle = Math.min(1, COTE_MAX / Math.max(img.naturalWidth, img.naturalHeight));
            if (echelle === 1 && file.size < 1.5 * 1048576) { resolve(file); return; }
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(img.naturalWidth * echelle);
            canvas.height = Math.round(img.naturalHeight * echelle);
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height); // fond blanc (PNG transparent)
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
            canvas.toBlob(function (blob) {
                if (!blob || blob.size >= file.size) { resolve(file); return; }
                resolve(new File([blob], (file.name || 'photo').replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg', lastModified: Date.now() }));
            }, 'image/jpeg', QUALITE);
        };
        img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
        img.src = url;
    });

    const afficher = function (zone, file) {
        zone.querySelectorAll('.js-photo-nouvelle').forEach(el => el.remove());
        // Plusieurs photos : une vignette par photo.
        if (Array.isArray(file)) {
            const info = zone.querySelector('.js-photo-info');
            zone.querySelector('.js-photo-retirer').classList.toggle('d-none', !file.length);
            info.textContent = file.length ? file.length + ' photo(s) prête(s) (' + taille(file.reduce((t, f) => t + f.size, 0)) + ')' : '';
            const input = zone.querySelector('.js-photo-input');
            file.slice().reverse().forEach(function (f) {
                const img = document.createElement('img');
                img.className = 'photo-apercu mt-2 me-2 js-photo-nouvelle';
                img.alt = 'Nouvelle photo';
                img.src = URL.createObjectURL(f);
                input.insertAdjacentElement('afterend', img);
            });
            return;
        }
        const actuelle = zone.querySelector('.js-photo-actuelle');
        const info = zone.querySelector('.js-photo-info');
        zone.querySelector('.js-photo-retirer').classList.toggle('d-none', !file);
        if (actuelle) { actuelle.classList.toggle('d-none', !!file); }
        if (!file) { info.textContent = ''; return; }
        const img = document.createElement('img');
        img.className = 'photo-apercu mt-2 js-photo-nouvelle';
        img.alt = 'Nouvelle photo';
        img.src = URL.createObjectURL(file);
        zone.querySelector('.js-photo-input').insertAdjacentElement('afterend', img);
        info.textContent = 'Photo prête (' + taille(file.size) + ')';
    };

    document.addEventListener('click', function (e) {
        const bouton = e.target.closest('.js-photo-camera, .js-photo-galerie, .js-photo-retirer');
        if (!bouton) { return; }
        const zone = bloc(bouton);
        const input = zone.querySelector('.js-photo-input');
        if (bouton.classList.contains('js-photo-retirer')) {
            input.value = '';
            input._photos = [];
            afficher(zone, input.multiple ? [] : null);
            return;
        }
        // « capture » ouvre directement l'appareil photo (arrière) sur mobile ; sans, la galerie.
        if (bouton.classList.contains('js-photo-camera')) { input.setAttribute('capture', 'environment'); } else { input.removeAttribute('capture'); }
        input.click();
    });

    document.addEventListener('change', function (e) {
        const input = e.target;
        if (!input.matches || !input.matches('.js-photo-input')) { return; }
        const zone = bloc(input);
        if (input.multiple) {
            // Les photos s'ajoutent aux précédentes (l'appareil photo n'en renvoie qu'une à la fois).
            const nouvelles = Array.from(input.files || []);
            zone.querySelector('.js-photo-info').textContent = nouvelles.length ? 'Préparation des photos…' : '';
            Promise.all(nouvelles.map(compresser)).then(function (resultats) {
                let toutes = (input._photos || []).concat(resultats);
                if (typeof DataTransfer !== 'undefined') {
                    try { const dt = new DataTransfer(); toutes.forEach(r => dt.items.add(r)); input.files = dt.files; } catch (x) { toutes = nouvelles; }
                } else {
                    toutes = nouvelles;
                }
                input._photos = toutes;
                afficher(zone, toutes);
            });
            return;
        }
        const file = input.files && input.files[0];
        if (!file) { afficher(zone, null); return; }
        zone.querySelector('.js-photo-info').textContent = 'Préparation de la photo…';
        compresser(file).then(function (resultat) {
            if (resultat !== file && typeof DataTransfer !== 'undefined') {
                try { const dt = new DataTransfer(); dt.items.add(resultat); input.files = dt.files; } catch (x) { resultat = file; }
            }
            afficher(zone, resultat);
        });
    });
})();

/* ---------- Localisation des clients (GPS du téléphone ou adresse) ---------- */
(function () {
    'use strict';
    const carteUrl = function (lat, lng) {
        const d = 0.004;
        return 'https://www.openstreetmap.org/export/embed.html?bbox=' + [lng - d, lat - d / 2, lng + d, lat + d / 2].join(',') + '&layer=mapnik&marker=' + lat + ',' + lng;
    };
    const erreurGps = function (err) {
        if (!window.isSecureContext) { return 'La localisation nécessite une connexion sécurisée (https).'; }
        switch (err && err.code) {
            case 1: return 'Localisation refusée : autorisez l\'accès à la position pour SOTRAMA dans les réglages du téléphone.';
            case 2: return 'Position introuvable : activez le GPS (localisation) du téléphone.';
            case 3: return 'Le GPS met trop de temps à répondre : réessayez, de préférence à l\'extérieur.';
            default: return 'Localisation impossible sur cet appareil.';
        }
    };
    // Cartes chargées après la page : l'écran de chargement du thème n'attend pas OpenStreetMap.
    const chargerCartes = () => document.querySelectorAll('iframe.geo-carte[data-src]').forEach(f => { if (f.dataset.src) { f.src = f.dataset.src; } });
    if (document.readyState === 'complete') { chargerCartes(); } else { window.addEventListener('load', chargerCartes, { once: true }); }

    window.sotramaPosition = function () {
        return new Promise(function (resolve, reject) {
            if (!navigator.geolocation) { reject({ code: 0 }); return; }
            navigator.geolocation.getCurrentPosition(resolve, reject, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
        });
    };

    // Formulaire client
    const zone = document.querySelector('.js-geo');
    if (zone) {
        const form = zone.closest('form');
        const lat = zone.querySelector('.js-geo-lat');
        const lng = zone.querySelector('.js-geo-lng');
        const source = zone.querySelector('.js-geo-source');
        const etat = zone.querySelector('.js-geo-etat');
        const carte = zone.querySelector('.geo-carte');
        const effacer = zone.querySelector('.js-geo-effacer');
        const champ = sel => form.querySelector(sel);
        const indiquer = (texte, classe) => { etat.textContent = texte; etat.className = 'fs-13 mb-2 js-geo-etat ' + (classe || ''); };

        const placer = function (la, ln, origine, texte, classe) {
            lat.value = la.toFixed(7); lng.value = ln.toFixed(7); source.value = origine;
            carte.src = carteUrl(la, ln); carte.classList.remove('d-none');
            effacer.classList.remove('d-none');
            indiquer(texte, classe || 'text-success');
        };

        zone.querySelector('.js-geo-gps').addEventListener('click', function () {
            indiquer('Recherche de la position GPS…');
            window.sotramaPosition().then(function (p) {
                const precision = Math.round(p.coords.accuracy);
                placer(p.coords.latitude, p.coords.longitude, 'gps',
                    'Position GPS relevée (précision ± ' + precision + ' m).' + (precision > 150 ? ' Précision faible : réessayez à l\'extérieur.' : ''),
                    precision > 150 ? 'text-warning' : 'text-success');
            }).catch(e => indiquer(erreurGps(e), 'text-danger'));
        });

        let requete = 0;
        const localiserAdresse = function (manuel) {
            const params = {
                adresse: (champ('[name$="[adresse]"]') || {}).value || '',
                cp: (champ('.js-cp-code') || {}).value || '',
                ville: (champ('.js-cp-ville') || {}).value || '',
                pays: (champ('.js-cp-pays') || {}).value || ''
            };
            if (!params.cp.trim() && !params.ville.trim()) {
                if (manuel) { indiquer('Renseignez au moins le code postal ou la ville.', 'text-warning'); }
                return;
            }
            const numero = ++requete;
            if (manuel) { indiquer('Recherche de l\'adresse…'); }
            fetch(form.dataset.geocoderUrl + '?' + new URLSearchParams(params))
                .then(r => r.ok ? r.json() : { position: null })
                .then(function (d) {
                    if (numero !== requete) { return; }
                    const p = d.position;
                    if (!p) {
                        if (manuel) { indiquer('Adresse introuvable : vérifiez-la ou utilisez « Ma position actuelle » chez le client.', 'text-warning'); }
                        return;
                    }
                    placer(p.lat, p.lng, 'adresse', 'Position trouvée : ' + p.libelle + (p.precision === 'approximative' ? ' (approximative).' : '.'),
                        p.precision === 'approximative' ? 'text-warning' : 'text-success');
                })
                .catch(() => manuel && indiquer('Recherche indisponible pour le moment.', 'text-warning'));
        };
        zone.querySelector('.js-geo-adresse').addEventListener('click', () => localiserAdresse(true));

        // Position déduite automatiquement de l'adresse, sauf si elle a été relevée au GPS.
        let minuterie = null;
        const auto = function () {
            if (source.value === 'gps') { return; }
            clearTimeout(minuterie);
            minuterie = setTimeout(() => localiserAdresse(false), 900);
        };
        ['[name$="[adresse]"]', '.js-cp-code', '.js-cp-ville'].forEach(sel => { const el = champ(sel); if (el) { el.addEventListener('change', auto); } });

        effacer.addEventListener('click', function () {
            lat.value = ''; lng.value = ''; source.value = '';
            carte.classList.add('d-none'); carte.src = 'about:blank';
            effacer.classList.add('d-none');
            indiquer('Position effacée.', 'text-muted');
        });
    }

    // Fiche client : « Je suis chez le client »
    const fiche = document.querySelector('.js-geo-fiche');
    if (fiche) {
        const bouton = fiche.querySelector('.js-geo-ici');
        bouton.addEventListener('click', function () {
            bouton.disabled = true;
            window.sotramaPosition().then(function (p) {
                const precision = Math.round(p.coords.accuracy);
                if (!window.confirm('Enregistrer votre position actuelle (précision ± ' + precision + ' m) comme domicile du client ?')) { return null; }
                return fetch(fiche.dataset.url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': fiche.dataset.token },
                    body: JSON.stringify({ lat: p.coords.latitude, lng: p.coords.longitude, precision: precision })
                }).then(r => r.json().then(j => { if (!r.ok) { throw new Error(j.error || 'Erreur'); } toastr.success('Position du client enregistrée.'); setTimeout(() => window.location.reload(), 700); }));
            }).catch(e => toastr.error(e && e.message && !('code' in e) ? e.message : erreurGps(e)))
              .finally(() => { bouton.disabled = false; });
        });
    }
})();

/* ---------- Partage du reçu PDF (téléphone : WhatsApp, Gmail…) ---------- */
(function () {
    'use strict';
    document.querySelectorAll('.js-partager-recu').forEach(function (bouton) {
        const test = new File([''], 'recu.pdf', { type: 'application/pdf' });
        if (!navigator.canShare || !navigator.canShare({ files: [test] })) { return; }
        bouton.classList.remove('d-none');
        bouton.addEventListener('click', function () {
            bouton.disabled = true;
            fetch(bouton.dataset.url, { credentials: 'same-origin' })
                .then(r => { if (!r.ok) { throw new Error('Reçu indisponible'); } return r.blob(); })
                .then(pdf => navigator.share({ files: [new File([pdf], bouton.dataset.nom, { type: 'application/pdf' })], title: bouton.dataset.titre }))
                .catch(e => { if (e.name !== 'AbortError') { toastr.error(e.message); } })
                .finally(() => { bouton.disabled = false; });
        });
    });
})();

/* ---------- Notifications du navigateur (rappels des ramassages) ---------- */
(function () {
    'use strict';
    const corps = document.body.dataset;
    const boutons = document.querySelectorAll('.js-push-bouton');
    if (!corps.pushCle || !boutons.length || !('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
        return;
    }

    // Clé mal configurée sur le serveur : on abandonne ce bloc seulement (le reste du script doit tourner).
    let cleServeur;
    try {
        const b = (corps.pushCle + '='.repeat((4 - corps.pushCle.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/');
        cleServeur = Uint8Array.from(atob(b), c => c.charCodeAt(0));
    } catch (e) {
        return;
    }

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

/* ---------- Registre des appels ---------- */
(function () {
    'use strict';
    // Tableau rafraîchi automatiquement : chacun voit les appels notés par les autres.
    const liste = document.querySelector('[data-rafraichir]');
    if (liste) {
        const delai = (parseInt(liste.dataset.rafraichir, 10) || 30) * 1000;
        const rafraichir = function (force) {
            // Pas pendant une saisie dans le tableau, ni quand l'onglet est caché (sauf après un changement d'ordre).
            if (force !== true && (document.hidden || liste.contains(document.activeElement))) { return; }
            fetch(window.location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(r => r.ok && !r.redirected ? r.text() : Promise.reject())
                .then(html => { liste.innerHTML = html; })
                .catch(() => {});
        };
        setInterval(rafraichir, delai);
        document.addEventListener('visibilitychange', function () { if (!document.hidden) { rafraichir(); } });

        // Ordre de passage du camion : flèches ↑ ↓ sans recharger la page. La ligne bouge tout de suite,
        // puis la liste est relue (numéros et flèches à jour).
        // Bouton « Traité » (ou « Remettre en cours ») : après la confirmation, la ligne disparaît sans recharger.
        liste.addEventListener('submit', function (e) {
            const f = e.target.closest('.js-traiter-appel');
            if (!f || f.dataset.confirme !== '1') { return; } // la fenêtre de confirmation passe d'abord
            e.preventDefault();
            const ligne = f.closest('tr, .registre-fiche');
            if (ligne) { ligne.classList.add('ligne-traitee'); }
            fetch(f.action, { method: 'POST', body: new FormData(f), headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(r => r.ok ? r.json() : Promise.reject())
                .then(d => { if (window.toastr) { toastr.success(d.archive ? 'Appel traité : il est dans les Archives.' : 'Appel remis en cours.'); } })
                .catch(() => { if (window.toastr) { toastr.error("L'appel n'a pas pu être mis à jour. Réessayez."); } })
                .finally(() => setTimeout(() => rafraichir(true), 350));
        });

        liste.addEventListener('submit', function (e) {
            const f = e.target.closest('.js-deplacer-appel');
            if (!f) { return; }
            e.preventDefault();
            const ligne = f.closest('tr, .registre-fiche');
            const haut = (f.querySelector('[name="sens"]') || {}).value === 'haut';
            const voisine = ligne && (haut ? ligne.previousElementSibling : ligne.nextElementSibling);
            if (ligne && voisine && voisine.tagName === ligne.tagName) {
                haut ? voisine.before(ligne) : voisine.after(ligne);
                ligne.classList.remove('ligne-deplacee'); void ligne.offsetWidth; ligne.classList.add('ligne-deplacee');
            }
            fetch(f.action, { method: 'POST', body: new FormData(f), headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(r => r.ok ? r.json() : Promise.reject())
                .catch(() => { if (window.toastr) { toastr.error("L'ordre n'a pas pu être enregistré. Réessayez."); } })
                .finally(() => rafraichir(true));
        });
    }

    // Client : choisi dans la base ou nouveau client.
    const form = document.querySelector('.js-form-appel');
    if (!form) { return; }
    const champ = nom => form.querySelector('[name$="[' + nom + ']"]');
    const choixClient = form.querySelector('select.js-appel-client');
    const info = form.querySelector('.js-client-connu');
    const mode = () => (form.querySelector('.js-mode-client input:checked') || {}).value || 'nouveau';
    const afficherMode = function () {
        const existant = mode() === 'existant';
        form.querySelectorAll('.js-bloc-existant').forEach(b => b.classList.toggle('d-none', !existant));
        form.querySelectorAll('.js-bloc-nouveau').forEach(b => b.classList.toggle('d-none', existant));
    };
    form.querySelectorAll('.js-mode-client input').forEach(r => r.addEventListener('change', afficherMode));
    afficherMode();

    // Client choisi dans la liste : ses coordonnées remplissent l'appel (l'adresse reste modifiable).
    if (choixClient && window.jQuery) {
        jQuery(choixClient).on('change', function () {
            const o = choixClient.selectedOptions[0];
            if (!o || !o.value) { return; }
            [['nom', 'nom'], ['prenom', 'prenom'], ['telephone', 'telephone'], ['codePostal', 'codePostal'], ['ville', 'ville'], ['adresse', 'adresse']].forEach(function ([k, d]) {
                const c = champ(k);
                if (c) { c.value = o.dataset[d] || ''; }
            });
            if (info) { info.classList.add('d-none'); }
        });
    }

    // Nouveau client dont le téléphone est déjà dans la base : on bascule sur sa fiche.
    const tel = form.querySelector('.js-appel-telephone');
    if (!tel || !form.dataset.clientUrl) { return; }
    let dernier = '';
    const chercher = function () {
        const chiffres = tel.value.replace(/\D/g, '');
        if (mode() !== 'nouveau' || chiffres.length < 8 || chiffres === dernier) { return; }
        dernier = chiffres;
        fetch(form.dataset.clientUrl + '?' + new URLSearchParams({ telephone: tel.value }), { credentials: 'same-origin' })
            .then(r => r.ok ? r.json() : { client: null })
            .then(function (d) {
                if (!info) { return; }
                if (!d.client) { info.classList.add('d-none'); return; }
                const radio = form.querySelector('.js-mode-client input[value="existant"]');
                if (radio && choixClient && window.jQuery) {
                    radio.checked = true;
                    afficherMode();
                    jQuery(choixClient).val(String(d.client.id)).trigger('change');
                }
                info.innerHTML = '';
                const lien = document.createElement('a');
                lien.href = d.client.fiche; lien.target = '_blank'; lien.className = 'text-success';
                lien.textContent = '✔ Déjà client : ' + [d.client.prenom, d.client.nom].filter(Boolean).join(' ') + ' (' + d.client.colis + ' colis) — fiche sélectionnée';
                info.appendChild(lien);
                info.classList.remove('d-none');
            })
            .catch(() => {});
    };
    tel.addEventListener('change', chercher);
    tel.addEventListener('blur', chercher);
})();

/* ---------- Étiquettes : format mémorisé, position sur planche A4 ---------- */
(function () {
    'use strict';
    document.querySelectorAll('.js-form-etiquettes').forEach(function (form) {
        const format = form.querySelector('.js-etiquettes-format');
        const debut = form.querySelector('.js-etiquettes-debut');
        try {
            const memo = localStorage.getItem('sotrama_format_etiquettes');
            if (memo && format.querySelector('option[value="' + memo + '"]')) { format.value = memo; }
        } catch (e) { /* stockage indisponible */ }
        const afficher = () => { if (debut) { debut.classList.toggle('d-none', format.value !== 'a6'); } };
        format.addEventListener('change', function () {
            afficher();
            try { localStorage.setItem('sotrama_format_etiquettes', format.value); } catch (e) { /* ignoré */ }
        });
        afficher();
    });
})();

// Paiement groupé (liste des colis non payés) : nombre de colis cochés et total à encaisser, en direct.
(function () {
    'use strict';
    const form = document.querySelector('.js-paiement-groupe');
    if (!form) { return; }
    const cases = Array.from(document.querySelectorAll('.js-groupe-case'));
    const tout = document.querySelector('.js-groupe-tout');
    const nb = form.querySelector('.js-groupe-nb');
    const total = form.querySelector('.js-groupe-total');
    const bouton = form.querySelector('.js-groupe-bouton');
    const format = (centimes) => (centimes / 100).toLocaleString('fr-FR', { minimumFractionDigits: centimes % 100 ? 2 : 0, maximumFractionDigits: 2 }) + ' ' + (form.dataset.devise || '€');
    const majour = function () {
        const cochees = cases.filter((c) => c.checked);
        nb.textContent = cochees.length;
        total.textContent = format(cochees.reduce((s, c) => s + parseInt(c.dataset.reste, 10), 0));
        bouton.disabled = cochees.length === 0;
        if (tout) {
            tout.checked = cochees.length > 0 && cochees.length === cases.length;
            tout.indeterminate = cochees.length > 0 && cochees.length < cases.length;
        }
        cases.forEach((c) => c.closest('tr').classList.toggle('table-active', c.checked));
    };
    cases.forEach((c) => c.addEventListener('change', majour));
    if (tout) {
        tout.addEventListener('change', function () { cases.forEach((c) => { c.checked = tout.checked; }); majour(); });
    }
    majour();
})();

/* ---------- Registre des appels : code postal déduit de la ville ---------- */
(function () {
    'use strict';
    const form = document.querySelector('.js-form-appel');
    if (!form || !form.dataset.codesPostauxUrl) { return; }
    const cp = form.querySelector('.js-cp-code');
    const ville = form.querySelector('.js-cp-ville');
    if (!cp || !ville) { return; }
    const liste = document.createElement('datalist');
    liste.id = 'codes-postaux-ville';
    form.appendChild(liste);
    cp.setAttribute('list', liste.id);
    const aide = document.createElement('div');
    aide.className = 'form-text';
    cp.insertAdjacentElement('afterend', aide);
    const simple = s => (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
    let derniere = '', requete = 0;

    const chercher = function () {
        const nom = ville.value.trim();
        // Code postal déjà tapé à la main : on ne l'écrase pas.
        if (nom.length < 2 || simple(nom) === derniere || (cp.value.trim() && !cp.dataset.auto)) { return; }
        derniere = simple(nom);
        const numero = ++requete;
        fetch(form.dataset.codesPostauxUrl + '?' + new URLSearchParams({ ville: nom }), { credentials: 'same-origin' })
            .then(r => r.ok ? r.json() : { communes: [] })
            .then(function (d) {
                if (numero !== requete) { return; }
                let communes = d.communes || [];
                const exactes = communes.filter(c => simple(c.ville) === simple(nom));
                if (exactes.length) { communes = exactes; }
                liste.innerHTML = '';
                communes.forEach(function (c) { const o = document.createElement('option'); o.value = c.cp; o.label = c.ville; liste.appendChild(o); });
                const codes = [...new Set(communes.map(c => c.cp))];
                if (codes.length === 1) {
                    cp.value = codes[0]; cp.dataset.auto = '1';
                    if (exactes.length) { ville.value = communes[0].ville; }
                    aide.textContent = 'Code postal trouvé pour ' + communes[0].ville + '.';
                    aide.className = 'form-text text-success';
                } else if (codes.length > 1) {
                    if (cp.dataset.auto) { cp.value = ''; }
                    aide.textContent = codes.length + ' codes postaux possibles : choisissez dans la liste.';
                    aide.className = 'form-text text-warning';
                    cp.focus();
                    if (typeof cp.showPicker === 'function') { try { cp.showPicker(); } catch (e) { /* non pris en charge */ } }
                } else {
                    aide.textContent = 'Ville introuvable : saisissez le code postal.';
                    aide.className = 'form-text text-warning';
                }
            })
            .catch(() => { aide.textContent = ''; });
    };
    ville.addEventListener('change', chercher);
    ville.addEventListener('blur', chercher);
    cp.addEventListener('input', function () { delete cp.dataset.auto; aide.textContent = ''; });
})();

/* ---------- Récapitulatif « À traiter » à la connexion ---------- */
(function () {
    'use strict';
    const el = document.getElementById('modal-a-traiter');
    if (!el || typeof bootstrap === 'undefined') { return; }
    // Après l'écran de chargement du thème, sinon il masque la fenêtre.
    const afficher = () => setTimeout(() => bootstrap.Modal.getOrCreateInstance(el).show(), 400);
    if (document.readyState === 'complete') { afficher(); } else { window.addEventListener('load', afficher, { once: true }); }
})();
