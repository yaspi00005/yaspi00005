<?php

namespace App\Tests\Controller;

use App\Enum\StatutRamassage;
use App\Repository\RamassageRepository;
use App\Repository\UserRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ExportRamassagesTest extends WebTestCase
{
    /** @return iterable<array{string}> */
    public static function statuts(): iterable
    {
        yield 'tous' => [''];
        foreach (StatutRamassage::cases() as $s) {
            yield $s->label() => [$s->value];
        }
    }

    #[DataProvider('statuts')]
    public function testExportPdfParStatut(string $statut): void
    {
        $client = static::createClient();
        $client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'ramassage']));
        $client->request('GET', '/ramassages/export.pdf', ['statut' => $statut, 'du' => '2020-01-01', 'au' => '2099-12-31']);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $client->getResponse()->getContent());
        $this->assertStringContainsString('ramassages'.($statut ? '-'.$statut : ''), (string) $client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testFiltreParStatutEtPeriode(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(RamassageRepository::class);

        $tous = $repo->pourExport(null, null, null);
        $enAttente = $repo->pourExport(StatutRamassage::EnAttente, null, null);
        $effectues = $repo->pourExport(StatutRamassage::Effectue, null, null);

        $this->assertNotEmpty($enAttente);
        $this->assertNotEmpty($effectues);
        $this->assertCount(\count($tous), array_merge($enAttente, $effectues, $repo->pourExport(StatutRamassage::Annule, null, null)));
        foreach ($enAttente as $r) {
            $this->assertSame(StatutRamassage::EnAttente, $r->getStatut());
        }

        // Période : seuls les ramassages futurs (les fixtures en placent dans les jours qui viennent).
        $futurs = $repo->pourExport(null, new \DateTimeImmutable('tomorrow'), null);
        foreach ($futurs as $r) {
            $this->assertGreaterThanOrEqual(new \DateTimeImmutable('tomorrow'), $r->getDatePrevue());
        }
        $this->assertLessThan(\count($tous), \count($futurs));
    }

    public function testRepresentantNaPasAcces(): void
    {
        $client = static::createClient();
        $client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'bamako']));
        $client->request('GET', '/ramassages/export.pdf');
        $this->assertResponseStatusCodeSame(403);
    }
}
