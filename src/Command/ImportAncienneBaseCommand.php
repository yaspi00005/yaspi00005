<?php

namespace App\Command;

use App\Entity\Article;
use App\Entity\Client;
use App\Entity\Colis;
use App\Entity\Journal;
use App\Journal\Journaliseur;
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
 * Reprend les données de l'ancienne application SOTRAMA (base MySQL) dans la nouvelle.
 *
 *   php bin/console app:import-ancienne-base "mysql://user:mdp@127.0.0.1:3306/ancienne_base" --photos=/chemin/ancien/public/uploads
 *
 * La base cible doit être vide (migrations exécutées, aucun client ni colis).
 * Aucun SMS ni e-mail n'est envoyé pendant l'import.
 */
#[AsCommand(name: 'app:import-ancienne-base', description: "Importe les données de l'ancienne application SOTRAMA")]
final class ImportAncienneBaseCommand extends Command
{
    private SymfonyStyle $io;
    private Connection $ancienne;
    /** @var array<string, array<int, object>> anciens id → nouvelles entités */
    private array $map = ['user' => [], 'client' => [], 'expedition' => [], 'colis' => []];
    /** @var list<string> */
    private array $avertissements = [];
    private bool $simulation = false;
    /** @var array<string, list<string>>|null nom de fichier (minuscules) → chemins, pour les anciennes photos */
    private ?array $indexPhotos = null;
    private int $photosTrouvees = 0;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Numerotation $numerotation,
        #[Autowire('%app.uploads_dir%')] private readonly string $uploadsDir,
        #[Autowire('%kernel.logs_dir%')] private readonly string $dossierLogs,
        private readonly Journaliseur $journal,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('dsn', InputArgument::REQUIRED, "Connexion à l'ancienne base, ex. mysql://user:mdp@127.0.0.1:3306/ancienne_base")
            ->addOption('photos', null, InputOption::VALUE_REQUIRED, "Dossier des anciennes photos (ancien public/uploads) à copier")
            ->addOption('dry-run', null, InputOption::VALUE_NONE, "Simule l'import sans rien enregistrer");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $dryRun = $this->simulation = (bool) $input->getOption('dry-run');
        // Grandes photos à réduire : GD a besoin de mémoire (une photo 12 Mpx ≈ 50 Mo).
        if ((int) ini_get('memory_limit') > 0 && (int) ini_get('memory_limit') < 1024) {
            @ini_set('memory_limit', '1024M');
        }

        if ($this->em->getRepository(Colis::class)->count([]) || $this->em->getRepository(Client::class)->count([])) {
            $this->io->error('La nouvelle base contient déjà des clients ou des colis. Importez dans une base vide.');

            return Command::FAILURE;
        }

        $params = (new DsnParser(['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql']))->parse($input->getArgument('dsn'));
        $params['charset'] ??= 'utf8mb4';
        $this->ancienne = DriverManager::getConnection($params);

        // Pas une ligne de journal par élément importé : un résumé est écrit à la fin.
        $this->journal->suspendre();
        $this->em->getConnection()->beginTransaction();
        try {
            $this->importerUtilisateurs();
            $this->importerTypes();
            $this->importerClients();
            $this->importerExpeditions();
            $this->importerColis($input->getOption('photos'));
            $this->importerPaiements();
            $this->finaliserColis();
            $this->importerBonus();
            $this->importerRamassages();
            $this->em->flush();

            if ($dryRun) {
                $this->em->getConnection()->rollBack();
                $this->io->note('Simulation : rien n\'a été enregistré.');
            } else {
                $this->em->getConnection()->commit();
                $this->journal->suspendre(false);
                $this->journal->ecrire($this->em, Journal::CREATION, \sprintf(
                    "Import de l'ancienne base : %d utilisateurs, %d clients, %d expéditions, %d colis",
                    \count($this->map['user']), \count($this->map['client']), \count($this->map['expedition']), \count($this->map['colis']),
                ), 'Import');
            }
        } catch (\Throwable $e) {
            $this->em->getConnection()->rollBack();
            $this->io->error('Import annulé : '.$e->getMessage());

            return Command::FAILURE;
        }

