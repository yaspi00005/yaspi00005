<?php

namespace App\Controller\Admin;

use App\Entity\Camion;
use App\Entity\User;
use App\Form\CamionType;
use App\Repository\CamionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Camions des ramassages et leur chauffeur habituel. */
#[Route('/admin/camions')]
#[IsGranted(User::ROLE_ADMIN)]
class CamionController extends AbstractController
{
    /** Identifiant chiffré (voir App\\Security\\IdChiffreur). */
    private const ID = '[A-Za-z0-9_-]{22}';

    #[Route('', name: 'admin_camion_index', methods: ['GET', 'POST'])]
    public function index(Request $request, CamionRepository $camions, EntityManagerInterface $em): Response
    {
        $camion = new Camion();
        $form = $this->createForm(CamionType::class, $camion);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($camion);
            $em->flush();
            $this->addFlash('success', 'Camion « '.$camion->getNom().' » ajouté.');

            return $this->redirectToRoute('admin_camion_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/camion/index.html.twig', [
            'camions' => $camions->findBy([], ['actif' => 'DESC', 'nom' => 'ASC']),
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'admin_camion_edit', requirements: ['id' => self::ID], methods: ['GET', 'POST'])]
    public function edit(Request $request, Camion $camion, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(CamionType::class, $camion);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Camion « '.$camion->getNom().' » mis à jour.');

            return $this->redirectToRoute('admin_camion_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/camion/form.html.twig', ['form' => $form, 'camion' => $camion]);
    }
}
