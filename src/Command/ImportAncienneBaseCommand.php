<?php

namespace App\Command;

use App\Entity\Article;
use App\Entity\Client;
use App\Entity\Colis;
use App\Entity\Expedition;
use App\Entity\HistoriqueStatut;
use App\Entity\MouvementBonus;
use App\Entity\Paiement;
use App\Entity\Ramassage;
use App\Entity\TypeMarchandise;
use App\Entity\User;
use App\Enum\Destination;
use App\Enum\ModePaiement;
use App\Enum\ModeTransport;
use App\Enum\StatutColis;
use App\Enum\StatutExpedition;
use App\Enum\StatutRamassage;
use App\Enum\TypeExpedition;
use App\Service\Numerotation;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Reprend les données de l'ancienne application GESCO (base MySQL) dans la nouvelle.
 *
 *   php bin/console app:import-ancienne-base "mysql://user:mdp@127.0.0.1:3306/ancienne_base" --photos=/chemin/ancien/public/uploads
 *
 * La base cible doit être vide (migrations exécutées, aucun client ni colis).
 * Aucun SMS ni e-mail n'est envoyé pendant l'import.
 */
#[AsCommand(name: 'app:import-ancienne-base', description: "Importe les données de l'ancienne application GESCO")]
final class ImportAncienneBaseCommand extends Command
{
    private SymfonyStyle $io;
    private Connection $ancienne;
    /** @var array<string, array<int, object>> anciens id → nouvelles entités */
    private array $map = ['user' => [], 'client' => [], 'expedition' => [], 'colis' => []];
    /** @var list<string> */
    private array $avertissements = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Numerotation $numerotation,
        #[Autowire('%app.uploads_dir%')] private readonly string $uploadsDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('dsn', InputArgument::REQUIRED, "Connexion à l'ancienne base, ex. mysql://user:mdp@127.0.0.1:3306/gesco")
            ->addOption('photos', null, InputOption::VALUE_REQUIRED, "Dossier des anciennes photos (ancien public/uploads) à copier")
            ->addOption('dry-run', null, InputOption::VALUE_NONE, "Simule l'import sans rien enregistrer");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        if ($this->em->getRepository(Colis::class)->count([]) || $this->em->getRepository(Client::class)->count([])) {
            $this->io->error('La nouvelle base contient déjà des clients ou des colis. Importez dans une base vide.');

            return Command::FAILURE;
        }

        $params = (new DsnParser(['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql']))->parse($input->getArgument('dsn'));
        $params['charset'] ??= 'utf8mb4';
        $this->ancienne = DriverManager::getConnection($params);

        $this->em->getConnection()->beginTransaction();
        try {
            $this->importerUtilisateurs();
            $this->importerTypes();
            $this->importerClients();
            $this->importerExpeditions();
            $this->importerColis($input->getOption('photos'));
            $this->importerPaiements();
            $this->finaliserColis();
            $this->importerRamassages();
            $this->em->flush();

            if ($dryRun) {
                $this->em->getConnection()->rollBack();
                $this->io->note('Simulation : rien n\'a été enregistré.');
            } else {
                $this->em->getConnection()->commit();
            }
        } catch (\Throwable $e) {
            $this->em->getConnection()->rollBack();
            $this->io->error('Import annulé : '.$e->getMessage());

            return Command::FAILURE;
        }

        $this->io->table(['Élément', 'Importés'], array_map(fn ($k) => [$k, \count($this->map[$k])], array_keys($this->map)));
        foreach (array_slice($this->avertissements, 0, 50) as $a) {
            $this->io->writeln(' <comment>!</comment> '.$a);
        }
        if (\count($this->avertissements) > 50) {
            $this->io->writeln(' … et '.(\count($this->avertissements) - 50).' autres avertissements.');
        }
        $this->io->success($dryRun ? 'Simulation terminée.' : 'Import terminé.');

