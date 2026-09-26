<?php

namespace App\Service;

use App\Entity\Client;
use App\Entity\Colis;
use App\Entity\MouvementBonus;
use App\Entity\Paiement;
use App\Entity\User;
use App\Enum\ModePaiement;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Toute la logique d'argent : encaissements, remises, bonus fidélité.
 * Les montants sont en centimes.
 */
final class PaiementService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BonusFidelite $bonus,
    ) {
    }

    public function encaisser(
        Colis $colis,
        int $montant,
        ModePaiement $mode,
        ?User $caissier,
        int $remise = 0,
        ?string $reference = null,
        ?string $commentaire = null,
    ): Paiement {
        if ($montant < 0 || $remise < 0) {
            throw new \DomainException('Les montants doivent être positifs.');
        }
        if (0 === $montant && 0 === $remise) {
            throw new \DomainException('Saisissez un montant ou une remise.');
        }
        if (ModePaiement::Bonus === $mode) {
            throw new \DomainException('Le bonus fidélité s\'applique avec « Utiliser le bonus ».');
        }
        if ($remise > $colis->getMontantRestant()) {
            throw new \DomainException('La remise dépasse le montant restant.');
        }
        $restantApresRemise = $colis->getMontantRestant() - $remise;
        if ($montant > $restantApresRemise) {
            throw new \DomainException(\sprintf(
                'Le montant encaissé (%s) dépasse le reste à payer (%s).',
                self::format($montant),
                self::format($restantApresRemise),
            ));
        }

        $paiement = (new Paiement())
            ->setColis($colis)
            ->setMontant($montant)
            ->setRemise($remise)
            ->setMode($mode)
            ->setCaissier($caissier)
            ->setReference($reference ?: null)
            ->setCommentaire($commentaire ?: null);
        $colis->getPaiements()->add($paiement);

        $colis->setRemise($colis->getRemise() + $remise);
        $colis->setMontantPaye($colis->getMontantPaye() + $montant);
        $colis->rafraichirStatutPaiement();

        $this->em->persist($paiement);
        $this->em->flush();

        return $paiement;
    }

    /** Applique le bonus du client sur le reste à payer du colis. Renvoie le montant utilisé. */
    public function appliquerBonus(Colis $colis, ?User $caissier): int
    {
        $client = $colis->getClient();
        $montant = min($this->bonus->disponiblePour($colis), $colis->getMontantRestant());
        if ($montant <= 0) {
            throw new \DomainException('Aucun bonus applicable sur ce colis.');
        }

        $this->mouvementBonus($client, -$montant, 'Utilisé sur le colis '.$colis->getNumeroSuivi(), $caissier, $colis);

        $paiement = (new Paiement())
            ->setColis($colis)
            ->setMontant(0)
            ->setRemise($montant)
            ->setMode(ModePaiement::Bonus)
            ->setCaissier($caissier)
            ->setCommentaire('Bonus fidélité');
        $colis->getPaiements()->add($paiement);
        $colis->setRemise($colis->getRemise() + $montant);
        $colis->rafraichirStatutPaiement();

        $this->em->persist($paiement);
        $this->em->flush();

        return $montant;
    }

    /** Annule un paiement (admin) et rétablit les montants du colis et le bonus éventuel. */
    public function annuler(Paiement $paiement, ?User $auteur): void
    {
        $colis = $paiement->getColis();
        $colis->setMontantPaye(max(0, $colis->getMontantPaye() - $paiement->getMontant()));
        $colis->setRemise(max(0, $colis->getRemise() - $paiement->getRemise()));
        if (ModePaiement::Bonus === $paiement->getMode()) {
            $this->mouvementBonus($colis->getClient(), $paiement->getRemise(), 'Annulation du bonus utilisé sur '.$colis->getNumeroSuivi(), $auteur, $colis);
        }
        $colis->getPaiements()->removeElement($paiement);
        $colis->rafraichirStatutPaiement();

        $this->em->remove($paiement);
        $this->em->flush();
    }

    /** Modification du tarif d'un colis par un admin. */
    public function ajusterFrais(Colis $colis, int $frais): void
    {
        if ($frais < 0) {
            throw new \DomainException('Le tarif doit être positif.');
        }
        if ($frais < $colis->getRemise() + $colis->getMontantPaye()) {
            throw new \DomainException('Le nouveau tarif est inférieur à ce qui a déjà été payé ou remisé.');
        }
        $colis->setFrais($frais);
        $colis->rafraichirStatutPaiement();
        $this->em->flush();
    }

    public function mouvementBonus(Client $client, int $montant, string $libelle, ?User $auteur, ?Colis $colis = null): MouvementBonus
    {
        if ($montant < 0 && -$montant > $client->getSoldeBonus()) {
            throw new \DomainException('Solde de bonus insuffisant.');
        }
        $mouvement = (new MouvementBonus())
            ->setMontant($montant)
            ->setLibelle($libelle)
            ->setAuteur($auteur)
            ->setColis($colis);
        $client->addMouvementBonus($mouvement);
        $client->setSoldeBonus($client->getSoldeBonus() + $montant);

        $this->em->persist($mouvement);
        $this->em->flush();

        return $mouvement;
    }

    public static function format(int $centimes): string
    {
        return number_format($centimes / 100, 2, ',', ' ');
    }
}
