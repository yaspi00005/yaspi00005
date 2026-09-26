<?php

namespace App\Tests\Service;

use App\Entity\Article;
use App\Entity\Client;
use App\Entity\Colis;
use App\Enum\ModePaiement;
use App\Service\BonusFidelite;
use App\Service\ColisService;
use App\Service\PaiementService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BonusFideliteTest extends KernelTestCase
{
    /** @return iterable<array{float, int}> total articles (€) → bonus (€) */
    public static function bareme(): iterable
    {
        yield [0, 0];
        yield [199.99, 0];
        yield [200, 10];
        yield [299.5, 10];
        yield [300, 15];
        yield [450, 20];
        yield [599, 30];
        yield [650, 40];
        yield [799, 50];
        yield [800, 60];
        yield [999, 70];
        yield [1000, 80];
        yield [1150, 90];
        yield [1299, 100];
        yield [1300, 110];
        yield [1499.99, 120];
        yield [1500, 150];
        yield [5000, 150];
    }

    #[DataProvider('bareme')]
    public function testBareme(float $total, int $bonus): void
    {
        self::bootKernel();
        $this->assertSame($bonus * 100, self::getContainer()->get(BonusFidelite::class)->bonusPour((int) round($total * 100)));
    }

    private function colis(Client $client, int $prixEuros): Colis
    {
        $colis = (new Colis())->setClient($client)->setDestinataireNom('Dest')->setDestinataireTelephone('76000000');
        $colis->addArticle((new Article())->setTypeMarchandise('Divers')->setPoidsVolume(1)->setPrix($prixEuros * 100));

        return self::getContainer()->get(ColisService::class)->creer($colis, null);
    }

    public function testGainRecalculeEtRetireAvecLeColis(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $bonus = self::getContainer()->get(BonusFidelite::class);
        $client = (new Client())->setPrenom('Bonus')->setNom('Test')->setTelephone('0600000001');
        $em->persist($client);

        $colis = $this->colis($client, 450);
        $this->assertSame(2000, $client->getSoldeBonus(), '450 € → 20 € de bonus');
        $this->assertSame(0, $bonus->disponiblePour($colis), 'Le bonus du colis ne paie pas ce même colis.');

        // Modification des articles : le bonus suit (upsert), sans doublon.
        $colis->getArticles()->first()->setPrix(65000);
        self::getContainer()->get(ColisService::class)->apresModification($colis);
        $this->assertSame(4000, $client->getSoldeBonus());
        $this->assertCount(1, array_filter($client->getMouvementsBonus()->toArray(), fn ($m) => $m->isGainColis()));

        // Le bonus du premier colis sert sur le suivant.
        $second = $this->colis($client, 100);
        $this->assertSame(4000, $bonus->disponiblePour($second));
        self::getContainer()->get(PaiementService::class)->appliquerBonus($second, null);
        $this->assertSame(6000, $second->getMontantRestant(), "100 € − 40 € de bonus");
        $this->assertSame(0, $client->getSoldeBonus());

        // Passé sous 200 € : le bonus est retiré (sans descendre sous zéro).
        $colis->getArticles()->first()->setPrix(15000);
        self::getContainer()->get(ColisService::class)->apresModification($colis);
        $this->assertNull($bonus->gainDuColis($colis));
        $this->assertSame(0, $client->getSoldeBonus());
    }

    public function testSuppressionDuColisRetireLeBonus(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $client = (new Client())->setPrenom('Bonus')->setNom('Suppr')->setTelephone('0600000002');
        $em->persist($client);

        $colis = $this->colis($client, 1600);
        $this->assertSame(15000, $client->getSoldeBonus());
        self::getContainer()->get(ColisService::class)->supprimer($colis);
        $this->assertSame(0, $client->getSoldeBonus());
    }

    public function testBonusNonUtilisableSurColisPaye(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $client = (new Client())->setPrenom('Bonus')->setNom('Paye')->setTelephone('0600000003')->setSoldeBonus(5000);
        $em->persist($client);
        $colis = $this->colis($client, 100);
        self::getContainer()->get(PaiementService::class)->encaisser($colis, 10000, ModePaiement::Especes, null);
        $this->expectException(\DomainException::class);
        self::getContainer()->get(PaiementService::class)->appliquerBonus($colis, null);
    }
}
