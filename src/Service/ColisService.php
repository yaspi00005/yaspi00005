<?php

namespace App\Service;

use App\Entity\Colis;
use App\Entity\HistoriqueStatut;
use App\Entity\User;
use App\Enum\StatutColis;
use Doctrine\ORM\EntityManagerInterface;

final class ColisService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Numerotation $numerotation,
        private readonly NotificationService $notifications,
    ) {
    }

    /** Complète et enregistre un nouveau colis saisi au ramassage. */
    public function creer(Colis $colis, ?User $agent): Colis
    {
        $colis->setDateReception(new \DateTimeImmutable());
        $colis->setNumeroSuivi($this->numerotation->numeroSuivi($colis->getDateReception()));
        $colis->setCodeRetrait($this->numerotation->codeRetrait());
        $colis->setToken($this->numerotation->token());
        $colis->setAgent($agent);
        $this->recalculerFrais($colis);
        $colis->addHistorique(new HistoriqueStatut(StatutColis::EnPreparation, $agent, 'Colis enregistré'));

        $this->em->persist($colis);
        $this->em->flush();

        $this->notifications->colisEnregistre($colis);

        return $colis;
    }

    /** Frais = somme des prix des articles. */
    public function recalculerFrais(Colis $colis): void
    {
        $colis->setFrais($colis->getTotalArticles());
        $colis->rafraichirStatutPaiement();
    }

    public function changerStatut(Colis $colis, StatutColis $statut, ?User $auteur, ?string $commentaire = null, bool $notifier = true): bool
    {
        if ($colis->getStatut() === $statut) {
            return false;
        }
        if (StatutColis::Recupere === $colis->getStatut()) {
            throw new \DomainException('Ce colis a déjà été remis au destinataire.');
        }
        if (StatutColis::Recupere === $statut) {
            throw new \DomainException('Utilisez « Remettre au destinataire » pour clôturer un colis.');
        }

        $colis->setStatut($statut);
        $colis->addHistorique(new HistoriqueStatut($statut, $auteur, $commentaire));
        $this->em->flush();

        if ($notifier) {
            $this->notifications->statutChange($colis);
        }

        return true;
    }

    /** Remise du colis au destinataire à l'agence. */
    public function remettre(Colis $colis, ?User $auteur, string $recuperePar, bool $forcerImpaye = false): void
    {
        if (StatutColis::Recupere === $colis->getStatut()) {
            throw new \DomainException('Ce colis a déjà été remis.');
        }
        if (StatutColis::Arrive !== $colis->getStatut()) {
            throw new \DomainException("Le colis n'est pas encore arrivé à l'agence.");
        }
        if (!$colis->isSolde() && !$forcerImpaye) {
            throw new \DomainException('Le colis n\'est pas entièrement payé (reste '.number_format($colis->getMontantRestant() / 100, 2, ',', ' ').').');
        }

        $colis->setStatut(StatutColis::Recupere);
        $colis->setDateRecuperation(new \DateTimeImmutable());
        $colis->setRecupererPar(trim($recuperePar) ?: $colis->getDestinataireNom());
        $colis->addHistorique(new HistoriqueStatut(StatutColis::Recupere, $auteur, 'Remis à '.$colis->getRecupererPar()));
        $this->em->flush();

        $this->notifications->statutChange($colis);
    }

    public function supprimer(Colis $colis): void
    {
        if (!$colis->isSupprimable()) {
            throw new \DomainException('Seuls les colis en préparation sans paiement peuvent être supprimés.');
        }
        $this->em->remove($colis);
        $this->em->flush();
    }
}
