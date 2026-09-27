<?php

namespace App\Command;

use App\Entity\Article;
use App\Entity\Colis;
use App\Entity\Journal;
use App\Enum\StatutColis;
use App\Journal\Journaliseur;
use App\Service\PhotoUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Supprime les photos des colis remis depuis plus de N mois (PHOTOS_CONSERVATION_MOIS, 5 par défaut),
 * comme l'ancienne application. Le colis, ses articles et son historique sont conservés.
 * À lancer chaque nuit (tâche cron) :
 *   30 0 * * * cd /chemin/sotrama && php bin/console app:purger-photos --env=prod
 */
#[AsCommand(name: 'app:purger-photos', description: 'Supprime les photos des colis remis depuis plusieurs mois')]
final class PurgerPhotosCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PhotoUploader $photos,
        private readonly Journaliseur $journal,
        #[Autowire('%env(int:PHOTOS_CONSERVATION_MOIS)%')] private readonly int $moisParDefaut,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('mois', null, InputOption::VALUE_REQUIRED, 'Durée de conservation après la remise, en mois (par défaut : PHOTOS_CONSERVATION_MOIS)')
            ->addOption('apercu', null, InputOption::VALUE_NONE, 'Compte les photos concernées sans rien supprimer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $mois = max(1, (int) ($input->getOption('mois') ?? $this->moisParDefaut));
        $limite = (new \DateTimeImmutable('today'))->modify("-$mois months");
        $apercu = (bool) $input->getOption('apercu');

        $qb = $this->em->createQueryBuilder()
            ->select('c')->from(Colis::class, 'c')
            ->where('c.statut = :remis')
            ->andWhere('COALESCE(c.dateRecuperation, c.dateReception) < :limite')
            ->andWhere('c.photo IS NOT NULL OR EXISTS (SELECT a.id FROM '.Article::class.' a WHERE a.colis = c AND a.photo IS NOT NULL)')
            ->setParameter('remis', StatutColis::Recupere)
            ->setParameter('limite', $limite)
            ->orderBy('c.id');

        // Le résumé par colis suffit : pas une ligne de journal par article modifié.
        $this->journal->suspendre();
        $colisTraites = $photosSupprimees = 0;
        try {
            do {
                /** @var list<Colis> $lot */
                $lot = (clone $qb)->setMaxResults(100)->setFirstResult($apercu ? $colisTraites : 0)->getQuery()->getResult();
                foreach ($lot as $colis) {
                    $fichiers = array_values(array_filter([$colis->getPhoto(), ...$colis->getArticles()->map(fn ($a) => $a->getPhoto())->toArray()]));
                    $photosSupprimees += \count($fichiers);
                    ++$colisTraites;
                    if ($apercu) {
                        continue;
                    }
                    array_walk($fichiers, fn ($f) => $this->photos->supprimer($f));
                    $colis->setPhoto(null);
                    foreach ($colis->getArticles() as $article) {
                        $article->setPhoto(null);
                    }
                    $this->em->flush();
                    $this->journal->ecrire($this->em, Journal::SUPPRESSION,
                        \sprintf('Photos du colis %s supprimées automatiquement (%d) : remis depuis plus de %d mois', $colis->getNumeroSuivi(), \count($fichiers), $mois),
                        'Photo', $colis->getId(), colisId: $colis->getId(), clientId: $colis->getClient()?->getId());
                }
                $this->em->clear();
            } while (\count($lot) === 100);
        } finally {
            $this->journal->suspendre(false);
        }

        $io->success(\sprintf('%s : %d photo(s) de %d colis remis avant le %s.',
            $apercu ? 'Aperçu, rien n\'est supprimé' : 'Supprimées', $photosSupprimees, $colisTraites, $limite->format('d/m/Y')));

        return Command::SUCCESS;
    }
}
