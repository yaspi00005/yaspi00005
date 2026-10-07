<?php

namespace App\Service;

use App\Entity\Article;
use App\Entity\Colis;
use App\Util\Code128;
use App\Vitrine\Vitrine;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Étiquettes à coller sur les colis : une par carton / valise (« 2 / 3 »), avec un QR code qui ouvre la fiche
 * pour l'équipe (le suivi public pour les autres) et un code-barres pour les douchettes.
 */
final class Etiquettes
{
    /**
     * Formats d'impression. Dimensions en mm : page, étiquette, nombre de colonnes × lignes par page.
     *
     * @var array<string, array{libelle: string, page: array{float, float}, etiquette: array{float, float}, colonnes: int, lignes: int}>
     */
    public const FORMATS = [
        'thermique' => ['libelle' => 'Étiquette 10 × 15 cm (imprimante thermique)', 'page' => [100, 150], 'etiquette' => [100, 150], 'colonnes' => 1, 'lignes' => 1],
        'a6' => ['libelle' => 'Feuille A4 : 4 étiquettes A6 (planche autocollante 105 × 148 mm)', 'page' => [210, 297], 'etiquette' => [105, 148.5], 'colonnes' => 2, 'lignes' => 2],
        'a4' => ['libelle' => 'Feuille A4 : 1 grande étiquette (papier normal, à scotcher)', 'page' => [210, 297], 'etiquette' => [210, 297], 'colonnes' => 1, 'lignes' => 1],
        'petite' => ['libelle' => 'Petite étiquette 62 × 100 mm (Brother, Dymo)', 'page' => [62, 100], 'etiquette' => [62, 100], 'colonnes' => 1, 'lignes' => 1],
    ];

    public const FORMAT_DEFAUT = 'thermique';
    public const MAX = 200;

    public function __construct(
        private readonly Environment $twig,
        private readonly DocumentService $documents,
        private readonly UrlGeneratorInterface $urls,
        private readonly Vitrine $vitrine,
    ) {
    }

    /** Lien du QR code : court (QR plus lisible), sur l'espace de gestion. */
    public function lienScan(Colis $colis): string
    {
        return $this->vitrine->urlApplication().$this->urls->generate('colis_scan', ['token' => $colis->getToken()]);
    }

    /**
     * @param list<Colis> $colis
     * @param ?int        $nombre nombre d'étiquettes (un seul colis) ; par défaut une par article
     * @param int         $debut  position de la première étiquette sur une planche déjà entamée (1 = en haut à gauche)
     */
    public function pdf(array $colis, string $format, ?int $nombre = null, int $debut = 1): string
    {
        $f = self::FORMATS[$format] ?? self::FORMATS[self::FORMAT_DEFAUT];
        $parPage = $f['colonnes'] * $f['lignes'];

        $etiquettes = [];
        foreach ($colis as $c) {
            // Une pièce par colis physique : « Vêtements — 3 colis » donne 3 étiquettes.
            $articles = [];
            foreach ($c->getArticles() as $a) {
                array_push($articles, ...array_fill(0, $a->getQuantite(), $a));
            }
            $total = 1 === \count($colis) && $nombre ? $nombre : max(1, \count($articles));
            $qr = $this->documents->qrCodeDataUri($this->lienScan($c), 360);
            $codeBarres = Code128::pngDataUri($c->getNumeroSuivi());
            for ($i = 1; $i <= $total && \count($etiquettes) < self::MAX; ++$i) {
                // Une étiquette par colis quand le nombre correspond : le contenu de sa ligne y figure.
                $article = \count($articles) === $total ? $articles[$i - 1] : null;
                $etiquettes[] = [
                    'colis' => $c, 'numero' => $i, 'total' => $total, 'qr' => $qr, 'codeBarres' => $codeBarres,
                    'contenu' => $article ? $this->contenu($article) : $this->resume($articles),
                    'parArticle' => null !== $article,
                ];
            }
        }

        // Planche déjà entamée : on laisse vides les premières positions.
        $vides = $parPage > 1 ? max(0, min($debut, $parPage) - 1) : 0;
        $cases = array_merge(array_fill(0, $vides, null), $etiquettes);

        $html = $this->twig->render('pdf/etiquettes.html.twig', [
            'pages' => array_chunk($cases, $parPage),
            'format' => $f,
            // Tout est dessiné pour 100 mm de large, puis mis à l'échelle de l'étiquette.
            'e' => $f['etiquette'][0] / 100,
        ]);

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $mm = 72 / 25.4;
        $dompdf->setPaper([0, 0, $f['page'][0] * $mm, $f['page'][1] * $mm]);
        $dompdf->render();

        return (string) $dompdf->output();
    }

    private function contenu(Article $a): string
    {
        return trim($a->getTypeMarchandise().($a->getObservations() ? ' — '.$a->getObservations() : ''));
    }

    /** @param list<Article> $articles */
    private function resume(array $articles): string
    {
        $types = array_count_values(array_filter(array_map(fn (Article $a) => (string) $a->getTypeMarchandise(), $articles)));

        return implode(', ', array_map(fn ($t, $n) => ($n > 1 ? $n.' × ' : '').$t, array_keys($types), $types)) ?: '—';
    }
}
