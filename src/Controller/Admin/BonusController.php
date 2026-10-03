<?php

namespace App\Controller\Admin;

use App\Entity\MouvementBonus;
use App\Entity\PalierBonus;
use App\Entity\User;
use App\Repository\ClientRepository;
use App\Repository\MouvementBonusRepository;
use App\Repository\PalierBonusRepository;
use App\Service\BonusFidelite;
use App\Service\ExportExcel;
use App\Util\Csv;
use App\Util\DateFr;
use App\Util\Pagination;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Suivi du bonus fidélité : gains, utilisations, soldes des clients. */
#[Route('/admin/bonus')]
#[IsGranted(User::ROLE_ADMIN)]
class BonusController extends AbstractController
{
    /**
     * Barème du bonus fidélité : l'administrateur ajoute, modifie ou retire des paliers
     * (seuil du total des articles → bonus gagné). S'applique aux colis enregistrés ou modifiés ensuite.
     */
    #[Route('/bareme', name: 'admin_bonus_bareme', methods: ['GET', 'POST'])]
    public function bareme(Request $request, PalierBonusRepository $paliers, EntityManagerInterface $em, BonusFidelite $bonus): Response
    {
        $lignes = array_map(fn (PalierBonus $p) => ['seuil' => $p->getSeuil() / 100, 'montant' => $p->getMontant() / 100], $paliers->bareme());
        $erreurs = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('bareme-bonus', $request->getPayload()->getString('_token'))) {
                throw $this->createAccessDeniedException('Jeton de sécurité invalide, rechargez la page.');
            }
            $seuils = $request->getPayload()->all('seuil');
            $montants = $request->getPayload()->all('montant');
            $lignes = $nouveau = [];
            foreach ($seuils as $i => $brut) {
                $seuil = self::euros($brut);
                $montant = self::euros($montants[$i] ?? '');
                if (null === $seuil && null === $montant) {
                    continue; // ligne vide
                }
                $lignes[] = ['seuil' => $brut, 'montant' => $montants[$i] ?? ''];
                if (null === $seuil || $seuil <= 0 || null === $montant || $montant <= 0) {
                    $erreurs[] = 'Ligne '.($i + 1).' : indiquez un seuil et un bonus supérieurs à zéro.';
                } elseif (isset($nouveau[$seuil])) {
                    $erreurs[] = 'Le seuil de '.($seuil / 100).' € est présent deux fois.';
                } else {
                    $nouveau[$seuil] = $montant;
                }
            }

            if (!$erreurs) {
                // Mise à jour palier par palier (chaque changement apparaît dans le journal).
                foreach ($paliers->findAll() as $p) {
                    if (!isset($nouveau[$p->getSeuil()])) {
                        $em->remove($p);
                    } else {
                        $p->setMontant($nouveau[$p->getSeuil()]);
                        unset($nouveau[$p->getSeuil()]);
                    }
                }
                foreach ($nouveau as $seuil => $montant) {
                    $em->persist(new PalierBonus($seuil, $montant));
                }
                $em->flush();
                $bonus->oublierBareme();
                $this->addFlash('success', 'Barème du bonus enregistré. Il s\'applique aux colis enregistrés ou modifiés à partir de maintenant.');

                return $this->redirectToRoute('admin_bonus_bareme', [], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('admin/bonus/bareme.html.twig', ['lignes' => $lignes, 'erreurs' => $erreurs], new Response(null, $erreurs ? 422 : 200));
    }

    /** « 1 250,50 » → 125050 centimes ; null si vide ou invalide. */
    private static function euros(mixed $valeur): ?int
    {
        $v = str_replace([' ', "\u{202F}", "\u{00A0}", ','], ['', '', '', '.'], trim((string) $valeur));

        return '' !== $v && is_numeric($v) ? (int) round((float) $v * 100) : null;
    }

    #[Route('', name: 'admin_bonus_index', methods: ['GET'])]
    public function index(Request $request, MouvementBonusRepository $mouvements, ClientRepository $clients, ExportExcel $excel): Response
    {
        $filtres = [
            'du' => $request->query->getString('du'),
            'au' => $request->query->getString('au'),
            'type' => \array_key_exists($t = $request->query->getString('type'), MouvementBonusRepository::TYPES) ? $t : '',
            'q' => $request->query->getString('q'),
        ];
        $qb = $mouvements->filtreQb($filtres);

        if ('csv' === $request->query->get('export')) {
            return $excel->reponse('bonus-'.date('Y-m-d').'.xlsx', 'Mouvements du bonus fidélité',
                ['Date', 'Client', 'Téléphone', 'Type', 'Libellé', 'Colis', 'Montant', 'Par'],
                (function () use ($qb) {
                    foreach ($qb->getQuery()->toIterable() as $m) {
                        /* @var MouvementBonus $m */
                        yield [
                            $m->getCreatedAt()->format('d/m/Y H:i'), $m->getClient()->getNomComplet(), $m->getClient()->getTelephone(),
                            MouvementBonusRepository::TYPES[MouvementBonusRepository::typeDe($m)], $m->getLibelle(),
                            $m->getColis()?->getNumeroSuivi(), Csv::montant($m->getMontant()), $m->getAuteur()?->getNomComplet() ?? 'Automatique',
                        ];
                    }
                })(),
                ExportExcel::periode($filtres['du'], $filtres['au']),
            );
        }

        $parMois = $mouvements->parMois(12);

        return $this->render('admin/bonus/index.html.twig', [
            'mouvements' => Pagination::depuisRequete($qb, $request, 30),
            'filtres' => $filtres,
            'types' => MouvementBonusRepository::TYPES,
            'totaux' => $mouvements->totaux($qb),
            'global' => $clients->soldeBonusGlobal(),
            'meilleurs' => $clients->meilleursSoldes(10),
            'filtreActif' => (bool) array_filter($filtres),
            'chart' => [
                'labels' => array_map(fn ($k) => DateFr::moisCourt(new \DateTimeImmutable($k.'-01')), array_keys($parMois)),
                'gagne' => array_map(fn ($v) => round($v['gagne'] / 100, 2), array_values($parMois)),
                'utilise' => array_map(fn ($v) => round($v['utilise'] / 100, 2), array_values($parMois)),
            ],
        ]);
    }
}
