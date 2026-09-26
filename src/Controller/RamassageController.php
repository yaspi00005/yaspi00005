<?php

namespace App\Controller;

use App\Entity\Ramassage;
use App\Entity\User;
use App\Enum\StatutRamassage;
use App\Form\RamassageType;
use App\Repository\ClientRepository;
use App\Repository\RamassageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Planning des ramassages chez les clients. */
#[Route('/ramassages')]
#[IsGranted(User::ROLE_RAMASSAGE)]
class RamassageController extends AbstractController
{
    #[Route('', name: 'ramassage_index', methods: ['GET'])]
    public function index(RamassageRepository $ramassages): Response
    {
        return $this->render('ramassage/index.html.twig', [
            'aVenir' => $ramassages->aVenir(10),
            'statuts' => StatutRamassage::cases(),
        ]);
    }

    #[Route('/evenements.json', name: 'ramassage_events', methods: ['GET'])]
    public function events(Request $request, RamassageRepository $ramassages): JsonResponse
    {
        try {
            $debut = new \DateTimeImmutable($request->query->getString('start', 'first day of this month'));
            $fin = new \DateTimeImmutable($request->query->getString('end', 'last day of next month'));
        } catch (\Exception) {
            return $this->json(['error' => 'Dates invalides'], 400);
        }

        return $this->json(array_map(fn (Ramassage $r) => [
            'id' => $r->getId(),
            'title' => $r->getClient()->getNomComplet(),
            'start' => $r->getDatePrevue()->format(\DATE_ATOM),
            'end' => $r->getDatePrevue()->modify('+1 hour')->format(\DATE_ATOM),
            'url' => $this->generateUrl('ramassage_edit', ['id' => $r->getId()]),
            'className' => 'bg-'.$r->getStatut()->badge(),
            'extendedProps' => ['statut' => $r->getStatut()->label(), 'telephone' => $r->getClient()->getTelephone()],
        ], $ramassages->entre($debut, $fin)));
    }

    #[Route('/nouveau', name: 'ramassage_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, ClientRepository $clients): Response
    {
        $ramassage = (new Ramassage())->setAgent($this->getUser());
        if ($date = $request->query->getString('date')) {
            try {
                $ramassage->setDatePrevue(new \DateTimeImmutable($date.(\strlen($date) <= 10 ? ' 09:00' : '')));
            } catch (\Exception) {
            }
        }
        if ($request->query->getInt('client')) {
            $ramassage->setClient($clients->find($request->query->getInt('client')));
        }

        return $this->formulaire($request, $ramassage, $em);
    }

    #[Route('/{id}', name: 'ramassage_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Ramassage $ramassage, EntityManagerInterface $em): Response
    {
        return $this->formulaire($request, $ramassage, $em);
    }

    #[Route('/{id}/deplacer', name: 'ramassage_deplacer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deplacer(Request $request, Ramassage $ramassage, EntityManagerInterface $em): JsonResponse
    {
        if (!$this->isCsrfTokenValid('ramassage', $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Jeton invalide'], 403);
        }
        try {
            $ramassage->setDatePrevue(new \DateTimeImmutable($request->getPayload()->getString('start')));
        } catch (\Exception) {
            return $this->json(['error' => 'Date invalide'], 400);
        }
        $em->flush();

        return $this->json(['ok' => true]);
    }

    #[Route('/{id}/supprimer', name: 'ramassage_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Ramassage $ramassage, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete-ramassage-'.$ramassage->getId(), $request->getPayload()->getString('_token'))) {
            $em->remove($ramassage);
            $em->flush();
            $this->addFlash('success', 'Ramassage supprimé.');
        }

        return $this->redirectToRoute('ramassage_index', [], Response::HTTP_SEE_OTHER);
    }

    private function formulaire(Request $request, Ramassage $ramassage, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(RamassageType::class, $ramassage);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($ramassage);
            $em->flush();
            $this->addFlash('success', 'Ramassage enregistré.');

            return $this->redirectToRoute('ramassage_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('ramassage/form.html.twig', ['form' => $form, 'ramassage' => $ramassage]);
    }
}
