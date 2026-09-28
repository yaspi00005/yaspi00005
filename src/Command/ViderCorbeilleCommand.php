<?php

namespace App\Command;

use App\Service\Corbeille;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Supprime définitivement les colis restés plus de 7 jours dans la corbeille. Chaque nuit :
 *   15 0 * * * cd /chemin/sotrama && php bin/console app:vider-corbeille --env=prod
 */
#[AsCommand(name: 'app:vider-corbeille', description: 'Supprime définitivement les colis de la corbeille de plus de 7 jours')]
final class ViderCorbeilleCommand extends Command
{
    public function __construct(private readonly Corbeille $corbeille)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        (new SymfonyStyle($input, $output))->success($this->corbeille->vider().' colis supprimé(s) définitivement.');

        return Command::SUCCESS;
    }
}
