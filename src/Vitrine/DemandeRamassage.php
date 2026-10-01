<?php

namespace App\Vitrine;

use App\Entity\Client;
use App\Entity\Expedition;
use App\Entity\Ramassage;
use App\Entity\User;
use App\Enum\Destination;
use App\Enum\StatutRamassage;
use App\Repository\UserRepository;
use App\Service\Doublons;
use App\Service\NotificationPush;
use App\Service\RegistreAppels;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Demande de ramassage envoyée par un visiteur de sotrama.net : elle arrive dans le planning avec le
 * statut « Demande en ligne », l'équipe rappelle le client pour fixer l'heure puis la valide.
 */
final class DemandeRamassage
{
    /** Ramassage gratuit en Île-de-France uniquement (départements). */
    public const DEPARTEMENTS = ['75', '77', '78', '91', '92', '93', '94', '95'];

    /** créneau → [libellé, heure du rendez-vous provisoire] */
    public const CRENEAUX = [
        'matin' => ['Matin (9 h – 12 h)', 9],
        'apres-midi' => ['Après-midi (14 h – 18 h)', 14],
        'soir' => ['Soirée (18 h – 21 h)', 18],
    ];

    public const JOURS_MAX = 60;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Doublons $doublons,
        private readonly UserRepository $users,
        private readonly NotificationPush $push,
        private readonly RegistreAppels $registre,
        private readonly UrlGeneratorInterface $urls,
        private readonly Vitrine $vitrine,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Champs saisis, nettoyés.
     *
     * @return array<string, string>
     */
    public static function lire(array $saisie): array
    {
        $champs = ['prenom', 'nom', 'telephone', 'email', 'adresse', 'code_postal', 'ville', 'date', 'creneau', 'destination', 'colis', 'message'];
        $donnees = [];
        foreach ($champs as $champ) {
            $valeur = $saisie[$champ] ?? '';
            $donnees[$champ] = \is_string($valeur) ? trim(preg_replace('/[^\P{C}\n]+/u', '', $valeur) ?? '') : '';
        }
        $donnees['consentement'] = !empty($saisie['consentement']) ? '1' : '';

        return $donnees;
    }

    /** @return array<string, string> champ → message d'erreur */
    public function valider(array $d, ?Expedition $depart): array
    {
        $erreurs = [];
        foreach (['prenom' => 'votre prénom', 'nom' => 'votre nom', 'adresse' => "l'adresse du ramassage", 'ville' => 'la ville'] as $champ => $libelle) {
            if ('' === $d[$champ]) {
                $erreurs[$champ] = 'Indiquez '.$libelle.'.';
            } elseif (mb_strlen($d[$champ]) > ('adresse' === $champ ? 255 : 100)) {
                $erreurs[$champ] = 'Texte trop long.';
            }
        }
        if (!preg_match('/^\+?[0-9 .\-]{6,20}$/', $d['telephone']) || null === Doublons::finTelephone($d['telephone'])) {
            $erreurs['telephone'] = 'Indiquez un numéro de téléphone valide.';
        }
        if ('' !== $d['email'] && (!filter_var($d['email'], \FILTER_VALIDATE_EMAIL) || mb_strlen($d['email']) > 180)) {
            $erreurs['email'] = 'Adresse e-mail invalide.';
        }
        if (!preg_match('/^\d{5}$/', $d['code_postal'])) {
            $erreurs['code_postal'] = 'Code postal à 5 chiffres.';
        } elseif (!\in_array(substr($d['code_postal'], 0, 2), self::DEPARTEMENTS, true)) {
            $erreurs['code_postal'] = 'Le ramassage en ligne est réservé à l\'Île-de-France. Contactez-nous sur WhatsApp pour les autres régions.';
        }
        $date = self::date($d['date']);
        if (!$date) {
            $erreurs['date'] = 'Choisissez une date.';
        } elseif ($date < $this->premierJour() || $date > $this->dernierJour($depart)) {
            $erreurs['date'] = 'Choisissez une date entre le '.$this->premierJour()->format('d/m/Y').' et le '.$this->dernierJour($depart)->format('d/m/Y').'.';
        } elseif ('7' === $date->format('N')) {
            $erreurs['date'] = 'Pas de ramassage le dimanche.';
        }
        if (!isset(self::CRENEAUX[$d['creneau']])) {
            $erreurs['creneau'] = 'Choisissez un créneau.';
        }
        if (!Destination::tryFrom($d['destination'])) {
            $erreurs['destination'] = 'Choisissez la destination.';
        }
        if (mb_strlen($d['colis']) > 500 || mb_strlen($d['message']) > 1000) {
            $erreurs['colis'] = 'Texte trop long.';
        }
        if (!$d['consentement']) {
            $erreurs['consentement'] = 'Veuillez accepter que SOTRAMA utilise ces informations pour vous recontacter.';
        }

        return $erreurs;
    }

