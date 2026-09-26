<?php

namespace App\Controller;

use App\Entity\Client;
use App\Entity\User;
use App\Form\BonusType;
use App\Form\ClientType;
use App\Repository\ClientRepository;
use App\Service\PaiementService;
use App\Util\Pagination;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/clients')]
#[IsGranted(User::ROLE_RAMASSAGE)]
class ClientController extends AbstractController
{
    #[Route('', name: 'client_index', methods: ['GET'])]
    public function index(Request $request, ClientRepository $clients): Response
    {
        $q = $request->query->getString('q');

        return $this->render('client/index.html.twig', [
            'clients' => Pagination::depuis($clients->rechercheQb($q), $request->query->get('page', 1)),
            'q' => $q,
        ]);
    }

    #[Route('/recherche.json', name: 'client_autocomplete', methods: ['GET'])]
    public function autocomplete(Request $request, ClientRepository $clients): JsonResponse
    {
        return $this->json(['results' => array_map(fn (Client $c) => [
            'id' => $c->getId(),
            'text' => $c->getNomComplet().' — '.$c->getTelephone(),
        ], $clients->autocomplete($request->query->getString('q')))]);
    }

    #[Route('/nouveau', name: 'client_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, ClientRepository $clients): Response
    {
        $client = new Client();
        $form = $this->createForm(ClientType::class, $client);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($doublon = $clients->findDoublon($client)) {
                $this->addFlash('warning', 'Attention : un client avec ce numéro existe déjà ('.$doublon->getNomComplet().').');
            }
            $em->persist($client);
            $em->flush();
            $this->addFlash('success', 'Client '.$client->getNomComplet().' enregistré.');

            if ($retour = $request->query->getString('retour')) {
                return $this->redirectToRoute($retour === 'colis' ? 'colis_new' : 'client_index', ['client' => $client->getId()]);
            }

            return $this->redirectToRoute('client_show', ['id' => $client->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('client/form.html.twig', ['form' => $form, 'client' => $client]);
    }

    #[Route('/{id}', name: 'client_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Client $client): Response
    {
        $bonusForm = $this->isGranted(User::ROLE_ADMIN)
            ? $this->createForm(BonusType::class, null, ['action' => $this->generateUrl('client_bonus', ['id' => $client->getId()])])
            : null;

        return $this->render('client/show.html.twig', [
            'client' => $client,
            'bonusForm' => $bonusForm,
        ]);
    }

    #[Route('/{id}/modifier', name: 'client_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Client $client, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(ClientType::class, $client);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Fiche client mise à jour.');

            return $this->redirectToRoute('client_show', ['id' => $client->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('client/form.html.twig', ['form' => $form, 'client' => $client]);
    }

    #[Route('/{id}/supprimer', name: 'client_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function delete(Request $request, Client $client, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete-client-'.$client->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if (!$client->getColis()->isEmpty()) {
            $this->addFlash('danger', 'Impossible de supprimer un client qui a des colis.');

            return $this->redirectToRoute('client_show', ['id' => $client->getId()]);
        }
        $em->remove($client);
        $em->flush();
        $this->addFlash('success', 'Client supprimé.');

        return $this->redirectToRoute('client_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/bonus', name: 'client_bonus', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function bonus(Request $request, Client $client, PaiementService $paiements): Response
    {
        $form = $this->createForm(BonusType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $d = $form->getData();
            try {
                $paiements->mouvementBonus($client, (int) $d['sens'] * (int) $d['montant'], $d['libelle'], $this->getUser());
                $this->addFlash('success', 'Compte bonus mis à jour.');
            } catch (\DomainException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->addFlash('danger', 'Formulaire bonus invalide.');
        }

        return $this->redirectToRoute('client_show', ['id' => $client->getId()], Response::HTTP_SEE_OTHER);
    }
}
