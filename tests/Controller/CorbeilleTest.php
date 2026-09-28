<?php

namespace App\Tests\Controller;

use App\Entity\Colis;
use App\Enum\StatutColis;
use App\Repository\ColisRepository;
use App\Repository\UserRepository;
use App\Security\IdChiffreur;
use App\Service\ColisService;
use App\Service\Corbeille;
use App\Service\Numerotation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Corbeille : colis invisible partout, restaurable 7 jours, puis supprimé définitivement ; numéro jamais réattribué. */
final class CorbeilleTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    private function connecte(string $login): void
    {
        $this->client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => $login]));
    }

    private function hid(int $id): string
    {
        return static::getContainer()->get(IdChiffreur::class)->encode($id);
    }

    private function colisDeLAgent(): Colis
    {
        foreach (static::getContainer()->get(ColisRepository::class)->findBy(['statut' => StatutColis::EnPreparation, 'montantPaye' => 0], ['id' => 'ASC']) as $c) {
            if ('ramassage' === $c->getAgent()?->getUsername()) {
                return $c;
            }
        }
        self::fail('Pas de colis supprimable.');
    }

    public function testMiseALaCorbeilleEtRestauration(): void
    {
        $colis = $this->colisDeLAgent();
        $id = $colis->getId();
        $numero = $colis->getNumeroSuivi();
        $token = $colis->getToken();
        $soldeBonus = $colis->getClient()->getSoldeBonus();

        $this->connecte('ramassage');
        $crawler = $this->client->request('GET', '/colis/'.$this->hid($id));
        $this->client->submit($crawler->filter('form[action$="/supprimer"]')->form());
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-success', 'corbeille');

        // Invisible partout : liste, fiche, suivi public.
        $this->client->request('GET', '/colis', ['q' => $numero]);
        $this->assertSelectorTextNotContains('table', $numero);
        $this->client->request('GET', '/colis/'.$this->hid($id));
        $this->assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/suivi/'.$token);
        $this->assertResponseStatusCodeSame(404);

        // Dans la corbeille de l'agent, pas dans celle d'un représentant (pas d'accès).
        $crawler = $this->client->request('GET', '/corbeille');
        $this->assertSelectorTextContains('table', $numero);
        $this->assertSelectorNotExists('form[action$="/'.$this->hid($id).'/supprimer"]', "L'agent ne supprime pas définitivement.");

        // Restauration par l'agent : le colis et le bonus du client reviennent.
        $this->client->submit($crawler->filter('form[action$="/'.$this->hid($id).'/restaurer"]')->form());
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-success', 'restauré');
        $this->assertSelectorTextContains('body', $numero);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $this->assertSame($soldeBonus, $em->find(Colis::class, $id)->getClient()->getSoldeBonus());

        $this->connecte('bamako');
        $this->client->request('GET', '/corbeille');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testUnAgentNeRestaurePasLesColisDesAutres(): void
    {
        $colis = $this->colisDeLAgent();
        $admin = static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'admin']);
        $colis->setAgent($admin);
        static::getContainer()->get(ColisService::class)->supprimer($colis, $admin);

        $this->connecte('ramassage');
        $this->client->request('GET', '/corbeille');
        $this->assertSelectorTextNotContains('body', $colis->getNumeroSuivi());
        $corbeille = static::getContainer()->get(Corbeille::class);
        $agent = static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'ramassage']);
        $this->assertNull($corbeille->trouver($colis->getId(), $agent), "L'agent ne peut pas restaurer le colis d'un autre.");
        $this->assertNotNull($corbeille->trouver($colis->getId(), $admin));

        $this->connecte('admin');
        $this->client->request('GET', '/corbeille');
        $this->assertSelectorTextContains('table', $colis->getNumeroSuivi());
    }

    public function testSuppressionDefinitiveApresSeptJoursEtNumeroJamaisReattribue(): void
    {
        $colis = $this->colisDeLAgent();
        $id = $colis->getId();
        $date = $colis->getDateReception();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $container->get(ColisService::class)->supprimer($colis, null);

        // Encore là après 6 jours…
        $em->getConnection()->executeStatement('UPDATE colis SET supprime_le = ? WHERE id = ?', [(new \DateTimeImmutable('-6 days'))->format('Y-m-d H:i:s'), $id]);
        $this->assertSame(0, $container->get(Corbeille::class)->vider());
        // … supprimé définitivement après 7 jours.
        $em->getConnection()->executeStatement('UPDATE colis SET supprime_le = ? WHERE id = ?', [(new \DateTimeImmutable('-8 days'))->format('Y-m-d H:i:s'), $id]);
        $em->clear();
        $this->assertGreaterThanOrEqual(1, $container->get(Corbeille::class)->vider());
        $this->assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM colis WHERE id = ?', [$id]));

        // Le numéro suivant du mois ne réutilise aucun numéro déjà attribué, même supprimé.
        $prefixe = 'ST'.$date->format('ym').'-';
        $max = (int) $em->getConnection()->fetchOne("SELECT MAX(CAST(SUBSTR(numero_suivi, 8) AS INTEGER)) FROM colis WHERE numero_suivi LIKE ?", [$prefixe.'%']);
        $numerotation = $container->get(Numerotation::class);
        $premier = $numerotation->numeroSuivi($date);
        $em->flush();
        $second = $numerotation->numeroSuivi($date);
        $this->assertGreaterThan($max, (int) substr($premier, -4));
        $this->assertGreaterThan((int) substr($premier, -4), (int) substr($second, -4), 'Le compteur ne revient jamais en arrière.');
    }
}
