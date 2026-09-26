<?php

namespace App\Tests\Service;

use App\Entity\Article;
use App\Entity\Client;
use App\Entity\Colis;
use App\Enum\ModePaiement;
use App\Enum\StatutPaiement;
use App\Service\ColisService;
use App\Service\PaiementService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PaiementServiceTest extends KernelTestCase
{
    private PaiementService $paiements;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->paiements = self::getContainer()->get(PaiementService::class);
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function nouveauColis(int $prix, int $bonusClient = 0): Colis
    {
        $client = (new Client())->setPrenom('Test')->setNom('Paiement')->setTelephone('0600000000')->setSoldeBonus($bonusClient);
        $this->em->persist($client);
        $colis = (new Colis())->setClient($client)->setDestinataireNom('Dest')->setDestinataireTelephone('76000000');
        $colis->addArticle((new Article())->setTypeMarchandise('Divers')->setPoidsVolume(1)->setPrix($prix));

        return self::getContainer()->get(ColisService::class)->creer($colis, null);
    }

    public function testEncaissementPartielPuisSolde(): void
    {
        $colis = $this->nouveauColis(10000);
        $this->assertSame(10000, $colis->getFrais());

        $this->paiements->encaisser($colis, 4000, ModePaiement::Especes, null, 1000);
        $this->assertSame(5000, $colis->getMontantRestant());
        $this->assertSame(StatutPaiement::Partiel, $colis->getStatutPaiement());

        $this->paiements->encaisser($colis, 5000, ModePaiement::Carte, null);
        $this->assertTrue($colis->isSolde());
        $this->assertSame(StatutPaiement::Paye, $colis->getStatutPaiement());
        $this->assertCount(2, $colis->getPaiements());
    }

    public function testRefuseUnMontantSuperieurAuReste(): void
    {
        $colis = $this->nouveauColis(5000);
        $this->expectException(\DomainException::class);
        $this->paiements->encaisser($colis, 6000, ModePaiement::Especes, null);
    }

    public function testRefuseUneRemiseSuperieureAuReste(): void
    {
        $colis = $this->nouveauColis(5000);
        $this->expectException(\DomainException::class);
        $this->paiements->encaisser($colis, 0, ModePaiement::Especes, null, 6000);
    }

    public function testBonusPlafonneAuResteEtAnnulationLeRestitue(): void
    {
        $colis = $this->nouveauColis(3000, 5000);
        $utilise = $this->paiements->appliquerBonus($colis, null);

        $this->assertSame(3000, $utilise, 'Le bonus ne dépasse pas le reste à payer.');
        $this->assertSame(2000, $colis->getClient()->getSoldeBonus());
        $this->assertTrue($colis->isSolde());

        $paiementBonus = $colis->getPaiements()->last();
        $this->paiements->annuler($paiementBonus, null);
        $this->assertSame(5000, $colis->getClient()->getSoldeBonus(), 'Annuler le paiement bonus recrédite le client.');
        $this->assertSame(3000, $colis->getMontantRestant());
        $this->assertSame(StatutPaiement::NonPaye, $colis->getStatutPaiement());
    }

    public function testDebitBonusInterditAuDelaDuSolde(): void
    {
        $colis = $this->nouveauColis(1000, 500);
        $this->expectException(\DomainException::class);
        $this->paiements->mouvementBonus($colis->getClient(), -600, 'Trop', null);
    }
}
