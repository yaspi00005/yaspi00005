<?php

namespace App\Service;

use App\Entity\Expedition;
use App\Entity\User;
use App\Enum\StatutColis;
use App\Enum\StatutExpedition;
use App\Repository\ColisRepository;
use Doctrine\ORM\EntityManagerInterface;

final class ExpeditionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Numerotation $numerotation,
        private readonly ColisService $colisService,
        private readonly ColisRepository $colisRepository,
    ) {
    }

    public function creer(Expedition $expedition): void
    {
        $expedition->setNumero($this->numerotation->numeroExpedition($expedition->getDateDepart() ?? new \DateTimeImmutable()));
        $this->em->persist($expedition);
        $this->em->flush();
    }

    /**
     * Change le statut de l'expédition et répercute le statut correspondant
     * sur tous ses colis non encore remis. Renvoie le nombre de colis mis à jour.
     */
    public function changerStatut(Expedition $expedition, StatutExpedition $statut, ?User $auteur, bool $notifier = true): int
    {
        $expedition->setStatut($statut);
        if (StatutExpedition::Arrivee === $statut && !$expedition->getDateArrivee()) {
            $expedition->setDateArrivee(new \DateTimeImmutable('today'));
        }

        $n = 0;
        if ($statutColis = $statut->statutColis()) {
            foreach ($this->colisRepository->findBy(['expedition' => $expedition]) as $colis) {
                if (StatutColis::Recupere === $colis->getStatut()) {
                    continue;
                }
                if ($this->colisService->changerStatut($colis, $statutColis, $auteur, 'Expédition '.$expedition->getNumero(), $notifier)) {
                    ++$n;
                }
            }
        }
        $this->em->flush();

        return $n;
    }
}
