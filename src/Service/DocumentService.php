<?php

namespace App\Service;

use App\Entity\Colis;
use Dompdf\Dompdf;
use Dompdf\Options;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Twig\Environment;

/** Reçus PDF et QR codes. */
final class DocumentService
{
    public function __construct(
        private readonly Environment $twig,
        private readonly NotificationService $notifications,
        private readonly PhotoUploader $photos,
        private readonly BonusFidelite $bonus,
    ) {
    }

    public function qrCodeDataUri(string $contenu, int $taille = 220): string
    {
        return (new Builder(writer: new PngWriter(), data: $contenu, size: $taille, margin: 6))->build()->getDataUri();
    }

    public function qrCodeColis(Colis $colis): string
    {
        return $this->qrCodeDataUri($this->notifications->lienSuivi($colis));
    }

    /** Rend un template Twig en PDF A4. */
    public function pdf(string $template, array $contexte, string $orientation = 'portrait'): string
    {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->twig->render($template, $contexte), 'UTF-8');
        $dompdf->setPaper('A4', $orientation);
        $dompdf->render();

        return (string) $dompdf->output();
    }

    public function recuPdf(Colis $colis): string
    {
        $photos = [];
        foreach ($colis->getArticles() as $article) {
            if ($uri = $this->photos->dataUri($article->getPhoto())) {
                $photos[] = ['uri' => $uri, 'legende' => $article->getTypeMarchandise().($article->getObservations() ? ' — '.$article->getObservations() : '')];
            }
        }

        $html = $this->twig->render('pdf/recu.html.twig', [
            'colis' => $colis,
            'qr' => $this->qrCodeColis($colis),
            'photos' => $photos,
            'lien' => $this->notifications->lienSuivi($colis),
            'bonusGagne' => $this->bonus->gainDuColis($colis)?->getMontant() ?? 0,
        ]);

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