        $this->io->table(['Élément', 'Importés'], [
            ...array_map(fn ($k) => [$k, \count($this->map[$k])], array_keys($this->map)),
            ...($input->getOption('photos') ? [['photos', $this->photosTrouvees]] : []),
        ]);
        foreach (array_slice($this->avertissements, 0, 50) as $a) {
            $this->io->writeln(' <comment>!</comment> '.$a);
        }
        if (\count($this->avertissements) > 50) {
            $this->io->writeln(' … et '.(\count($this->avertissements) - 50).' autres avertissements.');
        }
        if ($this->avertissements) {
            $fichier = $this->dossierLogs.'/import-ancienne-base.txt';
            file_put_contents($fichier, implode(\PHP_EOL, $this->avertissements).\PHP_EOL);
            $this->io->note('Liste complète des '.\count($this->avertissements).' avertissements : '.$fichier);
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
                // Les agents de l'ancienne application n'avaient souvent que ROLE_USER.
                default => User::ROLE_RAMASSAGE,
            };
            $login = mb_strtolower(trim((string) $r['usename']));
            if (User::ROLE_RAMASSAGE === $role && !\in_array('ROLE_RAMASSAGE', $roles, true)) {
                $this->avertissements[] = "Compte « $login » ({$r['prenom']} {$r['nom']}) importé comme agent de ramassage : vérifiez son rôle dans Administration → Utilisateurs.";
            }
            $user = $existants[$login] ?? (new User())->setUsername($login);
            $user->setPrenom($r['prenom'] ?: '—')->setNom($r['nom'] ?: '—')
                ->setRolePrincipal($role)
                ->setActif((bool) $r['statut'])
                ->setPassword($r['password']); // Le hash est conservé : mêmes identifiants qu'avant.
            if (User::ROLE_REPRESENTANT === $role) {
                $user->setPays(\in_array('ROLE_SENEGAL', $roles, true) ? Destination::Senegal : Destination::Mali);
            }
            $this->enregistrer($user);
            $this->map['user'][(int) $r['id']] = $user;
        }
        $this->em->flush();
    }

    private function importerTypes(): void
    {
        $existants = array_map(fn (TypeMarchandise $t) => mb_strtolower($t->getNom()), $this->em->getRepository(TypeMarchandise::class)->findAll());
        foreach ($this->ancienne->fetchFirstColumn('SELECT DISTINCT TRIM(nom) FROM type_marchandises WHERE nom <> \'\'') as $nom) {
            $nom = mb_substr($nom, 0, 100);
            if (!\in_array(mb_strtolower($nom), $existants, true)) {
                $this->enregistrer((new TypeMarchandise())->setNom($nom));
                $existants[] = mb_strtolower($nom);
            }
        }
    }

    private function importerClients(): void
    {
        foreach ($this->ancienne->fetchAllAssociative('SELECT * FROM clients ORDER BY id') as $r) {
            $client = (new Client())
                ->setNom($r['nom'] ?: '—')->setPrenom($r['prenom'] ?: '—')
                ->setTelephone($this->premierTelephone($r['telephone']) ?: '—')
                ->setNotes($this->autresTelephones($r['telephone']))
                ->setEmail($r['email'] ?: null)
                ->setAdresse($r['adresse'] ?: null)->setVille($r['ville'] ?: null)->setCodePostal($r['code_postal'] ?: null)
                ->setPays(trim((string) $r['pays']) ?: 'France');
            $this->forcer($client, 'createdAt', $this->date($r['created_at']) ?? new \DateTimeImmutable());
            $solde = max(0, (int) $r['sahel']) * 100;
            if ($solde > 0) {
                $client->setSoldeBonus($solde);
                $mouvement = (new MouvementBonus())->setMontant($solde)->setLibelle("Solde repris de l'ancienne application");
                $client->addMouvementBonus($mouvement);
                $this->enregistrer($mouvement);
            }
            $this->enregistrer($client);
            $this->map['client'][(int) $r['id']] = $client;
        }
        $this->em->flush();
    }

    private function importerExpeditions(): void
    {
        $numeros = [];
        foreach ($this->ancienne->fetchAllAssociative('SELECT * FROM expeditions ORDER BY id') as $r) {
            $numero = $this->numeroUnique(trim((string) $r['numero_expeditions']) ?: 'ANC-'.$r['id'], $numeros, 'Expédition');

            $expedition = (new Expedition())
                ->setNumero($numero)
                ->setModeTransport(str_contains($this->normaliser($r['mode_transport']), 'mari') ? ModeTransport::Maritime : ModeTransport::Aerien)
                ->setDestination(preg_match('/senegal|dakar|dkr/', $this->normaliser($r['destinations'])) ? Destination::Senegal : Destination::Mali)
                ->setDateDepart($this->date($r['date_expeditions']) ?? new \DateTimeImmutable('2000-01-01'))
                ->setDateArrivee($this->date($r['date_arrive']))
                ->setStatut($this->statutExpedition($r['statut']));
            $this->enregistrer($expedition);
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
        $numeros = [];
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
                ->setNumeroSuivi($this->numeroUnique(trim((string) $r['numero_suivi']) ?: 'ANC-'.$r['id'], $numeros, 'Colis'))
                ->setCodeRetrait($code)
                ->setToken($this->numerotation->token())
                ->setClient($client)
                ->setDestinataireNom(trim((string) $r['destinateurs_nom']) ?: '—')
                ->setDestinataireTelephone($this->premierTelephone($r['destinateurs_telephones']) ?: '—')
                ->setObservations($this->autresTelephones($r['destinateurs_telephones']))
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
                    ->setPhoto($this->copierPhoto($d['photo'] ?? null, $dossierPhotos, $reception));
                $colis->addArticle($article);
            }

            $frais = (int) $r['frais_expeditions'] > 0 ? (int) $r['frais_expeditions'] * 100 : $colis->getTotalArticles();
            $colis->setFrais($frais)->setRemise(max(0, (int) $r['remises']) * 100);
            $colis->addHistorique((new HistoriqueStatut($colis->getStatut(), null, 'Repris de l\'ancienne application'))
                ->setDate($this->date($r['date_update']) ?? $reception));

            $this->enregistrer($colis);
            $this->map['colis'][(int) $r['id']] = $colis;
            if (0 === $i % 200) {
                $this->em->flush();
            }
        }
        $this->em->flush();
    }

    /**
     * L'ancienne application laissait enregistrer des paiements en trop : double clic (deux lignes identiques
     * à la même seconde) ou nouvel encaissement à la remise d'un colis déjà soldé. Ces lignes sont écartées
     * (et listées dans les avertissements) pour ne pas gonfler le chiffre d'affaires ; les lignes vides aussi.
     */
    private function importerPaiements(): void
    {
        /** @var array<int, array{paye: int, remises: int, derniere: ?string}> $cumul */
        $cumul = [];
        foreach ($this->ancienne->fetchAllAssociative('SELECT * FROM paiements ORDER BY colis_id, date_paiements, id') as $r) {
            $colis = $this->map['colis'][(int) $r['colis_id']] ?? null;
            if (!$colis) {
                $this->avertissements[] = "Paiement {$r['id']} ignoré : colis {$r['colis_id']} introuvable.";
                continue;
            }
            $montant = max(0, (int) $r['montants']) * 100;
            $remise = max(0, (int) $r['remises']) * 100;
            if (0 === $montant && 0 === $remise) {
                continue;
            }
            $c = &$cumul[(int) $r['colis_id']];
            $c ??= ['paye' => 0, 'remises' => 0, 'derniere' => null];
            $signature = $montant.'|'.$remise.'|'.$r['mode_paiement'].'|'.$r['date_paiements'];
            $details = \sprintf('paiement %d de %s € du %s', $r['id'], $montant / 100, $r['date_paiements']);
            if ($signature === $c['derniere']) {
                $this->avertissements[] = "Colis {$colis->getNumeroSuivi()} : $details ignoré, doublon exact du précédent.";
                continue;
            }
            // Une ligne de remise seule (bonus, geste commercial) est toujours gardée.
            $remiseAcquise = max($colis->getRemise(), $c['remises']);
            if ($montant > 0 && $colis->getFrais() > 0 && $c['paye'] + $remiseAcquise >= $colis->getFrais()) {
                $this->avertissements[] = \sprintf('Colis %s : %s ignoré, le colis était déjà soldé (%s € payés%s pour un tarif de %s €).',
                    $colis->getNumeroSuivi(), $details, $c['paye'] / 100,
                    $remiseAcquise > 0 ? \sprintf(' + %s € de remise', $remiseAcquise / 100) : '', $colis->getFrais() / 100);
                continue;
            }
            $c['paye'] += $montant;
            $c['remises'] += $remise;
            $c['derniere'] = $signature;
            unset($c);

            $paiement = (new Paiement())
                ->setColis($colis)
                ->setMontant($montant)
                ->setRemise($remise)
                ->setMode($this->modePaiement($r['mode_paiement']))
                ->setDate($this->date($r['date_paiements']) ?? $colis->getDateReception())
                ->setCaissier($this->map['user'][(int) $r['caissier_id']] ?? null);
            $colis->getPaiements()->add($paiement);
            $this->enregistrer($paiement);
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
                // Le client a payé plus que le tarif moins la remise : la remise n'a pas été accordée
                // (ou pas en entier). Elle est réduite ; le tarif ne bouge que si le payé seul le dépasse.
                $remise = max(0, $colis->getFrais() - $paye);
                $this->avertissements[] = \sprintf('Colis %s : %s € payés + %s € de remise > tarif de %s €, remise ramenée à %s €%s.',
                    $colis->getNumeroSuivi(), $paye / 100, $colis->getRemise() / 100, $colis->getFrais() / 100, $remise / 100,
                    $paye > $colis->getFrais() ? \sprintf(' et tarif porté à %s €', $paye / 100) : '');
                $colis->setRemise($remise)->setFrais(max($colis->getFrais(), $paye));
                foreach ($colis->getPaiements() as $p) {
                    $p->setRemise(min($p->getRemise(), $remise));
                    $remise -= $p->getRemise();
                }
            }
            $colis->rafraichirStatutPaiement();
        }
    }

    /**
     * Les lignes de « bases_sahel » deviennent les bonus gagnés par colis. Le solde du client reste celui
     * de l'ancienne application (clients.sahel) : l'écart éventuel est inscrit comme une ligne d'ajustement.
     */
    private function importerBonus(): void
    {
        $gains = [];
        foreach ($this->ancienne->fetchAllAssociative('SELECT * FROM bases_sahel ORDER BY id') as $r) {
            $colis = $this->map['colis'][(int) $r['colis_id']] ?? null;
            $montant = max(0, (int) $r['sahel']) * 100;
            if (!$colis || 0 === $montant) {
                continue;
            }
            $client = $colis->getClient();
            $mouvement = (new MouvementBonus())->setMontant($montant)->setGainColis(true)->setColis($colis)
                ->setLibelle('Bonus fidélité — colis '.$colis->getNumeroSuivi())
                ->setCreatedAt($this->date($r['date_operations']) ?? $colis->getDateReception());
            $client->addMouvementBonus($mouvement);
            $this->enregistrer($mouvement);
            $gains[spl_object_id($client)] = [$client, ($gains[spl_object_id($client)][1] ?? 0) + $montant];
        }

        foreach ($gains as [$client, $total]) {
            // Le mouvement « Solde repris » créé avec le client est remplacé par l'ajustement.
            foreach ($client->getMouvementsBonus() as $m) {
                if (!$m->isGainColis() && str_starts_with($m->getLibelle(), 'Solde repris')) {
                    $client->getMouvementsBonus()->removeElement($m);
                    $this->em->remove($m);
                }
            }
            $ecart = $client->getSoldeBonus() - $total;
            if (0 !== $ecart) {
                $ajustement = (new MouvementBonus())->setMontant($ecart)
                    ->setLibelle($ecart < 0 ? "Bonus déjà utilisé (ancienne application)" : "Ajustement repris de l'ancienne application");
                $client->addMouvementBonus($ajustement);
                $this->enregistrer($ajustement);
            }
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
            $this->enregistrer((new Ramassage())
                ->setClient($client)
                ->setDatePrevue($this->date($r['date_ramassages']))
                ->setCommentaire($r['commentaires'] ?: null)
                ->setStatut(match (true) {
                    str_contains($statut, 'annul') => StatutRamassage::Annule,
                    str_contains($statut, 'recup'), str_contains($statut, 'effect') => StatutRamassage::Effectue,
                    str_contains($statut, 'confirm') => StatutRamassage::Confirme,
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
            // « En trasit » : faute de frappe présente dans l'ancienne application.
            str_contains($s, 'transit'), str_contains($s, 'trasit') => StatutColis::EnTransit,
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
            str_contains($s, 'transit'), str_contains($s, 'trasit') => StatutExpedition::EnTransit,
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

    /** Premier numéro d'un champ qui en contient parfois plusieurs (« 76 11 22 33 / 66 44 55 77 »). */
    private function premierTelephone(?string $valeur): string
    {
        $valeur = trim((string) $valeur);
        if (mb_strlen($valeur) <= 30) {
            return $valeur;
        }

        return trim(preg_split('#\s*(?:/|,|;|\||\bet\b|\bou\b)\s*#iu', $valeur)[0]);
    }

    /** Le champ complet, gardé en note quand il contenait plusieurs numéros. */
    private function autresTelephones(?string $valeur): ?string
    {
        $valeur = trim((string) $valeur);

        return mb_strlen($valeur) > 30 ? 'Téléphones (ancienne application) : '.$valeur : null;
    }

    /**
     * Enregistre une entité en coupant les textes trop longs pour leur colonne (l'ancienne base
     * acceptait 255 caractères ou plus là où la nouvelle en prévoit moins), avec un avertissement.
     */
    private function enregistrer(object $entite): void
    {
        $this->couperTextes($entite);
        if ($entite instanceof Colis) {
            array_map($this->couperTextes(...), [...$entite->getArticles()->toArray(), ...$entite->getHistorique()->toArray()]);
        }
        $this->em->persist($entite);
    }

    private function couperTextes(object $entite): void
    {
        $meta = $this->em->getClassMetadata($entite::class);
        foreach ($meta->getFieldNames() as $champ) {
            $mapping = $meta->getFieldMapping($champ);
            $longueur = $mapping->length ?? null;
            if ('string' !== $mapping->type || !$longueur || isset($mapping->enumType)) {
                continue;
            }
            $valeur = $meta->getFieldValue($entite, $champ);
            if (\is_string($valeur) && mb_strlen($valeur) > $longueur) {
                $meta->setFieldValue($entite, $champ, rtrim(mb_substr($valeur, 0, $longueur)));
                $nomCourt = (new \ReflectionClass($entite))->getShortName();
                $this->avertissements[] = "$nomCourt, champ « $champ » trop long ($longueur caractères max) : « ".mb_substr($valeur, 0, 60).(mb_strlen($valeur) > 60 ? '…' : '').' » a été raccourci.';
            }
        }
    }

    /**
     * Numéro unique (l'ancienne application acceptait les doublons) : le 2e reçoit « -B », le 3e « -C »…
     * La comparaison ignore la casse et les espaces, comme l'index unique de MySQL.
     *
     * @param array<string, true> $dejaVus
     */
    private function numeroUnique(string $numero, array &$dejaVus, string $quoi): string
    {
        $numero = mb_substr(preg_replace('/\s+/', ' ', $numero), 0, 26);
        $candidat = $numero;
        for ($i = 1; isset($dejaVus[mb_strtolower($candidat)]); ++$i) {
            $candidat = $numero.'-'.($i < 26 ? \chr(65 + $i) : $i);
        }
        if ($candidat !== $numero) {
            $this->avertissements[] = "$quoi $numero en double dans l'ancienne base : importé sous le numéro $candidat.";
        }
        $dejaVus[mb_strtolower($candidat)] = true;

        return $candidat;
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

    /**
     * Copie une photo de l'ancienne application. Le fichier est cherché par son nom dans tout le dossier
     * (sous-dossiers compris, majuscules ignorées, autre extension acceptée : .JPG, .jpeg, .png…).
     * Les grandes photos sont réduites à 1600 px (JPEG) et remises à l'endroit (orientation EXIF).
     * En simulation, rien n'est copié : on vérifie seulement que la photo existe.
     */
    private function copierPhoto(?string $nom, ?string $dossier, \DateTimeInterface $date): ?string
    {
        $nom = trim((string) $nom);
        if ('' === $nom || !$dossier) {
            return null;
        }
        $source = $this->trouverPhoto($nom, $dossier);
        if (!$source) {
            $this->avertissements[] = "Photo introuvable : $nom";

            return null;
        }
        $extension = strtolower(pathinfo($source, \PATHINFO_EXTENSION));
        if (!@getimagesize($source) && !\in_array($extension, ['heic', 'heif'], true)) {
            $this->avertissements[] = "Photo $nom : le fichier n'est pas une image valide, ignoré.";

            return null;
        }
        ++$this->photosTrouvees;
        if (\in_array($extension, ['heic', 'heif'], true)) {
            $this->avertissements[] = "Photo $nom au format iPhone (HEIC), non affichable par Chrome : convertissez-la en JPEG si besoin.";
        }
        if ($this->simulation) {
            return null;
        }

        // Rangement par année/mois de réception du colis, comme les nouvelles photos (PhotoUploader).
        $relatif = $date->format('Y/m/').bin2hex(random_bytes(12));
        $cible = $this->uploadsDir.'/photos/'.$relatif;
        if (!is_dir(\dirname($cible))) {
            mkdir(\dirname($cible), 0775, true);
        }
        if ($this->reduirePhoto($source, $cible.'.jpg')) {
            return $relatif.'.jpg';
        }
        @unlink($cible.'.jpg'); // réduction commencée puis abandonnée
        // Extension d'après le contenu réel : .jfif, .jpe, .JPEG… sont des JPEG.
        $extension = match (@getimagesize($source)[2] ?? null) {
            \IMAGETYPE_JPEG => 'jpg',
            \IMAGETYPE_PNG => 'png',
            \IMAGETYPE_WEBP => 'webp',
            \IMAGETYPE_GIF => 'gif',
            default => $extension ?: 'jpg',
        };
        if (!@copy($source, $cible.'.'.$extension)) {
            $this->avertissements[] = "Photo $nom : copie impossible, ignorée.";

            return null;
        }

        return $relatif.'.'.$extension;
    }

    /**
     * 1) chemin enregistré dans l'ancienne base, relatif au dossier (« 2025/03/x.jfif », avec ou sans
     *    préfixe « uploads/… ») ; 2) sinon recherche du nom dans tous les sous-dossiers (années…).
     */
    private function trouverPhoto(string $nom, string $dossier): ?string
    {
        $dossier = rtrim($dossier, '/');
        $morceaux = array_values(array_filter(explode('/', str_replace('\\', '/', $nom)), fn ($m) => '' !== $m && '.' !== $m && '..' !== $m));
        for ($i = 0; $i < \count($morceaux); ++$i) {
            if (is_file($chemin = $dossier.'/'.implode('/', \array_slice($morceaux, $i)))) {
                return $chemin;
            }
        }

        if (null === $this->indexPhotos) {
            $this->indexPhotos = [];
            $total = 0;
            if (is_dir($dossier)) {
                $fichiers = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dossier, \FilesystemIterator::SKIP_DOTS));
                foreach ($fichiers as $f) {
                    if ($f->isFile()) {
                        ++$total;
                        $this->indexPhotos[mb_strtolower($f->getFilename())][] = $f->getPathname();
                        $this->indexPhotos['~'.mb_strtolower($f->getBasename('.'.$f->getExtension()))][] = $f->getPathname();
                    }
                }
            }
            $this->io->writeln(\sprintf(' Dossier des photos : %d fichiers trouvés (sous-dossiers compris).', $total));
        }
        $base = mb_strtolower(end($morceaux) ?: '');
        $trouves = $this->indexPhotos[$base] ?? $this->indexPhotos['~'.pathinfo($base, \PATHINFO_FILENAME)] ?? [];
        if (\count($trouves) > 1) {
            $this->avertissements[] = \sprintf('Photo %s présente %d fois (%s) : la première est utilisée.', $nom, \count($trouves),
                implode(', ', array_map(fn ($c) => substr($c, \strlen($dossier) + 1), $trouves)));
        }

        return $trouves[0] ?? null;
    }

    /**
     * Réduit une photo JPEG/PNG/WebP trop grande. Renvoie false (la photo est alors copiée telle quelle)
     * si elle n'a pas besoin d'être réduite ou si GD n'y arrive pas : photo abîmée, trop grande pour la mémoire…
     */
    private function reduirePhoto(string $source, string $cible): bool
    {
        try {
            $infos = @getimagesize($source);
            if (!$infos || !\in_array($infos[2], [\IMAGETYPE_JPEG, \IMAGETYPE_PNG, \IMAGETYPE_WEBP], true)) {
                return false;
            }
            [$largeur, $hauteur] = $infos;
            $orientation = \IMAGETYPE_JPEG === $infos[2] && \function_exists('exif_read_data') ? (int) (@exif_read_data($source)['Orientation'] ?? 1) : 1;
            if (max($largeur, $hauteur) <= 1600 && filesize($source) < 1_500_000 && 1 === $orientation) {
                return false;
            }
            $image = match ($infos[2]) {
                \IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
                \IMAGETYPE_PNG => @imagecreatefrompng($source),
                default => @imagecreatefromwebp($source),
            };
            if (!$image instanceof \GdImage) {
                return false;
            }
            // Réduction d'abord (moins de mémoire), rotation ensuite.
            $echelle = min(1, 1600 / max(imagesx($image), imagesy($image)));
            if ($echelle < 1) {
                $image = @imagescale($image, max(1, (int) round(imagesx($image) * $echelle)), max(1, (int) round(imagesy($image) * $echelle)), \IMG_BICUBIC);
            }
            if ($image instanceof \GdImage && \in_array($orientation, [3, 6, 8], true)) {
                $image = @imagerotate($image, [3 => 180, 6 => -90, 8 => 90][$orientation], 0);
            }
            if (!$image instanceof \GdImage) {
                return false;
            }
            $fond = imagecreatetruecolor(imagesx($image), imagesy($image));
            imagefill($fond, 0, 0, imagecolorallocate($fond, 255, 255, 255));
            imagecopy($fond, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
            unset($image);

            return imagejpeg($fond, $cible, 82);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Renseigne une propriété sans setter public (dates de création historiques). */
    private function forcer(object $objet, string $propriete, mixed $valeur): void
    {
        (new \ReflectionProperty($objet, $propriete))->setValue($objet, $valeur);
    }
}
