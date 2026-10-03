<?php

namespace App\Tests\Controller;

use App\Entity\Colis;
use App\Enum\StatutPaiement;
use App\Repository\ColisRepository;
use App\Repository\UserRepository;
use App\Security\IdChiffreur;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Liste des colis non payés → cocher → récapitulatif du total → confirmation : chaque colis est soldé. */
final class PaiementGroupeTest extends WebTestCase
{
    private const APP = 'http://localhost';
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    private function connecte(string $login): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => $login]));
    }

    /** @return list<Colis> */
    private function nonPayes(int $nombre): array
    {
        $colis = array_values(array_filter(
            static::getContainer()->get(ColisRepository::class)->findBy(['statutPaiement' => StatutPaiement::NonPaye]),
            fn (Colis $c) => $c->getMontantRestant() > 0,
        ));
        $this->assertGreaterThanOrEqual($nombre, \count($colis), 'Données de démonstration : colis non payés.');

        return \array_slice($colis, 0, $nombre);
    }

    private function hid(Colis $c): string
    {
        return static::getContainer()->get(IdChiffreur::class)->encode($c->getId());
    }

    public function testSelectionRecapitulatifEtConfirmation(): void
    {
        $this->connecte('admin');
        $colis = $this->nonPayes(2);
        $attendu = $colis[0]->getMontantRestant() + $colis[1]->getMontantRestant();

        // Cases à cocher seulement sur le filtre « Non payé ».
        $crawler = $this->client->request('GET', self::APP.'/colis?paiement=non_paye');
        $this->assertSelectorExists('form#paiement-groupe');
        $this->assertGreaterThan(0, $crawler->filter('input.js-groupe-case')->count());
        $this->client->request('GET', self::APP.'/colis');
        $this->assertSelectorNotExists('form#paiement-groupe');

        $crawler = $this->client->request('POST', self::APP.'/colis/paiement-groupe', [
            'ids' => [$this->hid($colis[0]), $this->hid($colis[1])],
            'retour' => '/colis?paiement=non_paye',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.card-title', '2 colis');
        $this->assertSame((string) $attendu, $crawler->filter('input[name="total"]')->attr('value'));

        $form = $crawler->filter('form[action$="/confirmer"]')->form(['mode' => 'orange_money', 'reference' => 'OM-GROUPE']);
        $this->client->submit($form);
        $this->assertResponseRedirects('/colis?paiement=non_paye');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-success', '2 colis encaissés');

        $repo = static::getContainer()->get(ColisRepository::class);
        foreach ($colis as $c) {
            $c = $repo->find($c->getId());
            $this->assertSame(StatutPaiement::Paye, $c->getStatutPaiement());
            $this->assertSame(0, $c->getMontantRestant());
            $this->assertSame('OM-GROUPE', $c->getPaiements()->last()->getReference());
        }

        // Rejouer la confirmation : les colis sont déjà soldés, rien n'est encaissé deux fois.
        $this->client->submit($form);
        $this->assertResponseRedirects();
        $this->assertSame(0, $repo->find($colis[0]->getId())->getMontantRestant());
        $this->assertCount(1, $repo->find($colis[0]->getId())->getPaiements());
    }

    public function testTotalModifieEntreTempsRedemandeConfirmation(): void
    {
        $this->connecte('admin');
        [$c] = $this->nonPayes(1);
        $crawler = $this->client->request('POST', self::APP.'/colis/paiement-groupe', ['ids' => [$this->hid($c)]]);
        $form = $crawler->filter('form[action$="/confirmer"]')->form();
        $form['total']->setValue('1');
        $this->client->submit($form);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.alert-warning', 'La sélection a changé');
        $this->assertSame(StatutPaiement::NonPaye, static::getContainer()->get(ColisRepository::class)->find($c->getId())->getStatutPaiement());
    }

    public function testAgentDeRamassageNePeutPasEncaisser(): void
    {
        $this->connecte('ramassage');
        $crawler = $this->client->request('GET', self::APP.'/colis?paiement=non_paye');
        $this->assertCount(0, $crawler->filter('input.js-groupe-case'));

        [$c] = $this->nonPayes(1);
        $this->client->request('POST', self::APP.'/colis/paiement-groupe', ['ids' => [$this->hid($c)]]);
        $this->assertResponseRedirects();
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-warning', 'Cochez au moins un colis');
        $this->assertSame(StatutPaiement::NonPaye, static::getContainer()->get(ColisRepository::class)->find($c->getId())->getStatutPaiement());
    }

    public function testNombreDeLignesParPageRetenu(): void
    {
        $this->connecte('admin');
        $crawler = $this->client->request('GET', self::APP.'/colis?tous=1&par=10');
        $this->assertLessThanOrEqual(10, $crawler->filter('tbody tr')->count());
        $this->assertSelectorTextContains('.lignes-par-page .active', '10');
        // Retenu pour cette liste, même après un filtre.
        $this->client->request('GET', self::APP.'/colis?tous=1');
        $this->assertSelectorTextContains('.lignes-par-page .active', '10');
        // Valeur non proposée : ignorée.
        $this->client->request('GET', self::APP.'/colis?tous=1&par=100000');
        $this->assertSelectorTextContains('.lignes-par-page .active', '10');
        $crawler = $this->client->request('GET', self::APP.'/colis?tous=1&par=500');
        $this->assertSelectorTextContains('.lignes-par-page .active', '500');
        $this->assertGreaterThan(10, $crawler->filter('tbody tr')->count());
    }
}
