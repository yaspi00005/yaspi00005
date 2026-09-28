<?php

namespace App\Controller;

use App\Entity\Colis;
use App\Entity\User;
use App\Service\Corbeille;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Corbeille des colis : restauration pendant 7 jours (administrateur, ou l'agent pour ses colis),
 * puis suppression définitive automatique.
 */
#[Route('/corbeille')]
#[IsGranted(User::ROLE_RAMASSAGE)]
class CorbeilleController extends AbstractController
{
    private const ID = '[A-Za-z0-9_-]{22}';

    public function __construct(private readonly Corbeille $corbeille)
    {
    }

    #[Route('', name: 'corbeille_index', methods: ['GET'])]
    public function index(): Response
    {
        // Filet de sécurité si la tâche automatique de la nuit n'est pas installée.
        $this->corbeille->vider();

        return $this->render('corbeille/index.html.twig', [
            'colis' => $this->corbeille->contenu($this->getUser()),
            'jours' => Colis::JOURS_CORBEILLE,
        ]);
    }

    #[Route('/{id}/restaurer', name: 'corbeille_restaurer', requirements: ['id' => self::ID], methods: ['POST'])]
    public function restaurer(Request $request, int $id): Response
    {
        $colis = $this->colis($request, $id, 'restaurer');
        $this->corbeille->restaurer($colis);
        $this->addFlash('success', 'Colis '.$colis->getNumeroSuivi().' restauré.');

        return $this->redirectToRoute('colis_show', ['id' => $colis->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/supprimer', name: 'corbeille_supprimer', requirements: ['id' => self::ID], methods: ['POST'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function supprimer(Request $request, int $id): Response
    {
        $colis = $this->colis($request, $id, 'supprimer');
        $numero = $colis->getNumeroSuivi();
        $this->corbeille->supprimerDefinitivement($colis);
        $this->addFlash('success', 'Colis '.$numero.' supprimé définitivement.');

        return $this->redirectToRoute('corbeille_index', [], Response::HTTP_SEE_OTHER);
    }

    private function colis(Request $request, int $id, string $action): Colis
    {
        if (!$this->isCsrfTokenValid('corbeille-'.$action.'-'.$id, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide, rechargez la page.');
        }

        return $this->corbeille->trouver($id, $this->getUser()) ?? throw $this->createNotFoundException('Colis introuvable dans la corbeille.');
    }
}
