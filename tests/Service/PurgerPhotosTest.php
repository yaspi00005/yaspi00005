<?php

namespace App\Tests\Service;

use App\Entity\Journal;
use App\Enum\StatutColis;
use App\Repository\ColisRepository;
use App\Repository\JournalRepository;
use App\Service\PhotoUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class PurgerPhotosTest extends KernelTestCase
{
    public function testPhotosDesColisRemisDepuisLongtempsSupprimees(): void
    {
        $kernel = static::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $photos = static::getContainer()->get(PhotoUploader::class);
        $tous = static::getContainer()->get(ColisRepository::class)->findBy([], ['id' => 'ASC'], 2);

        // Colis 1 remis il y a 6 mois, colis 2 remis il y a 1 mois : chacun a une photo de colis et d'article.
        $fichiers = [];
        foreach ($tous as $i => $colis) {
            foreach (['colis', 'article'] as $type) {
                $nom = 'test/purge-'.$i.'-'.$type.'.jpg';
                @mkdir(\dirname($photos->chemin($nom)), 0775, true);
                file_put_contents($photos->chemin($nom), 'jpg');
                $fichiers[$i][] = $nom;
            }
            $colis->setPhoto($fichiers[$i][0]);
            $colis->getArticles()->first()->setPhoto($fichiers[$i][1]);
            $colis->setStatut(StatutColis::Recupere);
            $colis->setDateRecuperation(new \DateTimeImmutable(0 === $i ? '-6 months' : '-1 month'));
        }
        $em->flush();
        $ids = array_map(fn ($c) => $c->getId(), $tous);

        $tester = new CommandTester((new Application($kernel))->find('app:purger-photos'));
        $tester->execute(['--apercu' => true, '--mois' => 5]);
        $this->assertStringContainsString('2 photo(s) de 1 colis', $tester->getDisplay());
        $this->assertFileExists($photos->chemin($fichiers[0][0]));

        $modificationsAvant = static::getContainer()->get(JournalRepository::class)->count(['action' => Journal::MODIFICATION]);
        $tester->execute(['--mois' => 5]);
        $tester->assertCommandIsSuccessful();
        $em->clear();
        $ancien = static::getContainer()->get(ColisRepository::class)->find($ids[0]);
        $recent = static::getContainer()->get(ColisRepository::class)->find($ids[1]);
        $this->assertNull($ancien->getPhoto());
        $this->assertNull($ancien->getArticles()->first()->getPhoto());
        $this->assertFileDoesNotExist($photos->chemin($fichiers[0][0]));
        $this->assertFileDoesNotExist($photos->chemin($fichiers[0][1]));
        $this->assertSame($fichiers[1][0], $recent->getPhoto(), 'Colis remis récemment : photos conservées.');
        $this->assertFileExists($photos->chemin($fichiers[1][0]));

        $journal = static::getContainer()->get(JournalRepository::class)->findOneBy(['entite' => 'Photo', 'colisId' => $ids[0]]);
        $this->assertSame(Journal::SUPPRESSION, $journal->getAction());
        $this->assertSame('Système', $journal->getUtilisateurNom());
        $this->assertSame($modificationsAvant, static::getContainer()->get(JournalRepository::class)->count(['action' => Journal::MODIFICATION]), 'Un seul résumé par colis, pas une ligne par article.');

        array_map(fn ($f) => @unlink($photos->chemin($f)), $fichiers[1]);
    }
}
