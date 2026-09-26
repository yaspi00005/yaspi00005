<?php

namespace App\Controller;

use App\Repository\ColisRepository;
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
    public function recherche(Request $request, ColisRepository $colisRepository, RateLimiterFactory $suiviLimiter): Response
    {
        $numero = mb_strtoupper(trim($request->request->getString('numero')));
        $telephone = preg_replace('/\D/', '', $request->request->getString('telephone'));
        $erreur = null;

        if ($request->isMethod('POST')) {
            if (!$suiviLimiter->create($request->getClientIp())->consume()->isAccepted()) {
                $erreur = 'Trop de tentatives. Réessayez dans quelques minutes.';
            } elseif (!$this->isCsrfTokenValid('suivi', $request->request->getString('_token'))) {
                $erreur = 'Session expirée, réessayez.';
            } else {
                $colis = $colisRepository->findOneBy(['numeroSuivi' => $numero]);
                $telephones = $colis ? [preg_replace('/\D/', '', (string) $colis->getDestinataireTelephone()), preg_replace('/\D/', '', (string) $colis->getClient()->getTelephone())] : [];
                // Les 8 derniers chiffres suffisent à absorber les variantes d'indicatif (+223, 00223, 06…).
                $ok = $colis && \strlen($telephone) >= 8 && array_filter($telephones, fn ($t) => \strlen($t) >= 8 && str_ends_with($t, substr($telephone, -8)));
                if ($ok) {
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
}
