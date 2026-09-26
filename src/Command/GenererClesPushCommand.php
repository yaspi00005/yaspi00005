<?php

namespace App\Command;

use Minishlink\WebPush\VAPID;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:generer-cles-push', description: 'Génère les clés VAPID des notifications du navigateur')]
final class GenererClesPushCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $cles = VAPID::createVapidKeys();
        $io->text('Ajoutez ces lignes à .env.local (à faire une seule fois : changer de clés désabonne tous les navigateurs) :');
        $io->newLine();
        $output->writeln('VAPID_PUBLIC_KEY='.$cles['publicKey']);
        $output->writeln('VAPID_PRIVATE_KEY='.$cles['privateKey']);
        $output->writeln('VAPID_SUBJECT="mailto:contact@votre-domaine.com"');

        return Command::SUCCESS;
    }
}
