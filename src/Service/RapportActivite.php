<?php

namespace App\Service;

use App\Entity\Colis;
use App\Entity\HistoriqueStatut;
use App\Entity\Notification;
use App\Entity\Paiement;
use App\Entity\Ramassage;
use App\Entity\User;
use App\Enum\ModePaiement;
use App\Enum\StatutColis;
use App\Enum\StatutRamassage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Rapports d'activité (jour, semaine, mois) envoyés aux administrateurs par e-mail, avec le détail en PDF :
 * colis enregistrés, colis remis, paiements reçus, ramassages, changements de statut.
 * Aucun envoi si la période n'a connu aucun mouvement.
 */
final class RapportActivite
{
    public const JOUR = 'jour';
    public const SEMAINE = 'semaine';
    public const MOIS = 'mois';

    private const MOIS_FR = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailerInterface $mailer,
        private readonly DocumentService $documents,
        #[Autowire('%app.nom%')] private readonly string $appNom,
        #[Autowire('%app.email_expediteur%')] private readonly string $emailExpediteur,
        #[Autowire('%env(default::RAPPORTS_EMAILS)%')] private readonly ?string $emailsSupplementaires = null,
    ) {
    }

    /**
     * Rapports à envoyer à la date $maintenant (tâche de minuit) : la veille, plus la semaine écoulée
     * le lundi et le mois écoulé le 1er.
     *
     * @return list<string> types de rapport
     */
    public static function rapportsDus(\DateTimeImmutable $maintenant): array
    {
        return [
            self::JOUR,
            ...('1' === $maintenant->format('N') ? [self::SEMAINE] : []),
            ...('1' === $maintenant->format('j') ? [self::MOIS] : []),
        ];
    }

    /**
     * Période complète qui précède $maintenant (hier, la semaine dernière, le mois dernier),
     * ou qui contient $date quand on la précise.
     *
     * @return array{type: string, debut: \DateTimeImmutable, fin: \DateTimeImmutable, libelle: string, cle: string}
     */
    public function periode(string $type, \DateTimeImmutable $maintenant, bool $contenant = false): array
    {
        $ref = $maintenant->setTime(0, 0);
        [$debut, $fin] = match ($type) {
            self::JOUR => $contenant ? [$ref, $ref->modify('+1 day')] : [$ref->modify('-1 day'), $ref],
            self::SEMAINE => $contenant
                ? [$ref->modify('monday this week'), $ref->modify('monday this week')->modify('+7 days')]
                : [$ref->modify('monday this week')->modify('-7 days'), $ref->modify('monday this week')],
            self::MOIS => $contenant
                ? [$ref->modify('first day of this month'), $ref->modify('first day of next month')]
                : [$ref->modify('first day of last month'), $ref->modify('first day of this month')],
            default => throw new \InvalidArgumentException('Type de rapport inconnu : '.$type),
        };
        $dernier = $fin->modify('-1 day');
        $libelle = match ($type) {
            self::JOUR => 'du '.$this->jour($debut),
            self::SEMAINE => 'de la semaine du '.$debut->format('d/m').' au '.$dernier->format('d/m/Y'),
            self::MOIS => 'de '.self::MOIS_FR[(int) $debut->format('n')].' '.$debut->format('Y'),
        };

        return ['type' => $type, 'debut' => $debut, 'fin' => $fin, 'libelle' => $libelle, 'cle' => $type.'-'.$debut->format('Y-m-d')];
    }

    /** Chiffres de la période [debut ; fin[. */
    public function calculer(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $entre = fn (string $classe, string $champ) => $this->em->createQueryBuilder()
            ->select('e')->from($classe, 'e')
            ->where("e.$champ >= :debut AND e.$champ < :fin")
            ->setParameter('debut', $debut)->setParameter('fin', $fin)
            ->orderBy("e.$champ", 'ASC');

        /** @var list<Colis> $enregistres */
        $enregistres = $entre(Colis::class, 'dateReception')->getQuery()->getResult();
        /** @var list<Colis> $remis */
        $remis = $entre(Colis::class, 'dateRecuperation')->getQuery()->getResult();
        /** @var list<Paiement> $paiements */
        $paiements = $entre(Paiement::class, 'date')->getQuery()->getResult();
        /** @var list<Ramassage> $ramassagesPrevus */
        $ramassagesPrevus = $entre(Ramassage::class, 'datePrevue')->getQuery()->getResult();
        $ramassagesCrees = (int) $entre(Ramassage::class, 'createdAt')->select('COUNT(e.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        /** @var list<HistoriqueStatut> $historique */
        $historique = $entre(HistoriqueStatut::class, 'date')->getQuery()->getResult();

        $parMode = [];
        foreach (ModePaiement::cases() as $mode) {
            $liste = array_filter($paiements, fn (Paiement $p) => $p->getMode() === $mode);
            if ($liste) {
                $parMode[] = ['libelle' => $mode->label(), 'nombre' => \count($liste), 'montant' => array_sum(array_map(fn (Paiement $p) => $p->getMontant(), $liste))];
            }
        }
        $parCaissier = [];
        foreach ($paiements as $p) {
            $nom = $p->getCaissier()?->getNomComplet() ?? '—';
            $parCaissier[$nom] ??= ['nom' => $nom, 'nombre' => 0, 'montant' => 0];
            ++$parCaissier[$nom]['nombre'];
            $parCaissier[$nom]['montant'] += $p->getMontant();
        }
        $parDestination = [];
        foreach ($enregistres as $c) {
            $d = $c->getExpedition()?->getDestination()?->ville() ?? 'Non affecté';
            $parDestination[$d] = ($parDestination[$d] ?? 0) + 1;
        }
        $statuts = [];
        foreach ($historique as $h) {
            if (StatutColis::EnPreparation !== $h->getStatut() && StatutColis::Recupere !== $h->getStatut()) {
                $statuts[$h->getStatut()->label()] = ($statuts[$h->getStatut()->label()] ?? 0) + 1;
            }
        }
        $ramassagesParStatut = [];
        foreach (StatutRamassage::cases() as $s) {
            $n = \count(array_filter($ramassagesPrevus, fn (Ramassage $r) => $r->getStatut() === $s));
            if ($n) {
                $ramassagesParStatut[] = ['libelle' => $s->label(), 'nombre' => $n];
            }
        }

        $total = fn (iterable $liste, callable $valeur) => array_sum(array_map($valeur, \is_array($liste) ? $liste : iterator_to_array($liste)));

        return [
            'enregistres' => $enregistres,
            'enregistresMontant' => $total($enregistres, fn (Colis $c) => $c->getMontantNet()),
            'enregistresPoids' => $total($enregistres, fn (Colis $c) => $c->getPoidsTotal()),
            'parDestination' => $parDestination,
            'remis' => $remis,
            'paiements' => $paiements,
            'paiementsTotal' => $total($paiements, fn (Paiement $p) => $p->getMontant()),
            'paiementsRemises' => $total($paiements, fn (Paiement $p) => $p->getRemise()),
            'parMode' => $parMode,
            'parCaissier' => array_values($parCaissier),
            'ramassagesPrevus' => $ramassagesPrevus,
            'ramassagesParStatut' => $ramassagesParStatut,
            'ramassagesCrees' => $ramassagesCrees,
            'statuts' => $statuts,
            'activite' => $enregistres || $remis || $paiements || $ramassagesPrevus || $ramassagesCrees || $statuts,
        ];
    }

    /** @return list<string> adresses des administrateurs actifs (+ RAPPORTS_EMAILS) */
    public function destinataires(): array
    {
        $admins = array_filter(
            $this->em->getRepository(User::class)->findBy(['actif' => true]),
            fn (User $u) => \in_array(User::ROLE_ADMIN, $u->getRoles(), true) && $u->getEmail(),
        );
        $emails = [...array_map(fn (User $u) => $u->getEmail(), $admins), ...explode(',', (string) $this->emailsSupplementaires)];

        return array_values(array_unique(array_filter(array_map(fn ($e) => mb_strtolower(trim($e)), $emails))));
    }

    /** PDF détaillé du rapport. */
    public function pdf(array $periode, array $chiffres): string
    {
        return $this->documents->pdf('pdf/rapport_activite.html.twig', ['periode' => $periode, 'r' => $chiffres, 'genereLe' => new \DateTimeImmutable()]);
    }

    /**
     * Envoie le rapport aux destinataires (un e-mail chacun, journalisé).
     *
     * @param list<string> $destinataires
     *
     * @return int nombre d'e-mails envoyés
     */
    public function envoyer(array $periode, array $chiffres, array $destinataires): int
    {
        $pdf = $this->pdf($periode, $chiffres);
        $sujet = $this->appNom.' · Rapport '.$periode['libelle'];
        $fichier = 'rapport-'.$periode['type'].'-'.$periode['debut']->format('Y-m-d').'.pdf';
        $envoyes = 0;
        foreach ($destinataires as $email) {
            $log = new Notification(Notification::CANAL_EMAIL, $email, $sujet);
            try {
                $this->mailer->send((new TemplatedEmail())
                    ->from(new Address($this->emailExpediteur, $this->appNom))
                    ->to($email)
                    ->subject($sujet)
                    ->htmlTemplate('email/rapport_activite.html.twig')
                    ->context(['periode' => $periode, 'r' => $chiffres, 'app_nom' => $this->appNom])
                    ->attach($pdf, $fichier, 'application/pdf'));
                $log->setSucces(true);
                ++$envoyes;
            } catch (\Throwable $e) {
                $log->setErreur($e->getMessage());
            }
            $this->em->persist($log);
        }
        $this->em->flush();

        return $envoyes;
    }

    private function jour(\DateTimeImmutable $d): string
    {
        $jours = [1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];

        return $jours[(int) $d->format('N')].' '.$d->format('j').' '.self::MOIS_FR[(int) $d->format('n')].' '.$d->format('Y');
    }
}
