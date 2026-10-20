<?php

namespace App\Service;

use App\Entity\Appel;
use App\Entity\Client;
use App\Entity\Ramassage;
use App\Entity\User;
use App\Enum\StatutRamassage;
use App\Repository\AppelRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Registre des appels ↔ planning des ramassages.
 *
 * - L'appel est rattaché à la fiche client (retrouvée par le téléphone, sinon créée).
 * - Avec une date de ramassage, il crée ou met à jour le ramassage du planning (camion, chauffeur, adresse) ;
 *   « Confirmé » → ramassage confirmé, sinon en attente. Date retirée → le ramassage à faire est supprimé.
 * - Confirmé sans date : il attend dans « À planifier ».
 */
final class RegistreAppels
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Doublons $doublons,
        private readonly AppelRepository $appels,
    ) {
    }

    public function enregistrer(Appel $appel, ?\DateTimeImmutable $dateRamassage, ?User $auteur): void
    {
        $appel->setUtilisateur($appel->getUtilisateur() ?? $auteur);
        $appel->setClient($this->client($appel));
        if ($appel->getExpedition()) {
            $appel->setDateConteneur(null); // la date du conteneur est celle de l'expédition
        }
        $this->em->persist($appel);
        $this->synchroniser($appel, $dateRamassage);
        $this->em->flush();
    }

    /** Ramassage validé dans le planning (bouton « Valider » / « Le client a confirmé ») : l'appel est confirmé. */
    public function ramassageConfirme(Ramassage $ramassage): void
    {
        if ($appel = $this->appels->pourRamassage($ramassage)) {
            $appel->setConfirme(true);
        }
    }

    /** Demande faite sur sotrama.net : elle entre aussi dans le registre, source « Site web ». */
    /**
     * Fait monter ou descendre un client dans l'ordre de passage de son camion ; toute la tournée est renumérotée
     * 1, 2, 3… (les clients pas encore placés prennent leur place en fin de liste).
     */
    public function deplacer(Appel $appel, bool $versLeHaut): void
    {
        if (!$appel->getCamion()) {
            return;
        }
        $tournee = $this->appels->enCoursDuCamion($appel->getCamion()->getId());
        $i = array_search($appel, $tournee, true);
        $j = false === $i ? false : ($versLeHaut ? $i - 1 : $i + 1);
        if (false !== $j && isset($tournee[$j])) {
            [$tournee[$i], $tournee[$j]] = [$tournee[$j], $tournee[$i]];
        }
        foreach (array_values($tournee) as $n => $a) {
            $a->setOrdreTournee($n + 1);
        }
        $this->em->flush();
    }

    /**
     * Bouton « Traité » du registre : l'appel passe aux Archives et son ramassage encore à faire est marqué effectué.
     * Remis en cours : le ramassage marqué effectué redevient confirmé (ou en attente).
     */
    public function marquerTraite(Appel $appel, ?User $par, bool $traite): void
    {
        $appel->traiter($par, $traite);
        $r = $appel->getRamassage();
        if ($r && $traite && ($r->getStatut()->estAFaire() || StatutRamassage::Demande === $r->getStatut())) {
            $r->setStatut(StatutRamassage::Effectue);
        } elseif ($r && !$traite && StatutRamassage::Effectue === $r->getStatut()) {
            $r->setStatut($appel->isConfirme() ? StatutRamassage::Confirme : StatutRamassage::EnAttente);
        }
        $this->em->flush();
    }

    public function depuisDemandeEnLigne(Ramassage $ramassage, array $d, ?\App\Entity\Expedition $depart): Appel
    {
        // La demande du client n'est jamais perdue ; si son numéro est déjà en cours, l'équipe est prévenue.
        $existant = $this->appels->enCoursAuTelephone($d['telephone']);
        $avertissement = $existant ? \sprintf("⚠ Numéro déjà dans le registre (appel de %s du %s) : regroupez les deux appels.\n", $existant->getNomComplet(), $existant->getDateAppel()->format('d/m/Y')) : '';
        $appel = (new Appel())
            ->setSource(Appel::SOURCE_SITE)
            ->setClient($ramassage->getClient())
            ->setPrenom($d['prenom'])->setNom($d['nom'])->setTelephone($d['telephone'])
            ->setCodePostal($d['code_postal'])->setVille($d['ville'])->setAdresse($d['adresse'])
            ->setExpedition($depart)
            ->setCommentaire(trim($avertissement.($d['colis'] ?? '')."\n".($d['message'] ?? '')) ?: null)
            ->setRamassage($ramassage);
        $this->em->persist($appel);

        return $appel;
    }

    private function synchroniser(Appel $appel, ?\DateTimeImmutable $date): void
    {
        $ramassage = $appel->getRamassage();

        if (!$date) {
            // Date effacée : le ramassage encore à faire disparaît du planning (l'historique d'un ramassage fait reste).
            if ($ramassage && ($ramassage->getStatut()->estAFaire() || StatutRamassage::Demande === $ramassage->getStatut())) {
                $appel->setRamassage(null);
                $this->em->remove($ramassage);
            }

            return;
        }

        if (!$ramassage) {
            $ramassage = new Ramassage();
            $this->em->persist($ramassage);
            $appel->setRamassage($ramassage);
        }
        $camion = $appel->getCamion();
        $ramassage->setClient($appel->getClient())
            ->setDatePrevue($date)
            ->setAdresse($appel->getAdresseComplete())
            ->setCamion($camion)
            ->setAgent($camion?->getChauffeur() ?? $ramassage->getAgent() ?? $appel->getUtilisateur())
            ->setCommentaire($this->commentaire($appel));
        if ($ramassage->getStatut()->estAFaire() || StatutRamassage::Demande === $ramassage->getStatut()) {
            $ramassage->setStatut($appel->isConfirme() ? StatutRamassage::Confirme : StatutRamassage::EnAttente);
        }
    }

    private function commentaire(Appel $appel): string
    {
        return implode("\n", array_filter([
            $appel->getCommentaire(),
            null !== $appel->getEstimation() ? 'Estimation : '.number_format($appel->getEstimation() / 100, 0, ',', ' ').' €' : null,
            ($d = $appel->getDateConteneurPrevue()) ? 'Conteneur du '.$d->format('d/m/Y') : null,
            'Appel du '.$appel->getDateAppel()->format('d/m/Y').($appel->getUtilisateur() ? ' — pris par '.$appel->getUtilisateur()->getPrenom() : ''),
        ]));
    }

    /** Client choisi dans la base ; sinon retrouvé par le téléphone (doublon), sinon nouvelle fiche. Ses champs vides sont complétés. */
    private function client(Appel $appel): Client
    {
        $client = $appel->getClient() ?? $this->doublons->clientParTelephone($appel->getTelephone());
        if (!$client) {
            $client = (new Client())
                ->setNom($appel->getNom())->setPrenom($appel->getPrenom() ?? '')->setTelephone($appel->getTelephone())
                ->setNotes('Fiche créée depuis le registre des appels le '.date('d/m/Y').'.');
            $this->em->persist($client);
        }
        $client->getAdresse() || $client->setAdresse($appel->getAdresse());
        $client->getCodePostal() || $client->setCodePostal($appel->getCodePostal());
        $client->getVille() || $client->setVille($appel->getVille());

        return $client;
    }
}
