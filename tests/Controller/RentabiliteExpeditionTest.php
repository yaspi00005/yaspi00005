<?php

namespace App\Tests\Controller;

use App\Entity\Expedition;
use App\Repository\ExpeditionRepository;
use App\Repository\UserRepository;
use App\Security\IdChiffreur;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Charges d'une expédition, marge (gain ou perte) et vue de rentabilité. */
final class RentabiliteExpeditionTest extends WebTestCase
{
    public function testChargesEtMarge(): void
    {
        $client = static::createClient();
        $client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'admin']));
        /** @var Expedition $expedition */
        $expedition = static::getContainer()->get(ExpeditionRepository::class)->findOneBy([], ['id' => 'ASC']);
        $ca = $expedition->getChiffreAffaires();
        $this->assertGreaterThan(0, $ca);
        $id = static::getContainer()->get(IdChiffreur::class)->encode($expedition->getId());

        $crawler = $client->request('GET', "/expeditions/$id");
        $this->assertSelectorTextContains('#rentabilite', 'Rentabilité');
        $form = $crawler->filter('#rentabilite form[name="depense_expedition"]')->form([
            'depense_expedition[categorie]' => 'fret',
            'depense_expedition[libelle]' => 'Billet cargo',
            'depense_expedition[montant]' => '100,50',
        ]);
        $client->submit($form);
        $client->followRedirect();
        $this->assertSelectorTextContains('.alert-success', '100,50');
        $this->assertSelectorTextContains('#rentabilite', 'Billet cargo');
        $this->assertSelectorTextContains('#rentabilite', 'Gain');

        // Charges supérieures au chiffre d'affaires : perte.
        $crawler = $client->request('GET', "/expeditions/$id");
        $client->submit($crawler->filter('#rentabilite form[name="depense_expedition"]')->form([
            'depense_expedition[categorie]' => 'douane',
            'depense_expedition[montant]' => (string) ($ca / 100 + 1000),
        ]));
        $client->followRedirect();
        $this->assertSelectorTextContains('#rentabilite', 'Perte');

        $expedition = static::getContainer()->get(ExpeditionRepository::class)->find($expedition->getId());
        $this->assertSame(10050 + $ca + 100000, $expedition->getTotalDepenses());
        $this->assertSame(-10050 - 100000, $expedition->getMarge());

        $client->request('GET', '/expeditions/rentabilite', ['annee' => $expedition->getDateDepart()->format('Y')]);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('table', $expedition->getNumero());
        $this->assertSelectorTextContains('table', 'Perte');

        $client->request('GET', '/');
        $this->assertSelectorTextContains('body', 'Rentabilité des expéditions');

        // Suppression d'une charge.
        $crawler = $client->request('GET', "/expeditions/$id");
        $client->submit($crawler->filter('#rentabilite form[action*="/depenses/"]')->last()->form());
        $client->followRedirect();
        $this->assertSelectorTextContains('.alert-success', 'Dépense supprimée');
        $this->assertSelectorTextContains('#rentabilite', 'Gain');

        // Réservé à l'administrateur.
        $client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'ramassage']));
        $client->request('GET', '/expeditions/rentabilite');
        $this->assertResponseStatusCodeSame(403);
    }
}
