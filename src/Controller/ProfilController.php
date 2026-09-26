<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\MotDePasseType;
use App\Service\NotificationPush;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class ProfilController extends AbstractController
{
    #[Route('/profil', name: 'app_profil', methods: ['GET', 'POST'])]
    public function profil(Request $request, UserPasswordHasherInterface $hasher, EntityManagerInterface $em, NotificationPush $push): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $form = $this->createForm(MotDePasseType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($hasher->hashPassword($user, $form->get('nouveau')->getData()));
            $em->flush();
            $this->addFlash('success', 'Mot de passe modifié.');

            return $this->redirectToRoute('app_profil', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('profil/index.html.twig', ['form' => $form, 'nbNavigateurs' => $push->nombreAbonnements($user)]);
    }
}
