<?php

namespace App\Controller;

use App\Entity\User;
use App\Enum\StatutColis;
use App\Repository\ColisRepository;
use App\Security\ColisVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Guichet de l'agence : retrouver un colis par code de retrait, numéro ou scan du QR code. */
#[Route('/agence')]
#[IsGranted(User::ROLE_REPRESENTANT)]
class AgenceController extends AbstractController
{
    #[Route('', name: 'agence_guichet', methods: ['GET'])]
    public function guichet(Request $request, ColisRepository $colisRepository): Response
    {
        $code = trim($request->query->getString('code'));
        if ($code) {
            // Un scan du QR code (reçu ou étiquette) renvoie une URL : on en extrait le jeton.
            if (preg_match('#/(?:c|suivi)/([a-f0-9]{40})#', $code, $m)) {
                $colis = $colisRepository->findOneBy(['token' => $m[1]]);
            } else {
                $colis = $colisRepository->findOneByCode($code);
            }

            if ($colis && $this->isGranted(ColisVoter::VOIR, $colis)) {
                // Trouvé grâce au code présenté par le destinataire : il est reporté dans le formulaire de remise.
                $codePresente = hash_equals((string) $colis->getCodeRetrait(), mb_strtoupper($code)) ? $colis->getCodeRetrait() : null;

                return $this->redirectToRoute('colis_show', array_filter(['id' => $colis->getId(), 'code' => $codePresente]));
            }
            $this->addFlash('danger', 'Aucun colis trouvé pour « '.$code.' ».');
        }

        return $this->render('agence/guichet.html.twig', [
            'code' => $code,
            'aRetirer' => $colisRepository->filtreQb($this->getUser(), ['statut' => StatutColis::Arrive->value])->setMaxResults(50)->getQuery()->getResult(),
        ]);
    }
}
