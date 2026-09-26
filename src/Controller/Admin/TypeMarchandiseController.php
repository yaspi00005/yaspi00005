<?php

namespace App\Controller\Admin;

use App\Entity\TypeMarchandise;
use App\Entity\User;
use App\Form\TypeMarchandiseType;
use App\Repository\TypeMarchandiseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/marchandises')]
#[IsGranted(User::ROLE_ADMIN)]
class TypeMarchandiseController extends AbstractController
{
    /** Identifiant chiffré (voir App\\Security\\IdChiffreur). */
    private const ID = '[A-Za-z0-9_-]{22}';

    #[Route('', name: 'admin_type_index', methods: ['GET', 'POST'])]
    public function index(Request $request, TypeMarchandiseRepository $types, EntityManagerInterface $em): Response
    {
        $type = new TypeMarchandise();
        $form = $this->createForm(TypeMarchandiseType::class, $type);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($type);
            $em->flush();
            $this->addFlash('success', 'Type « '.$type->getNom().' » ajouté.');

            return $this->redirectToRoute('admin_type_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/type/index.html.twig', [
            'types' => $types->findBy([], ['nom' => 'ASC']),
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'admin_type_edit', requirements: ['id' => self::ID], methods: ['GET', 'POST'])]
    public function edit(Request $request, TypeMarchandise $type, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(TypeMarchandiseType::class, $type);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Type mis à jour.');

            return $this->redirectToRoute('admin_type_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/type/form.html.twig', ['form' => $form, 'type' => $type]);
    }
}
