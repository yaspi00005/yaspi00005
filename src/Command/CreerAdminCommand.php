<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:creer-admin', description: 'Crée (ou réinitialise) un compte administrateur')]
final class CreerAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('identifiant', InputArgument::REQUIRED, 'Identifiant de connexion');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $identifiant = mb_strtolower($input->getArgument('identifiant'));
        $user = $this->em->getRepository(User::class)->findOneBy(['username' => $identifiant]);

        if (!$user) {
            $user = (new User())
                ->setUsername($identifiant)
                ->setPrenom($io->ask('Prénom', 'Admin'))
                ->setNom($io->ask('Nom', 'GESCO'));
        }
        $password = $io->askHidden('Mot de passe (8 caractères min.)', function (?string $v) {
            if (mb_strlen((string) $v) < 8) {
                throw new \RuntimeException('Au moins 8 caractères.');
            }

            return $v;
        });

        $user->setRolePrincipal(User::ROLE_ADMIN)->setActif(true);
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->persist($user);
        $this->em->flush();

        $io->success('Administrateur « '.$identifiant.' » prêt.');

        return Command::SUCCESS;
    }
}
