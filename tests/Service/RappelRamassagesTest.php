<?php

namespace App\Tests\Service;

use App\Entity\Client;
use App\Entity\Ramassage;
use App\Entity\User;
use App\Enum\StatutRamassage;
use App\Repository\UserRepository;
use App\Service\RappelRamassages;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email;

final class RappelRamassagesTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RappelRamassages $rappels;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->rappels = self::getContainer()->get(RappelRamassages::class);
        // On isole le test : seuls les ramassages créés ici sont « à venir ».
        foreach ($this->em->getRepository(Ramassage::class)->findAll() as $r) {
            $r->setStatut(StatutRamassage::Annule);
        }
        $this->em->flush();
    }

    private function ramassage(string $date, ?User $agent): Ramassage
    {
        $client = (new Client())->setPrenom('Rappel')->setNom('Test')->setTelephone('0600000009')->setAdresse('1 rue du Test')->setVille('Paris');
        $r = (new Ramassage())->setClient($client)->setAgent($agent)->setDatePrevue(new \DateTimeImmutable($date));
        $this->em->persist($client);
        $this->em->persist($r);
        $this->em->flush();

        return $r;
    }

    private function agent(): User
    {
        return self::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'ramassage']);
    }

    public function testRappelDeLaVeillePuisUneHeureAvant(): void
    {
        $r = $this->ramassage('2030-05-10 10:00', $this->agent());

        // Deux jours avant : rien.
        $this->assertSame(['veille' => 0, 'proche' => 0], $this->rappels->executer(new \DateTimeImmutable('2030-05-08 09:00')));

        // La veille : rappel envoyé une seule fois.
        $this->assertSame(['veille' => 1, 'proche' => 0], $this->rappels->executer(new \DateTimeImmutable('2030-05-09 11:00')));
        $this->assertEmailCount(1);
        /** @var Email $email */
        $email = $this->getMailerMessage();
        $this->assertSame('ramassage@example.com', $email->getTo()[0]->getAddress());
        $this->assertStringContainsString('Demain à 10:00', $email->getSubject());
        $this->assertSame(['veille' => 0, 'proche' => 0], $this->rappels->executer(new \DateTimeImmutable('2030-05-09 18:00')));

        // Une heure avant.
        $this->assertSame(['veille' => 0, 'proche' => 1], $this->rappels->executer(new \DateTimeImmutable('2030-05-10 09:05')));
        $this->assertNotNull($r->getRappelProcheEnvoyeLe());
        $this->assertSame(['veille' => 0, 'proche' => 0], $this->rappels->executer(new \DateTimeImmutable('2030-05-10 09:30')));
    }

    public function testRendezVousDeplaceLesRappelsRepartent(): void
    {
        $r = $this->ramassage('2030-06-10 10:00', $this->agent());
        $this->rappels->executer(new \DateTimeImmutable('2030-06-09 12:00'));
        $this->assertNotNull($r->getRappelVeilleEnvoyeLe());

        $r->setDatePrevue(new \DateTimeImmutable('2030-06-12 15:00'));
        $this->em->flush();
        $this->assertNull($r->getRappelVeilleEnvoyeLe());
        $this->assertSame(['veille' => 1, 'proche' => 0], $this->rappels->executer(new \DateTimeImmutable('2030-06-11 16:00')));
    }

    public function testRamassageAnnuleOuEffectuePasDeRappel(): void
    {
        $r = $this->ramassage('2030-07-10 10:00', $this->agent());
        $r->setStatut(StatutRamassage::Effectue);
        $this->em->flush();
        $this->assertSame(['veille' => 0, 'proche' => 0], $this->rappels->executer(new \DateTimeImmutable('2030-07-10 09:30')));
    }

    public function testSansAgentTousLesAgentsDeRamassageSontPrevenus(): void
    {
        $r = $this->ramassage('2030-08-10 10:00', null);
        $destinataires = array_map(fn (User $u) => $u->getUsername(), $this->rappels->destinataires($r));
        $this->assertSame(['ramassage'], $destinataires, 'Les admins et représentants ne sont pas sollicités.');
    }

    public function testCreeTardivementSeulLeRappelProcheEstEnvoye(): void
    {
        $r = $this->ramassage('2030-09-10 10:00', $this->agent());
        $this->assertSame(['veille' => 0, 'proche' => 1], $this->rappels->executer(new \DateTimeImmutable('2030-09-10 09:20')));
        $this->assertSame(['veille' => 0, 'proche' => 0], $this->rappels->executer(new \DateTimeImmutable('2030-09-10 09:40')));
    }

    public function testRamassageConfirmeRecoitAussiLesRappels(): void
    {
        $r = $this->ramassage('2030-10-10 10:00', $this->agent());
        $r->setStatut(StatutRamassage::Confirme);
        $this->em->flush();
        $this->assertSame(['veille' => 1, 'proche' => 0], $this->rappels->executer(new \DateTimeImmutable('2030-10-09 12:00')));
        $this->assertStringContainsString('confirmé par le client', $this->getMailerMessage()->getHtmlBody());
    }
}
