<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\UserType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/utilisateurs')]
#[IsGranted(User::ROLE_ADMIN)]
class UtilisateurController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    #[Route('', name: 'admin_user_index', methods: ['GET'])]
    public function index(UserRepository $users): Response
    {
        return $this->render('admin/user/index.html.twig', ['users' => $users->findBy([], ['actif' => 'DESC', 'nom' => 'ASC'])]);
    }

    #[Route('/nouveau', name: 'admin_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $user = new User();
        $form = $this->createForm(UserType::class, $user, ['creation' => true]);

        return $this->traiter($request, $form, $user, 'Utilisateur créé.');
    }

    #[Route('/{id}', name: 'admin_user_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, User $user): Response
    {
        $form = $this->createForm(UserType::class, $user);

        return $this->traiter($request, $form, $user, 'Utilisateur mis à jour.');
    }

    private function traiter(Request $request, FormInterface $form, User $user, string $message): Response
    {
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($user === $this->getUser() && (!$user->isActif() || User::ROLE_ADMIN !== $user->getRolePrincipal())) {
                $this->addFlash('danger', 'Vous ne pouvez pas retirer vos propres droits d\'administrateur.');

                return $this->redirectToRoute('admin_user_edit', ['id' => $user->getId()]);
            }
            if (User::ROLE_REPRESENTANT === $user->getRolePrincipal() && !$user->getPays()) {
                $form->get('pays')->addError(new \Symfony\Component\Form\FormError('Choisissez le pays du représentant.'));
            } else {
                if ($plain = $form->get('plainPassword')->getData()) {
                    $user->setPassword($this->hasher->hashPassword($user, $plain));
                }
                $this->em->persist($user);
                $this->em->flush();
                $this->addFlash('success', $message);

                return $this->redirectToRoute('admin_user_index', [], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('admin/user/form.html.twig', ['form' => $form, 'user' => $user]);
    }
}