        return Command::SUCCESS;
    }

    private function importerUtilisateurs(): void
    {
        $existants = [];
        foreach ($this->em->getRepository(User::class)->findAll() as $u) {
            $existants[$u->getUsername()] = $u;
        }

        foreach ($this->ancienne->fetchAllAssociative('SELECT * FROM `user` ORDER BY id') as $r) {
            $roles = json_decode((string) $r['roles'], true) ?: [];
            $role = match (true) {
                \in_array('ROLE_ADMIN', $roles, true) => User::ROLE_ADMIN,
                \in_array('ROLE_REPRESENTANT', $roles, true) => User::ROLE_REPRESENTANT,
                \in_array('ROLE_RAMASSAGE', $roles, true) => User::ROLE_RAMASSAGE,
                default => null,
            };
            if (!$role) {
                continue; // Comptes « client » de l'ancienne application : non repris.
            }
            $login = mb_strtolower(trim((string) $r['usename']));
            $user = $existants[$login] ?? (new User())->setUsername($login);
            $user->setPrenom($r['prenom'] ?: '—')->setNom($r['nom'] ?: '—')
                ->setRolePrincipal($role)
                ->setActif((bool) $r['statut'])
                ->setPassword($r['password']); // Le hash est conservé : mêmes identifiants qu'avant.
            if (User::ROLE_REPRESENTANT === $role) {
                $user->setPays(\in_array('ROLE_SENEGAL', $roles, true) ? Destination::Senegal : Destination::Mali);
            }
            $this->em->persist($user);
            $this->map['user'][(int) $r['id']] = $user;
        }
        $this->em->flush();
    }

    private function importerTypes(): void
    {
        $existants = array_map(fn (TypeMarchandise $t) => mb_strtolower($t->getNom()), $this->em->getRepository(TypeMarchandise::class)->findAll());
        foreach ($this->ancienne->fetchFirstColumn('SELECT DISTINCT TRIM(nom) FROM type_marchandises WHERE nom <> \'\'') as $nom) {
            if (!\in_array(mb_strtolower($nom), $existants, true)) {
                $this->em->persist((new TypeMarchandise())->setNom($nom));
                $existants[] = mb_strtolower($nom);
            }
        }
    }

    private function importerClients(): void
    {
        foreach ($this->ancienne->fetchAllAssociative('SELECT * FROM clients ORDER BY id') as $r) {
            $client = (new Client())
                ->setNom($r['nom'] ?: '—')->setPrenom($r['prenom'] ?: '—')
                ->setTelephone($r['telephone'] ?: '—')
                ->setEmail($r['email'] ?: null)
                ->setAdresse($r['adresse'] ?: null)->setVille($r['ville'] ?: null)->setCodePostal($r['code_postal'] ?: null)
                ->setPays(trim((string) $r['pays']) ?: 'France');
            $this->forcer($client, 'createdAt', $this->date($r['created_at']) ?? new \DateTimeImmutable());
            $solde = max(0, (int) $r['sahel']) * 100;
            if ($solde > 0) {
                $client->setSoldeBonus($solde);
                $mouvement = (new MouvementBonus())->setMontant($solde)->setLibelle("Solde repris de l'ancienne application");
                $client->addMouvementBonus($mouvement);
                $this->em->persist($mouvement);
            }
            $this->em->persist($client);
            $this->map['client'][(int) $r['id']] = $client;
        }
        $this->em->flush();
    }

    private function importerExpeditions(): void
    {
        $numeros = [];
        foreach ($this->ancienne->fetchAllAssociative('SELECT * FROM expeditions ORDER BY id') as $r) {
            $numero = trim((string) $r['numero_expeditions']) ?: 'ANC-'.$r['id'];
            if (isset($numeros[$numero])) {
                $numero .= '-'.$r['id'];
            }
            $numeros[$numero] = true;

            $expedition = (new Expedition())
                ->setNumero($numero)
                ->setModeTransport(str_contains($this->normaliser($r['mode_transport']), 'mari') ? ModeTransport::Maritime : ModeTransport::Aerien)
                ->setDestination(str_contains($this->normaliser($r['destinations']), 'senegal') ? Destination::Senegal : Destination::Mali)
                ->setDateDepart($this->date($r['date_expeditions']) ?? new \DateTimeImmutable('2000-01-01'))
                ->setDateArrivee($this->date($r['date_arrive']))
                ->setStatut($this->statutExpedition($r['statut']));
            $this->em->persist($expedition);
            $this->map['expedition'][(int) $r['id']] = $expedition;
        }
        $this->em->flush();
    }

    private function importerColis(?string $dossierPhotos): void
    {
        $details = [];
        foreach ($this->ancienne->fetchAllAssociative('SELECT * FROM base_colis_details ORDER BY id') as $d) {
            $details[(int) $d['colis_id']][] = $d;
        }

        $codes = [];
        foreach ($this->ancienne->fetchAllAssociative('SELECT * FROM base_colis ORDER BY id') as $i => $r) {
            $client = $this->map['client'][(int) $r['clients_id']] ?? null;
            if (!$client) {
                $this->avertissements[] = "Colis {$r['numero_suivi']} ignoré : client {$r['clients_id']} introuvable.";
                continue;
            }
            $code = mb_strtoupper(trim((string) $r['code']));
            if ('' === $code || isset($codes[$code]) || mb_strlen($code) > 12) {
                $code = $this->numerotation->codeRetrait();
            }
            $codes[$code] = true;

            $reception = $this->date($r['date_receptions']) ?? new \DateTimeImmutable();
            $colis = (new Colis())
                ->setNumeroSuivi(trim((string) $r['numero_suivi']) ?: 'ANC-'.$r['id'])
                ->setCodeRetrait($code)
                ->setToken($this->numerotation->token())
                ->setClient($client)
                ->setDestinataireNom(trim((string) $r['destinateurs_nom']) ?: '—')
                ->setDestinataireTelephone(trim((string) $r['destinateurs_telephones']) ?: '—')
                ->setExpedition($this->map['expedition'][(int) $r['expeditions_id']] ?? null)
                ->setAgent($this->map['user'][(int) $r['expediteurs_id']] ?? null)
                ->setTypeExpedition(str_contains($this->normaliser($r['type_expeditions']), 'express') ? TypeExpedition::Express : TypeExpedition::Standard)
                ->setStatut($this->statutColis($r['statut']))
                ->setDateReception($reception)
                ->setDateRecuperation($this->date($r['date_recuperations']));

            foreach ($details[(int) $r['id']] ?? [] as $d) {
                $article = (new Article())
                    ->setTypeMarchandise(trim((string) $d['type_marchandises']) ?: 'Divers')
                    ->setPoidsVolume(max(0, (float) str_replace(',', '.', (string) $d['poids_volume'])))
                    ->setPrix(max(0, (int) ($d['prix_poids_volume'] ?? $d['montant_total'] ?? 0)) * 100)
                    ->setObservations($d['observations'] ?: null)
                    ->setPhoto($this->copierPhoto($d['photo'] ?? null, $dossierPhotos));
                $colis->addArticle($article);
            }

            $frais = (int) $r['frais_expeditions'] > 0 ? (int) $r['frais_expeditions'] * 100 : $colis->getTotalArticles();
            $colis->setFrais($frais)->setRemise(max(0, (int) $r['remises']) * 100);
            $colis->addHistorique((new HistoriqueStatut($colis->getStatut(), null, 'Repris de l\'ancienne application'))
                ->setDate($this->date($r['date_update']) ?? $reception));

            $this->em->persist($colis);
            $this->map['colis'][(int) $r['id']] = $colis;
            if (0 === $i % 200) {
                $this->em->flush();
            }
        }
        $this->em->flush();
    }

    private function importerPaiements(): void
    {
        foreach ($this->ancienne->fetchAllAssociative('SELECT * FROM paiements ORDER BY id') as $r) {
            $colis = $this->map['colis'][(int) $r['colis_id']] ?? null;
            if (!$colis) {
                $this->avertissements[] = "Paiement {$r['id']} ignoré : colis {$r['colis_id']} introuvable.";
                continue;
            }
            $paiement = (new Paiement())
                ->setColis($colis)
                ->setMontant(max(0, (int) $r['montants']) * 100)
                ->setRemise(max(0, (int) $r['remises']) * 100)
                ->setMode($this->modePaiement($r['mode_paiement']))
                ->setDate($this->date($r['date_paiements']) ?? $colis->getDateReception())
                ->setCaissier($this->map['user'][(int) $r['caissier_id']] ?? null);
            $colis->getPaiements()->add($paiement);
            $this->em->persist($paiement);
        }
    }

    /** Montants payés et remises recalculés à partir des paiements. */
    private function finaliserColis(): void
    {
        foreach ($this->map['colis'] as $colis) {
            $paye = array_sum($colis->getPaiements()->map(fn (Paiement $p) => $p->getMontant())->toArray());
            $remisesPaiements = array_sum($colis->getPaiements()->map(fn (Paiement $p) => $p->getRemise())->toArray());
            // L'ancienne application enregistrait les remises tantôt sur le colis, tantôt sur le paiement.
            $colis->setMontantPaye($paye)->setRemise(max($colis->getRemise(), $remisesPaiements));
            if ($colis->getRemise() + $paye > $colis->getFrais()) {
                $this->avertissements[] = \sprintf('Colis %s : payé + remises (%s) > tarif (%s), tarif ajusté.',
                    $colis->getNumeroSuivi(), ($colis->getRemise() + $paye) / 100, $colis->getFrais() / 100);
                $colis->setFrais($colis->getRemise() + $paye);
            }
            $colis->rafraichirStatutPaiement();
        }
    }

    private function importerRamassages(): void
    {
        foreach ($this->ancienne->fetchAllAssociative('SELECT * FROM programmes ORDER BY id') as $r) {
            $client = $this->map['client'][(int) $r['clients_id']] ?? null;
            if (!$client || !$r['date_ramassages']) {
                continue;
            }
            $statut = $this->normaliser($r['statut']);
            $this->em->persist((new Ramassage())
                ->setClient($client)
                ->setDatePrevue($this->date($r['date_ramassages']))
                ->setCommentaire($r['commentaires'] ?: null)
                ->setStatut(match (true) {
                    str_contains($statut, 'annul') => StatutRamassage::Annule,
                    str_contains($statut, 'recup'), str_contains($statut, 'effect') => StatutRamassage::Effectue,
                    default => StatutRamassage::EnAttente,
                }));
        }
    }

    private function statutColis(?string $ancien): StatutColis
    {
        $s = $this->normaliser($ancien);

        return match (true) {
            str_contains($s, 'non recup') => StatutColis::Arrive,
            str_contains($s, 'recup'), str_contains($s, 'livr') => StatutColis::Recupere,
            str_contains($s, 'arriv') => StatutColis::Arrive,
            str_contains($s, 'douane') => StatutColis::RetenuDouane,
            str_contains($s, 'transit') => StatutColis::EnTransit,
            str_contains($s, 'expedi') => StatutColis::Expedie,
            default => StatutColis::EnPreparation,
        };
    }

    private function statutExpedition(?string $ancien): StatutExpedition
    {
        $s = $this->normaliser($ancien);

        return match (true) {
            str_contains($s, 'clot'), str_contains($s, 'termin'), str_contains($s, 'livr') => StatutExpedition::Cloturee,
            str_contains($s, 'arriv') => StatutExpedition::Arrivee,
            str_contains($s, 'douane') => StatutExpedition::RetenueDouane,
            str_contains($s, 'transit') => StatutExpedition::EnTransit,
            str_contains($s, 'expedi') => StatutExpedition::Expediee,
            default => StatutExpedition::EnPreparation,
        };
    }

    private function modePaiement(?string $ancien): ModePaiement
    {
        $s = $this->normaliser($ancien);

        return match (true) {
            str_contains($s, 'bonus'), str_contains($s, 'sahel') => ModePaiement::Bonus,
            str_contains($s, 'orange'), 'om' === $s => ModePaiement::OrangeMoney,
            str_contains($s, 'wave') => ModePaiement::Wave,
            str_contains($s, 'carte'), str_contains($s, 'cb') => ModePaiement::Carte,
            str_contains($s, 'vir') => ModePaiement::Virement,
            default => ModePaiement::Especes,
        };
    }

    private function normaliser(?string $texte): string
    {
        return trim((string) (new AsciiSlugger())->slug((string) $texte, ' ')->lower());
    }

    private function date(?string $valeur): ?\DateTimeImmutable
    {
        if (!$valeur || str_starts_with($valeur, '0000')) {
            return null;
        }
        try {
            return new \DateTimeImmutable($valeur);
        } catch (\Exception) {
            return null;
        }
    }

    private function copierPhoto(?string $nom, ?string $dossier): ?string
    {
        if (!$nom || !$dossier) {
            return null;
        }
        $source = rtrim($dossier, '/').'/'.basename($nom);
        if (!is_file($source)) {
            $this->avertissements[] = "Photo introuvable : $nom";

            return null;
        }
        $relatif = 'import/'.basename($nom);
        $cible = $this->uploadsDir.'/photos/'.$relatif;
        if (!is_dir(\dirname($cible))) {
            mkdir(\dirname($cible), 0775, true);
        }
        copy($source, $cible);

        return $relatif;
    }

    /** Renseigne une propriété sans setter public (dates de création historiques). */
    private function forcer(object $objet, string $propriete, mixed $valeur): void
    {
        (new \ReflectionProperty($objet, $propriete))->setValue($objet, $valeur);
    }
}
