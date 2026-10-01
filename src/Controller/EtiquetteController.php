<?php

namespace App\Controller;

use App\Entity\Colis;
use App\Entity\Expedition;
use App\Entity\User;
use App\Repository\ColisRepository;
use App\Security\ColisVoter;
use App\Service\Etiquettes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Étiquettes des colis (impression) et lecture de leur QR code / code-barres. */
class EtiquetteController extends AbstractController
{
    /** Identifiant chiffré (voir App\\Security\\IdChiffreur). */
    private const ID = '[A-Za-z0-9_-]{22}';

    #[Route('/colis/{id}/etiquettes.pdf', name: 'colis_etiquettes', requirements: ['id' => self::ID], methods: ['GET'])]
    public function colis(Request $request, Colis $colis, Etiquettes $etiquettes): Response
    {
        $this->denyAccessUnlessGranted(ColisVoter::VOIR, $colis);
        $nombre = $request->query->getInt('nombre') ?: null;

        return $this->pdf(
            $etiquettes->pdf([$colis], $request->query->getString('format'), $nombre ? min(max($nombre, 1), Etiquettes::MAX) : null, $request->query->getInt('debut', 1)),
            'etiquettes-'.$colis->getNumeroSuivi(),
        );
    }

    /** Toutes les étiquettes d'une expédition (préparation du chargement). */
    #[Route('/expeditions/{id}/etiquettes.pdf', name: 'expedition_etiquettes', requirements: ['id' => self::ID], methods: ['GET'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function expedition(Request $request, Expedition $expedition, ColisRepository $colisRepository, Etiquettes $etiquettes): Response
    {
        $liste = $colisRepository->findBy(['expedition' => $expedition], ['numeroSuivi' => 'ASC']);
        if (!$liste) {
            $this->addFlash('warning', 'Aucun colis dans cette expédition.');

            return $this->redirectToRoute('expedition_show', ['id' => $expedition->getId()]);
        }

        return $this->pdf($etiquettes->pdf($liste, $request->query->getString('format'), null, $request->query->getInt('debut', 1)), 'etiquettes-'.$expedition->getNumero());
    }

    /**
     * Lien du QR code collé sur le colis : l'équipe arrive sur la fiche, toute autre personne sur le suivi public
     * (aucune donnée sensible : ni code de retrait, ni paiements).
     */
    #[Route('/c/{token}', name: 'colis_scan', requirements: ['token' => '[a-f0-9]{40}'], methods: ['GET'])]
    public function scan(string $token, ColisRepository $colisRepository): Response
    {
        $colis = $colisRepository->findOneBy(['token' => $token]) ?? throw $this->createNotFoundException('Colis introuvable.');
        if ($this->getUser() && $this->isGranted(ColisVoter::VOIR, $colis)) {
            return $this->redirectToRoute('colis_show', ['id' => $colis->getId()]);
        }

        return $this->redirectToRoute('suivi_colis', ['token' => $token]);
    }

    /** Scanner un colis (caméra du téléphone ou douchette) pour afficher sa fiche. */
    #[Route('/scanner', name: 'colis_scanner', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function scanner(Request $request, ColisRepository $colisRepository): Response
    {
        $code = trim($request->query->getString('code'));
        if ('' !== $code) {
            $colis = self::trouver($code, $colisRepository);
            if ($colis && $this->isGranted(ColisVoter::VOIR, $colis)) {
                return $this->redirectToRoute('colis_show', ['id' => $colis->getId()]);
            }
            $this->addFlash('danger', $colis ? 'Ce colis ne fait pas partie de vos colis.' : 'Aucun colis trouvé pour « '.mb_substr($code, 0, 60).' ».');
        }

        return $this->render('colis/scanner.html.twig', ['code' => $code]);
    }

    /** QR code de l'étiquette (/c/…) ou du reçu (/suivi/…), code-barres ou numéro de suivi tapé. */
    public static function trouver(string $code, ColisRepository $colisRepository): ?Colis
    {
        if (preg_match('#/(?:c|suivi)/([a-f0-9]{40})#', $code, $m)) {
            return $colisRepository->findOneBy(['token' => $m[1]]);
        }

        return $colisRepository->findOneBy(['numeroSuivi' => mb_strtoupper($code)]);
    }

    private function pdf(string $contenu, string $nom): Response
    {
        return new Response($contenu, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$nom.'.pdf"',
        ]);
    }
}
