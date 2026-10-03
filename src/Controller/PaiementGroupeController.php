<?php

namespace App\Controller;

use App\Entity\Colis;
use App\Enum\ModePaiement;
use App\Repository\ColisRepository;
use App\Security\ColisVoter;
use App\Security\IdChiffreur;
use App\Service\PaiementService;
use App\Util\Retour;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Paiement groupé depuis la liste des colis non payés : on coche les lignes, un récapitulatif affiche le total
 * à encaisser, puis, après confirmation, chaque colis est soldé (un paiement par colis, pour le journal et les reçus).
 */
#[Route('/colis/paiement-groupe')]
class PaiementGroupeController extends AbstractController
{
    public const MAX = 500;

    public function __construct(
        private readonly ColisRepository $colis,
        private readonly IdChiffreur $chiffreur,
        private readonly PaiementService $paiements,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Étape 1 : récapitulatif des colis cochés et du total à encaisser. */
    #[Route('', name: 'colis_paiement_groupe', methods: ['POST'])]
    public function recapitulatif(Request $request): Response
    {
        $selection = $this->selection($request);
        if (!$selection['colis']) {
            $this->addFlash('warning', 'Cochez au moins un colis non payé à encaisser.');

            return $this->versListe($request);
        }

        return $this->render('colis/paiement_groupe.html.twig', $selection + [
            'modes' => ModePaiement::encaissables(),
            'mode' => ModePaiement::tryFrom($request->request->getString('mode')) ?? ModePaiement::Especes,
            'retour' => Retour::depuis($request),
        ]);
    }

    /** Étape 2 : encaissement du reste à payer de chaque colis, tout ou rien. */
    #[Route('/confirmer', name: 'colis_paiement_groupe_confirmer', methods: ['POST'])]
    public function confirmer(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('paiement-groupe', $request->request->getString('_token'))) {
            $this->addFlash('danger', 'Le formulaire a expiré, recommencez.');

            return $this->versListe($request);
        }
        $selection = $this->selection($request);
        $mode = ModePaiement::tryFrom($request->request->getString('mode'));
        if (!$selection['colis'] || null === $mode || ModePaiement::Bonus === $mode) {
            $this->addFlash('danger', $selection['colis'] ? 'Choisissez le mode de paiement.' : 'Aucun colis à encaisser.');

            return $selection['colis'] ? $this->recapitulatif($request) : $this->versListe($request);
        }
        // Un colis payé entre-temps (par un collègue) change le total : on montre le nouveau récapitulatif.
        if ($request->request->getInt('total') !== $selection['total'] || $selection['ecartes']) {
            $this->addFlash('warning', 'La sélection a changé depuis le récapitulatif (colis déjà payé entre-temps ?). Vérifiez le nouveau total avant de confirmer.');

            return $this->recapitulatif($request);
        }

        $reference = trim($request->request->getString('reference')) ?: null;
        $commentaire = trim($request->request->getString('commentaire')) ?: 'Paiement groupé de '.\count($selection['colis']).' colis';
        try {
            $this->em->wrapInTransaction(function () use ($selection, $mode, $reference, $commentaire) {
                foreach ($selection['colis'] as $c) {
                    $this->paiements->encaisser($c, $c->getMontantRestant(), $mode, $this->getUser(), 0, $reference, $commentaire);
                }
            });
        } catch (\DomainException $e) {
            $this->addFlash('danger', 'Aucun paiement enregistré : '.$e->getMessage());

            return $this->recapitulatif($request);
        }

        $this->addFlash('success', \sprintf('%d colis encaissés (%s), total %s.',
            \count($selection['colis']), $mode->label(), PaiementService::format($selection['total'])));

        return $this->versListe($request);
    }

    /**
     * Colis cochés que l'utilisateur peut encaisser et qui restent à payer ; les autres sont écartés (comptés).
     *
     * @return array{colis: list<Colis>, total: int, ecartes: int}
     */
    private function selection(Request $request): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            fn ($v) => \is_string($v) ? $this->chiffreur->decode($v) : null,
            \array_slice((array) $request->request->all('ids'), 0, self::MAX),
        ))));
        $colis = $ids ? $this->colis->findBy(['id' => $ids], ['dateReception' => 'ASC']) : [];
        $retenus = array_values(array_filter($colis, fn (Colis $c) => $c->getMontantRestant() > 0 && $this->isGranted(ColisVoter::ENCAISSER, $c)));

        return [
            'colis' => $retenus,
            'total' => array_sum(array_map(fn (Colis $c) => $c->getMontantRestant(), $retenus)),
            'ecartes' => \count($request->request->all('ids')) - \count($retenus),
        ];
    }

    private function versListe(Request $request): Response
    {
        return $this->redirect(Retour::depuis($request) ?? $this->generateUrl('colis_index', ['paiement' => 'non_paye']), Response::HTTP_SEE_OTHER);
    }
}
