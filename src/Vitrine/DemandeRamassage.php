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
use Symfony\Component\Mime\Address;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use App\Entity\Notification;
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

    /** Jours de ramassage proposés (numéro ISO du jour) ; l'heure est fixée au téléphone. */
    public const JOURS = [2 => 'mardi', 4 => 'jeudi', 7 => 'dimanche'];

    /** Heure provisoire dans le planning, en attendant l'appel de confirmation. */
    public const HEURE_PROVISOIRE = 10;

    public const JOURS_MAX = 60;

    /** Nombre de jours proposés au visiteur (environ trois semaines). */
    public const NB_JOURS_PROPOSES = 9;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Doublons $doublons,
        private readonly UserRepository $users,
        private readonly NotificationPush $push,
        private readonly RegistreAppels $registre,
        private readonly UrlGeneratorInterface $urls,
        private readonly Vitrine $vitrine,
        private readonly LoggerInterface $logger,
        private readonly MailerInterface $mailer,
        private readonly string $emailExpediteur,
        private readonly string $appNom,
    ) {
    }

    /**
     * Champs saisis, nettoyés.
     *
     * @return array<string, string>
     */
    public static function lire(array $saisie): array
    {
        $champs = ['prenom', 'nom', 'telephone', 'email', 'adresse', 'code_postal', 'ville', 'date', 'destination', 'colis', 'message'];
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
        $proposes = array_map(fn (\DateTimeImmutable $j) => $j->format('Y-m-d'), $this->joursProposes($depart));
        if (!\in_array($d['date'], $proposes, true)) {
            $erreurs['date'] = 'Choisissez un des jours proposés (mardi, jeudi ou dimanche).';
        }
        if (!Destination::tryFrom($d['destination'])) {
            $erreurs['destination'] = 'Choisissez la destination.';
        }
        // Ce qu'il faut ramasser : indispensable pour prévoir le camion.
        if ('' === $d['colis']) {
            $erreurs['colis'] = 'Indiquez ce que nous devons ramasser (nombre de valises, cartons…).';
        } elseif (mb_strlen($d['colis']) > 500 || mb_strlen($d['message']) > 1000) {
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
        $jour = self::date($d['date']);
        $commentaire = implode("\n", array_filter([
            'Demande en ligne (sotrama.net) — jour souhaité : '.\App\Util\DateFr::jour($jour).'. Heure à fixer en appelant le client.',
            'Destination : '.$destination->ville().' ('.$destination->label().')'.($depart ? ', départ du '.$depart->getDateDepart()->format('d/m/Y') : '').'.',
            'Demandeur : '.$d['prenom'].' '.$d['nom'].' — '.$d['telephone'].($d['email'] ? ' — '.$d['email'] : ''),
            $d['colis'] ? 'Colis : '.$d['colis'] : null,
            $d['message'] ? 'Message : '.$d['message'] : null,
        ]));

        $ramassage = (new Ramassage())
            ->setClient($client)
            ->setDatePrevue($jour->setTime(self::HEURE_PROVISOIRE, 0))
            ->setAdresse($d['adresse'].', '.$d['code_postal'].' '.$d['ville'])
            ->setCommentaire($commentaire)
            ->setStatut(StatutRamassage::Demande);
        $this->em->persist($ramassage);
        // Elle apparaît aussi dans le registre des appels (source « Site web »).
        $this->registre->depuisDemandeEnLigne($ramassage, $d, $depart);
        $this->em->flush();

        $this->prevenirEquipe($ramassage);

        return $ramassage;
    }

    /**
     * Prochains mardis, jeudis et dimanches, à partir de demain et jusqu'à la date limite du départ choisi.
     *
     * @return list<\DateTimeImmutable>
     */
    public function joursProposes(?Expedition $depart): array
    {
        $jours = [];
        $fin = $this->dernierJour($depart);
        for ($j = $this->premierJour(); $j <= $fin && \count($jours) < self::NB_JOURS_PROPOSES; $j = $j->modify('+1 day')) {
            if (isset(self::JOURS[(int) $j->format('N')])) {
                $jours[] = $j;
            }
        }

        return $jours;
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

    /** Administrateurs et agents de ramassage prévenus : notification du téléphone (si activée) et e-mail. */
    private function prevenirEquipe(Ramassage $ramassage): void
    {
        $url = $this->vitrine->urlApplication().$this->urls->generate('ramassage_edit', ['id' => $ramassage->getId()]);
        $client = $ramassage->getClient();
        $corps = $client->getNomComplet().' — '.\App\Util\DateFr::jour($ramassage->getDatePrevue()).' (heure à confirmer) — '.$ramassage->getAdresse();
        foreach ($this->users->findBy(['actif' => true]) as $user) {
            if (!array_intersect([User::ROLE_ADMIN, User::ROLE_RAMASSAGE], $user->getRoles())) {
                continue;
            }
            try {
                $this->push->envoyer($user, 'Nouvelle demande de ramassage', $corps, $url, 'demande-ramassage-'.$ramassage->getId());
            } catch (\Throwable $e) {
                $this->logger->warning('Notification de demande de ramassage non envoyée : '.$e->getMessage());
            }
            if (!$user->getEmail()) {
                continue;
            }
            $sujet = 'Nouvelle demande de ramassage en ligne — '.$client->getNomComplet();
            $log = new Notification(Notification::CANAL_EMAIL, $user->getEmail(), $sujet);
            try {
                $this->mailer->send((new TemplatedEmail())
                    ->from(new Address($this->emailExpediteur, $this->appNom))
                    ->to($user->getEmail())
                    ->subject($sujet)
                    ->htmlTemplate('email/demande_ramassage.html.twig')
                    ->context(['ramassage' => $ramassage, 'agent' => $user, 'lien' => $url, 'app_nom' => $this->appNom]));
                $log->setSucces(true);
            } catch (\Throwable $e) {
                $log->setErreur($e->getMessage());
                $this->logger->warning('E-mail de demande de ramassage non envoyé : '.$e->getMessage());
            }
            $this->em->persist($log);
        }
        $this->em->flush();
    }
}
