<?php

namespace App\Tests\Service;

use App\Repository\UserRepository;
use App\Service\RapportActivite;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Console\Tester\CommandTester;

final class RapportActiviteTest extends KernelTestCase
{
    use MailerAssertionsTrait;

    private function commande(): CommandTester
    {
        return new CommandTester((new Application(static::bootKernel()))->find('app:rapport-activite'));
    }

    public function testRapportsDusSelonLeJour(): void
    {
        $this->assertSame(['jour'], RapportActivite::rapportsDus(new \DateTimeImmutable('2026-09-27 00:00')));          // dimanche
        $this->assertSame(['jour', 'semaine'], RapportActivite::rapportsDus(new \DateTimeImmutable('2026-09-28 00:00'))); // lundi
        $this->assertSame(['jour', 'semaine', 'mois'], RapportActivite::rapportsDus(new \DateTimeImmutable('2026-06-01 00:00'))); // lundi 1er
    }

    public function testPeriodes(): void
    {
        $r = static::getContainer()->get(RapportActivite::class);
        $jour = $r->periode('jour', new \DateTimeImmutable('2026-09-27 00:00:05'));
        $this->assertSame(['2026-09-26', '2026-09-27'], [$jour['debut']->format('Y-m-d'), $jour['fin']->format('Y-m-d')]);
        $this->assertSame('du samedi 26 septembre 2026', $jour['libelle']);
        $semaine = $r->periode('semaine', new \DateTimeImmutable('2026-09-28 00:00'));
        $this->assertSame(['2026-09-21', '2026-09-28'], [$semaine['debut']->format('Y-m-d'), $semaine['fin']->format('Y-m-d')]);
        $mois = $r->periode('mois', new \DateTimeImmutable('2026-10-01 00:00'));
        $this->assertSame('de septembre 2026', $mois['libelle']);
        $this->assertSame(['2026-09-01', '2026-10-01'], [$mois['debut']->format('Y-m-d'), $mois['fin']->format('Y-m-d')]);
        $contenant = $r->periode('mois', new \DateTimeImmutable('2026-09-15'), true);
        $this->assertSame('2026-09-01', $contenant['debut']->format('Y-m-d'));
    }

    public function testEnvoiAuxAdministrateursAvecPdf(): void
    {
        $tester = $this->commande();
        $admin = static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'admin']);
        $admin->setEmail('direction@example.com');
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        // Les données de démonstration sont datées d'aujourd'hui.
        $tester->execute(['--type' => 'jour', '--date' => date('Y-m-d')]);
        $tester->assertCommandIsSuccessful();
        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertEmailAddressContains($email, 'To', 'direction@example.com');
        $this->assertEmailSubjectContains($email, 'Rapport du');
        $this->assertEmailAttachmentCount($email, 1);
        $this->assertStringStartsWith('%PDF', $email->getAttachments()[0]->getBody());

        // Pas de second envoi du même rapport.
        $tester->execute(['--type' => 'jour', '--date' => date('Y-m-d')]);
        $this->assertStringContainsString('déjà envoyé', $tester->getDisplay());
    }

    public function testRienSansMouvement(): void
    {
        $tester = $this->commande();
        $tester->execute(['--type' => 'mois', '--date' => '2001-01-15', '--a' => 'test@example.com']);
        $tester->assertCommandIsSuccessful();
        $this->assertEmailCount(0);
        $this->assertStringContainsString('aucun mouvement', $tester->getDisplay());
    }
}
