<?php

namespace App\Command;

use App\Service\RappelRamassages;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * À lancer toutes les 5 à 15 minutes (tâche cron) :
 *   *\/10 * * * * cd /chemin/sotrama && php bin/console app:rappels-ramassages --env=prod
 */
#[AsCommand(name: 'app:rappels-ramassages', description: 'Envoie les rappels des ramassages (navigateur et e-mail)')]
final class RappelsRamassagesCommand extends Command
{
    public function __construct(private readonly RappelRamassages $rappels)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $n = $this->rappels->executer();
        (new SymfonyStyle($input, $output))->success(\sprintf('%d rappel(s) de la veille, %d rappel(s) « bientôt » envoyés.', $n['veille'], $n['proche']));

        return Command::SUCCESS;
    }
}
