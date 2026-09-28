/* Comportements communs GESCO (jQuery fourni par le thème). */
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
    window.gescoMontant = function (v) {
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
            $('#total-poids').text(gescoMontant(poids));
            $('#total-prix').text(gescoMontant(total));
        };

        const ajouter = function () {
            const html = $liste.data('prototype').replace(/__name__/g, index++);
            const $ligne = $(html);
            $liste.append($ligne);
            $ligne.find('input').first().trigger('focus');
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