    public function enregistrer(array $d, ?Expedition $depart): Ramassage
    {
        $client = $this->doublons->clientParTelephone($d['telephone']);
        if (!$client) {
            $client = (new Client())
                ->setPrenom($d['prenom'])->setNom($d['nom'])->setTelephone($d['telephone'])
                ->setEmail($d['email'] ?: null)->setAdresse($d['adresse'])->setCodePostal($d['code_postal'])->setVille($d['ville'])
                ->setNotes('Fiche créée par une demande de ramassage sur le site le '.date('d/m/Y').'.');
            $this->em->persist($client);
        }

        // La fiche d'un client existant n'est jamais modifiée par un visiteur : tout est noté sur le ramassage.
        $destination = Destination::from($d['destination']);
        [$creneau, $heure] = self::CRENEAUX[$d['creneau']];
        $commentaire = implode("\n", array_filter([
            'Demande en ligne (sotrama.net) — créneau souhaité : '.$creneau.'.',
            'Destination : '.$destination->ville().' ('.$destination->label().')'.($depart ? ', départ du '.$depart->getDateDepart()->format('d/m/Y') : '').'.',
            'Demandeur : '.$d['prenom'].' '.$d['nom'].' — '.$d['telephone'].($d['email'] ? ' — '.$d['email'] : ''),
            $d['colis'] ? 'Colis : '.$d['colis'] : null,
            $d['message'] ? 'Message : '.$d['message'] : null,
        ]));

        $ramassage = (new Ramassage())
            ->setClient($client)
            ->setDatePrevue(self::date($d['date'])->setTime($heure, 0))
            ->setAdresse($d['adresse'].', '.$d['code_postal'].' '.$d['ville'])
            ->setCommentaire($commentaire)
            ->setStatut(StatutRamassage::Demande);
        $this->em->persist($ramassage);
        // Elle apparaît aussi dans le registre des appels (source « Site web »).
        $this->registre->depuisDemandeEnLigne($ramassage, $d, $depart);
        $this->em->flush();

        $this->prevenirEquipe($ramassage, $creneau);

        return $ramassage;
    }

    public function premierJour(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('tomorrow');
    }

    /** Dernière date possible : la date limite de dépôt du départ choisi, sinon dans 60 jours. */
    public function dernierJour(?Expedition $depart): \DateTimeImmutable
    {
        $limite = new \DateTimeImmutable('today +'.self::JOURS_MAX.' days');
        $finDepart = $depart?->getDateLimiteDepot() ?? $depart?->getDateDepart()?->modify('-1 day');
        if ($finDepart && $finDepart >= $this->premierJour() && $finDepart < $limite) {
            return \DateTimeImmutable::createFromInterface($finDepart)->setTime(0, 0);
        }

        return $limite;
    }

    private static function date(string $valeur): ?\DateTimeImmutable
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur) ? (\DateTimeImmutable::createFromFormat('!Y-m-d', $valeur) ?: null) : null;
    }

    /** Notification sur le téléphone des administrateurs et agents de ramassage (si activée). */
    private function prevenirEquipe(Ramassage $ramassage, string $creneau): void
    {
        $url = $this->vitrine->urlApplication().$this->urls->generate('ramassage_edit', ['id' => $ramassage->getId()]);
        $corps = $ramassage->getClient()->getNomComplet().' — '.$ramassage->getDatePrevue()->format('d/m').', '.mb_strtolower($creneau).' — '.$ramassage->getAdresse();
        foreach ($this->users->findBy(['actif' => true]) as $user) {
            if (!array_intersect([User::ROLE_ADMIN, User::ROLE_RAMASSAGE], $user->getRoles())) {
                continue;
            }
            try {
                $this->push->envoyer($user, 'Nouvelle demande de ramassage', $corps, $url, 'demande-ramassage-'.$ramassage->getId());
            } catch (\Throwable $e) {
                $this->logger->warning('Notification de demande de ramassage non envoyée : '.$e->getMessage());
            }
        }
    }
}
