<?php

namespace App\Controller;

use App\Repository\ColisRepository;
use App\Service\DocumentService;
use App\Service\RecuPartage;
use App\Service\SuiviColis;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/** Pages publiques de suivi (sans connexion). */
#[Route('/suivi')]
class SuiviController extends AbstractController
{
    #[Route('', name: 'suivi_recherche', methods: ['GET', 'POST'])]
    public function recherche(Request $request, SuiviColis $suivi, RateLimiterFactory $suiviLimiter): Response
    {
        $numero = mb_strtoupper(trim($request->request->getString('numero')));
        $erreur = null;

        if ($request->isMethod('POST')) {
            if (!$suiviLimiter->create($request->getClientIp())->consume()->isAccepted()) {
                $erreur = 'Trop de tentatives. Réessayez dans quelques minutes.';
            } elseif (!$this->isCsrfTokenValid('suivi', $request->request->getString('_token'))) {
                $erreur = 'Session expirée, réessayez.';
            } else {
                if ($colis = $suivi->trouver($numero, $request->request->getString('telephone'))) {
                    return $this->redirectToRoute('suivi_colis', ['token' => $colis->getToken()]);
                }
                $erreur = 'Aucun colis ne correspond à ce numéro et ce téléphone.';
            }
        }

        return $this->render('suivi/recherche.html.twig', ['numero' => $numero, 'erreur' => $erreur]);
    }

    #[Route('/{token}', name: 'suivi_colis', requirements: ['token' => '[a-f0-9]{40}'], methods: ['GET'])]
    public function colis(string $token, ColisRepository $colisRepository): Response
    {
        $colis = $colisRepository->findOneBy(['token' => $token]) ?? throw $this->createNotFoundException('Colis introuvable.');

        return $this->render('suivi/colis.html.twig', ['colis' => $colis]);
    }

    /** Reçu PDF envoyé au client par WhatsApp : lien signé, distinct du lien de suivi. */
    #[Route('/{token}/recu-{signature}.pdf', name: 'suivi_recu', requirements: ['token' => '[a-f0-9]{40}', 'signature' => '[a-f0-9]{20}'], methods: ['GET'])]
    public function recu(string $token, string $signature, ColisRepository $colisRepository, RecuPartage $partage, DocumentService $documents): Response
    {
        $colis = $colisRepository->findOneBy(['token' => $token]);
        if (!$colis || !$partage->signatureValide($colis, $signature)) {
            throw $this->createNotFoundException('Reçu introuvable.');
        }

        return new Response($documents->recuPdf($colis), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="recu-'.$colis->getNumeroSuivi().'.pdf"',
            'X-Robots-Tag' => 'noindex',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
