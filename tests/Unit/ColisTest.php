<?php

namespace App\Tests\Unit;

use App\Entity\Article;
use App\Entity\Colis;
use App\Enum\StatutColis;
use App\Enum\StatutPaiement;
use PHPUnit\Framework\TestCase;

final class ColisTest extends TestCase
{
    public function testMontantsEtStatutPaiement(): void
    {
        $colis = (new Colis())->setFrais(10000);
        $this->assertSame(10000, $colis->getMontantRestant());
        $this->assertSame(StatutPaiement::NonPaye, $colis->rafraichirStatutPaiement()->getStatutPaiement());

        $colis->setRemise(1500)->setMontantPaye(3000)->rafraichirStatutPaiement();
        $this->assertSame(8500, $colis->getMontantNet());
        $this->assertSame(5500, $colis->getMontantRestant());
        $this->assertSame(StatutPaiement::Partiel, $colis->getStatutPaiement());

        $colis->setMontantPaye(8500)->rafraichirStatutPaiement();
        $this->assertTrue($colis->isSolde());
        $this->assertSame(StatutPaiement::Paye, $colis->getStatutPaiement());
    }

    public function testPoidsEtTotalArticles(): void
    {
        $colis = new Colis();
        $colis->addArticle((new Article())->setPoidsVolume('2.5')->setPrix(2000));
        $colis->addArticle((new Article())->setPoidsVolume(1.25)->setPrix(1500));

        $this->assertSame(3.75, $colis->getPoidsTotal());
        $this->assertSame(3500, $colis->getTotalArticles());
        $this->assertSame(2, $colis->getNombreArticles());
    }

    public function testRegleDeSuppressionEtDeRemise(): void
    {
        $colis = (new Colis())->setFrais(1000);
        $this->assertTrue($colis->isSupprimable());

        $colis->setMontantPaye(500);
        $this->assertFalse($colis->isSupprimable(), 'Un colis déjà (partiellement) payé ne se supprime pas.');

        $colis->setStatut(StatutColis::Arrive);
        $this->assertFalse($colis->isRecuperable(), 'Un colis non soldé ne peut pas être remis.');
        $colis->setMontantPaye(1000);
        $this->assertTrue($colis->isRecuperable());
    }
}
