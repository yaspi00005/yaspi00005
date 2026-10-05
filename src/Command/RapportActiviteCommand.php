<?php

namespace App\Command;

use App\Service\RapportActivite;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Rapports d'activité aux administrateurs, à lancer chaque nuit à minuit (tâche cron) :
 *   0 0 * * * cd /chemin/sotrama && php bin/console app:rapport-activite --env=prod
 *
 * Envoie le rapport de la veille ; le lundi aussi celui de la semaine écoulée, le 1er celui du mois
 * écoulé. Rien n'est envoyé pour une période sans aucun mouvement. Un rapport déjà envoyé ne l'est
 * pas une seconde fois (sauf --forcer).
 *
 * Exemples :
 *   app:rapport-activite --apercu                          ce qui serait envoyé, sans rien envoyer
 *   app:rapport-activite --type=mois --date=2026-09-01     rapport de septembre 2026
 *   app:rapport-activite --type=jour --date=2026-09-26 --a=moi@exemple.com --forcer
 */
#[AsCommand(name: 'app:rapport-activite', description: "Envoie les rapports d'activité (jour, semaine, mois) aux administrateurs")]
final class RapportActiviteCommand extends Command
{
    public function __construct(
        private readonly RapportActivite $rapports,
        private readonly CacheItemPoolInterface $cache,
        #[Autowire('%env(MAILER_DSN)%')] private readonly string $mailerDsn,
        #[Autowire('%kernel.environment%')] private readonly string $environnement,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'jour, semaine ou mois (par défaut : ceux qui sont dus aujourd\'hui)')
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Une date de la période voulue (AAAA-MM-JJ) ; par défaut, la période qui vient de se terminer')
            ->addOption('a', null, InputOption::VALUE_REQUIRED, 'Envoyer à cette adresse au lieu des administrateurs (essai)')
            ->addOption('apercu', null, InputOption::VALUE_NONE, "Affiche les chiffres sans rien envoyer")
            ->addOption('pdf', null, InputOption::VALUE_REQUIRED, 'Enregistre aussi le PDF dans ce dossier')
            ->addOption('forcer', null, InputOption::VALUE_NONE, 'Envoie même si ce rapport a déjà été envoyé');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $maintenant = new \DateTimeImmutable();
        $date = $input->getOption('date') ? new \DateTimeImmutable($input->getOption('date')) : null;
        $types = $input->getOption('type') ? [$input->getOption('type')] : RapportActivite::rapportsDus($maintenant);

        $destinataires = $input->getOption('a') ? [$input->getOption('a')] : $this->rapports->destinataires();
        if (!$destinataires && !$input->getOption('apercu')) {
            $io->warning("Aucun administrateur n'a d'adresse e-mail : renseignez-la dans Administration → Utilisateurs (ou RAPPORTS_EMAILS dans .env.local).");

            return Command::FAILURE;
        }

        // MAILER_DSN=null://null : les e-mails partent « dans le vide » sans erreur ; le rapport serait marqué
        // comme envoyé et ne repartirait jamais. On s'arrête avant.
        if ('test' !== $this->environnement && !$input->getOption('apercu') && str_starts_with(strtolower(trim($this->mailerDsn)), 'null:')) {
            $io->error("Envoi des e-mails désactivé (MAILER_DSN=null://null) : aucun rapport ne peut partir. Renseignez MAILER_DSN dans .env.local (serveur d'envoi de l'hébergeur), puis testez avec « php bin/console mailer:test votre@adresse.fr --env=prod ».");

            return Command::FAILURE;
        }

        foreach ($types as $type) {
            $periode = $this->rapports->periode($type, $date ?? $maintenant, null !== $date);
            $chiffres = $this->rapports->calculer($periode['debut'], $periode['fin']);
            $resume = \sprintf('%d colis enregistré(s), %d remis, %d paiement(s) (%s €), %d ramassage(s)',
                \count($chiffres['enregistres']), \count($chiffres['remis']), \count($chiffres['paiements']),
                number_format($chiffres['paiementsTotal'] / 100, 2, ',', ' '), \count($chiffres['ramassagesPrevus']));

            if (!$chiffres['activite']) {
                $io->text("Rapport {$periode['libelle']} : aucun mouvement, rien à envoyer.");
                continue;
            }
            if ($dossier = $input->getOption('pdf')) {
                file_put_contents(rtrim($dossier, '/').'/rapport-'.$periode['cle'].'.pdf', $this->rapports->pdf($periode, $chiffres));
            }
            if ($input->getOption('apercu')) {
                $io->text("Rapport {$periode['libelle']} : $resume (aperçu, rien n'est envoyé).");
                continue;
            }

            $memo = $this->cache->getItem('rapport_activite_'.$periode['cle']);
            if ($memo->isHit() && !$input->getOption('forcer') && !$input->getOption('a')) {
                $io->text("Rapport {$periode['libelle']} : déjà envoyé le {$memo->get()}.");
                continue;
            }

            $envoyes = $this->rapports->envoyer($periode, $chiffres, $destinataires);
            if ($envoyes && !$input->getOption('a')) {
                $this->cache->save($memo->set($maintenant->format('d/m/Y à H:i'))->expiresAfter(90 * 86400));
            }
            $message = "Rapport {$periode['libelle']} : $resume — envoyé à $envoyes/".\count($destinataires).' destinataire(s).';
            $envoyes === \count($destinataires) ? $io->success($message) : $io->warning($message.' Échecs visibles dans Administration → SMS & e-mails envoyés.');
        }

        return Command::SUCCESS;
    }
}
