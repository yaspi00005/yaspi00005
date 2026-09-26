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
