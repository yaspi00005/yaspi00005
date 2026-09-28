<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\EmailProfilType;
use App\Form\MotDePasseType;
use App\Service\NotificationPush;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
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
        $obligatoire = $user->isProfilAComplete();
        $form = $this->createForm(MotDePasseType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $nouveau = (string) $form->get('nouveau')->getData();
            if (hash_equals(User::MOT_DE_PASSE_PROVISOIRE, $nouveau)) {
                $form->get('nouveau')->get('first')->addError(new FormError('Choisissez un mot de passe personnel, différent du mot de passe provisoire.'));
            } else {
                $user->setPassword($hasher->hashPassword($user, $nouveau))->setMotDePasseProvisoire(false);
                $em->flush();
                $this->addFlash('success', 'Mot de passe modifié.'.($user->isProfilAComplete() ? ' Indiquez maintenant votre adresse e-mail.' : ''));

                return $this->redirectToRoute($obligatoire && !$user->isProfilAComplete() ? 'app_accueil' : 'app_profil', [], Response::HTTP_SEE_OTHER);
            }
        }

        $formEmail = $this->createForm(EmailProfilType::class, ['email' => $user->getEmail()]);
        $formEmail->handleRequest($request);
        if ($formEmail->isSubmitted() && $formEmail->isValid()) {
            $user->setEmail(mb_strtolower(trim((string) $formEmail->get('email')->getData())));
            $em->flush();
            $this->addFlash('success', 'Adresse e-mail enregistrée.'.($user->isMotDePasseProvisoire() ? ' Changez maintenant votre mot de passe provisoire.' : ''));

            return $this->redirectToRoute($obligatoire && !$user->isProfilAComplete() ? 'app_accueil' : 'app_profil', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('profil/index.html.twig', [
            'form' => $form,
            'formEmail' => $formEmail,
            'nbNavigateurs' => $push->nombreAbonnements($user),
        ], new Response(null, ($form->isSubmitted() && !$form->isValid()) || ($formEmail->isSubmitted() && !$formEmail->isValid()) || $form->getErrors(true)->count() ? 422 : 200));
    }
}
