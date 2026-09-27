<?php

namespace App\Command;

use Minishlink\WebPush\VAPID;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Clés VAPID des notifications du navigateur.
 *   php bin/console app:generer-cles-push --ecrire   → écrit directement dans .env.local (recommandé)
 *   php bin/console app:generer-cles-push            → affiche les lignes à copier
 */
#[AsCommand(name: 'app:generer-cles-push', description: 'Génère les clés VAPID des notifications du navigateur')]
final class GenererClesPushCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $dossierProjet,
        #[Autowire('%env(default::VAPID_PUBLIC_KEY)%')] private readonly ?string $clePublique,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('ecrire', null, InputOption::VALUE_NONE, 'Écrit les clés dans .env.local (sans les afficher)')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Adresse de contact transmise aux services de notification', 'contact@sotrama.net')
            ->addOption('remplacer', null, InputOption::VALUE_NONE, 'Remplace des clés déjà configurées (désabonne tous les navigateurs)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->clePublique && !$input->getOption('remplacer')) {
            $io->success('Les clés sont déjà configurées : rien à faire. (--remplacer pour en générer de nouvelles, ce qui désabonne tous les navigateurs.)');

            return Command::SUCCESS;
        }

        $cles = VAPID::createVapidKeys();
        $lignes = [
            'VAPID_PUBLIC_KEY='.$cles['publicKey'],
            'VAPID_PRIVATE_KEY='.$cles['privateKey'],
            'VAPID_SUBJECT="mailto:'.$input->getOption('email').'"',
        ];

        if (!$input->getOption('ecrire')) {
            $io->text('Ajoutez ces lignes à .env.local (une seule fois : changer de clés désabonne tous les navigateurs) :');
            $io->newLine();
            array_map($output->writeln(...), $lignes);
            $io->newLine();
            $io->text('Ou relancez avec --ecrire pour les enregistrer directement.');

            return Command::SUCCESS;
        }

        $fichier = $this->dossierProjet.'/.env.local';
        $contenu = is_file($fichier) ? (string) file_get_contents($fichier) : '';
        $contenu = preg_replace('/^VAPID_(PUBLIC_KEY|PRIVATE_KEY|SUBJECT)=.*\R?/m', '', $contenu);
        $contenu = rtrim($contenu)."\n\n# Notifications du navigateur (générées par app:generer-cles-push)\n".implode("\n", $lignes)."\n";
        file_put_contents($fichier, ltrim($contenu));
        @chmod($fichier, 0600);

        $io->success('Clés enregistrées dans .env.local.');
        $etapes = ['php bin/console cache:clear'];
        if (is_file($this->dossierProjet.'/.env.local.php')) {
            array_unshift($etapes, 'composer dump-env prod   (un fichier .env.local.php est présent)');
        }
        $io->text(['Pour les prendre en compte :', ...array_map(fn ($e) => '  '.$e, $etapes)]);

        return Command::SUCCESS;
    }
}
