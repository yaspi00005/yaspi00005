<?php

namespace App\Journal;

use App\Entity\Article;
use App\Entity\Client;
use App\Entity\Colis;
use App\Entity\DepenseExpedition;
use App\Entity\Expedition;
use App\Entity\Journal;
use App\Entity\MouvementBonus;
use App\Entity\Paiement;
use App\Entity\Ramassage;
use App\Entity\TypeMarchandise;
use App\Entity\User;
use App\Service\PaiementService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Journal d'activité : chaque ajout, modification et suppression des éléments suivis est enregistré
 * automatiquement (au niveau de la base, quelle que soit la page utilisée), avec l'auteur, la date,
 * l'adresse IP et, pour les modifications, les valeurs avant / après.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class Journaliseur
{
    /** Éléments suivis => libellé affiché. */
    public const SUIVIS = [
        Colis::class => 'Colis',
        Article::class => 'Article',
        Paiement::class => 'Paiement',
        Client::class => 'Client',
        Expedition::class => 'Expédition',
        DepenseExpedition::class => 'Dépense',
        Ramassage::class => 'Ramassage',
        MouvementBonus::class => 'Bonus',
        User::class => 'Utilisateur',
        TypeMarchandise::class => 'Type de marchandise',
    ];

    /** Champs jamais journalisés : secrets (mot de passe, jeton de suivi, code de retrait), horodatages techniques, valeurs recalculées. */
    private const IGNORES = [
        'password', 'token', 'codeRetrait', 'createdAt', 'updatedAt', 'derniereConnexion', 'positionMajLe',
        'rappelVeilleEnvoyeLe', 'rappelProcheEnvoyeLe', 'montantPaye', 'statutPaiement', 'soldeBonus',
    ];

    /** Montants en centimes. */
    private const MONTANTS = ['frais', 'remise', 'montant', 'prix', 'prixUnitaire'];

    private const LIBELLES_CHAMPS = [
        'numeroSuivi' => 'N° de suivi', 'codeRetrait' => 'Code de retrait', 'destinataireNom' => 'Destinataire',
        'destinataireTelephone' => 'Tél. destinataire', 'typeExpedition' => 'Type', 'statut' => 'Statut',
        'frais' => 'Tarif', 'remise' => 'Remise', 'observations' => 'Observations', 'photo' => 'Photo',
        'dateReception' => 'Date de réception', 'dateRecuperation' => 'Date de remise', 'recupererPar' => 'Remis à',
        'expedition' => 'Expédition', 'client' => 'Client', 'agent' => 'Agent', 'typeMarchandise' => 'Marchandise',
        'poidsVolume' => 'Poids', 'prix' => 'Prix', 'montant' => 'Montant', 'mode' => 'Mode', 'date' => 'Date',
        'caissier' => 'Caissier', 'reference' => 'Référence', 'commentaire' => 'Commentaire', 'nom' => 'Nom',
        'prenom' => 'Prénom', 'telephone' => 'Téléphone', 'email' => 'E-mail', 'adresse' => 'Adresse',
        'ville' => 'Ville', 'codePostal' => 'Code postal', 'pays' => 'Pays', 'notes' => 'Notes',
        'latitude' => 'Latitude', 'longitude' => 'Longitude', 'positionSource' => 'Origine de la position',
        'datePrevue' => 'Date prévue', 'roles' => 'Rôle', 'actif' => 'Actif', 'username' => 'Identifiant',
        'numero' => 'Numéro', 'modeTransport' => 'Transport', 'destination' => 'Destination',
        'dateDepart' => 'Départ', 'dateArriveePrevue' => 'Arrivée prévue', 'dateArrivee' => 'Arrivée',
        'libelle' => 'Libellé', 'prixUnitaire' => 'Tarif au kg', 'categorie' => 'Type de dépense', 'auteur' => 'Saisie par',
    ];

    private bool $actif = true;
    /** @var list<array{0: object, 1: string, 2: ?array, 3: ?array{string, ?int, array{?int, ?int}}}> actions en attente de la fin de l'enregistrement */
    private array $enAttente = [];
    private bool $ecriture = false;

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
    ) {
    }

    /** Suspend le journal (import de l'ancienne base, tâches de masse qui écrivent leur propre résumé). */
    public function suspendre(bool $suspendre = true): void
    {
        $this->actif = !$suspendre;
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if (!$this->actif || $this->ecriture) {
            return;
        }
        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entite) {
            if ($this->suivi($entite)) {
                $this->enAttente[] = [$entite, Journal::CREATION, $this->instantane($args->getObjectManager(), $entite), null];
            }
        }
        foreach ($uow->getScheduledEntityUpdates() as $entite) {
            if (!$this->suivi($entite)) {
                continue;
            }
            $changements = [];
            foreach ($uow->getEntityChangeSet($entite) as $champ => [$avant, $apres]) {
                if (\in_array($champ, self::IGNORES, true)) {
                    continue;
                }
                [$avant, $apres] = [$this->valeur($champ, $avant), $this->valeur($champ, $apres)];
                if ($avant !== $apres) {
                    $changements[$this->libelleChamp($champ)] = [$avant, $apres];
                }
            }
            if ($changements) {
                $this->enAttente[] = [$entite, Journal::MODIFICATION, $changements, null];
            }
        }
        foreach ($uow->getScheduledEntityDeletions() as $entite) {
            if ($this->suivi($entite)) {
                // Libellé et identifiants relevés maintenant : Doctrine les efface pendant la suppression.
                $this->enAttente[] = [$entite, Journal::SUPPRESSION, $this->instantane($args->getObjectManager(), $entite), [$this->libelle($entite), $entite->getId(), $this->liens($entite)]];
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if (!$this->enAttente || $this->ecriture) {
            return;
        }
        $em = $args->getObjectManager();
        $actions = $this->enAttente;
        $this->enAttente = [];
        $this->ecriture = true;
        try {
            foreach ($actions as [$entite, $action, $changements, $releve]) {
                [$libelle, $id, [$colis, $client]] = $releve ?? [$this->libelle($entite), $entite->getId(), $this->liens($entite)];
                $em->persist($this->nouveau($action, $libelle)
                    ->concerne(self::SUIVIS[$entite::class], $id)
                    ->setChangements($changements)
                    ->lier($colis, $client));
            }
            $em->flush();
        } finally {
            $this->ecriture = false;
        }
    }

    /** Entrée écrite à la main (connexions, suppression automatique des photos…). */
    public function ecrire(EntityManagerInterface $em, string $action, string $libelle, ?string $entite = null, ?int $entiteId = null, ?array $changements = null, ?int $colisId = null, ?int $clientId = null, ?User $utilisateur = null, ?string $nom = null): Journal
    {
        $journal = $this->nouveau($action, $libelle, $utilisateur, $nom)->concerne($entite, $entiteId)->setChangements($changements)->lier($colisId, $clientId);
        $this->ecriture = true;
        try {
            $em->persist($journal);
            $em->flush();
        } finally {
            $this->ecriture = false;
        }

        return $journal;
    }

    private function nouveau(string $action, string $libelle, ?User $utilisateur = null, ?string $nom = null): Journal
    {
        $utilisateur ??= ($u = $this->security->getUser()) instanceof User ? $u : null;
        $nom ??= $utilisateur ? $utilisateur->getNomComplet().' ('.$utilisateur->getUsername().')' : 'Système';

        return (new Journal($action, $libelle, $utilisateur, $nom))->setIp($this->requestStack->getMainRequest()?->getClientIp());
    }

    private function suivi(object $entite): bool
    {
        return isset(self::SUIVIS[$entite::class]);
    }

    /** Valeurs d'un élément ajouté ou supprimé (champs renseignés seulement). */
    private function instantane(EntityManagerInterface $em, object $entite): array
    {
        $meta = $em->getClassMetadata($entite::class);
        $valeurs = [];
        foreach ([...$meta->getFieldNames(), ...array_keys(array_filter($meta->getAssociationMappings(), fn ($a) => $a->isToOne()))] as $champ) {
            if ('id' === $champ || \in_array($champ, self::IGNORES, true)) {
                continue;
            }
            $valeur = $this->valeur($champ, $meta->getFieldValue($entite, $champ));
            if (null !== $valeur && '' !== $valeur) {
                $valeurs[$this->libelleChamp($champ)] = [null, $valeur];
            }
        }

        return $valeurs;
    }

    private function valeur(string $champ, mixed $valeur): ?string
    {
        return match (true) {
            null === $valeur => null,
            \in_array($champ, self::MONTANTS, true) && is_numeric($valeur) => PaiementService::format((int) $valeur).' €',
            \is_bool($valeur) => $valeur ? 'oui' : 'non',
            $valeur instanceof \DateTimeInterface => $valeur->format('H:i') === '00:00' ? $valeur->format('d/m/Y') : $valeur->format('d/m/Y H:i'),
            $valeur instanceof \BackedEnum => method_exists($valeur, 'label') ? $valeur->label() : (string) $valeur->value,
            'roles' === $champ && \is_array($valeur) => implode(', ', array_diff($valeur, ['ROLE_USER'])) ?: 'aucun',
            \is_array($valeur) => json_encode($valeur, \JSON_UNESCAPED_UNICODE),
            \is_object($valeur) => $this->libelle($valeur),
            default => mb_substr((string) $valeur, 0, 300),
        };
    }

    private function libelleChamp(string $champ): string
    {
        return self::LIBELLES_CHAMPS[$champ] ?? $champ;
    }

    private function libelle(object $entite): string
    {
        return match (true) {
            $entite instanceof Colis => 'Colis '.$entite->getNumeroSuivi(),
            $entite instanceof Article => 'Article « '.$entite->getTypeMarchandise().' » du colis '.$entite->getColis()?->getNumeroSuivi(),
            $entite instanceof Paiement => 'Paiement de '.PaiementService::format($entite->getMontant()).' € ('.$entite->getMode()->label().') · colis '.$entite->getColis()?->getNumeroSuivi(),
            $entite instanceof Client => 'Client '.$entite->getNomComplet(),
            $entite instanceof Expedition => 'Expédition '.$entite->getNumero(),
            $entite instanceof DepenseExpedition => 'Dépense de '.PaiementService::format((int) $entite->getMontant()).' € ('.$entite->getCategorie()?->label().') · expédition '.$entite->getExpedition()?->getNumero(),
            $entite instanceof Ramassage => 'Ramassage chez '.$entite->getClient()?->getNomComplet().($entite->getDatePrevue() ? ' le '.$entite->getDatePrevue()->format('d/m/Y H:i') : ''),
            $entite instanceof MouvementBonus => 'Bonus '.($entite->getMontant() >= 0 ? '+' : '').PaiementService::format($entite->getMontant()).' € · '.$entite->getClient()?->getNomComplet(),
            $entite instanceof User => 'Utilisateur '.$entite->getNomComplet().' ('.$entite->getUsername().')',
            method_exists($entite, '__toString') => (string) $entite,
            default => (new \ReflectionClass($entite))->getShortName().(method_exists($entite, 'getId') ? ' #'.$entite->getId() : ''),
        };
    }

    /** @return array{0: ?int, 1: ?int} colis et client auxquels rattacher l'entrée */
    private function liens(object $entite): array
    {
        return match (true) {
            $entite instanceof Colis => [$entite->getId(), $entite->getClient()?->getId()],
            $entite instanceof Article, $entite instanceof Paiement => [$entite->getColis()?->getId(), $entite->getColis()?->getClient()?->getId()],
            $entite instanceof Client => [null, $entite->getId()],
            $entite instanceof Ramassage => [null, $entite->getClient()?->getId()],
            $entite instanceof MouvementBonus => [$entite->getColis()?->getId(), $entite->getClient()?->getId()],
            default => [null, null],
        };
    }
}
