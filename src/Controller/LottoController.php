<?php

namespace App\Controller;

use App\Service\LottoService;
use App\Controller\AbstractController;
use App\Controller\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route("/api/lotto")]
class LottoController extends AbstractController
{
    private LottoService $lottoService;

    public function __construct(LottoService $lottoService)
    {
        $this->lottoService = $lottoService;
    }

    #[Route("/create-ticket", name: "create_ticket", methods: ["POST"])]
    public function createTicket(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['numbers']) || !is_array($data['numbers']) || count($data['numbers']) !== 6) {
            return $this->json(['error' => 'You must provide exactly 6 numbers.'], 400);
        }

        try {
            $ticket = $this->lottoService->createTicket($data['numbers']);

            return $this->json([
                'id' => $ticket->getId(),
                'numbers' => $ticket->getNumbers(),
                'createdAt' => $ticket->getCreatedAt()->format('Y-m-d H:i:s')
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }
    }

    #[Route("/generate-ticket", name: "generate_ticket", methods: ["POST"])]
    public function generateTicket(): JsonResponse
    {
        $ticket = $this->lottoService->generateRandomTicket();

        return $this->json([
            'id' => $ticket->getId(),
            'numbers' => $ticket->getNumbers(),
            'createdAt' => $ticket->getCreatedAt()->format('Y-m-d H:i:s')
        ]);
    }

    #[Route("/draw", name: "draw_numbers", methods: ["POST"])]
    public function drawNumbers(): JsonResponse
    {
        $numbers = $this->lottoService->drawNumbers();

        return $this->json([
            'drawResults' => $numbers
        ]);
    }

    #[Route("/check-ticket/{id}", name: "check_ticket", methods: ["POST"])]
    public function checkTicket(Request $request, int $id): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['drawResults']) || !is_array($data['drawResults']) || count($data['drawResults']) !== 6) {
            return $this->json(['error' => 'You must provide exactly 6 draw results.'], 400);
        }

        $ticketRepository = $this->getDoctrine()->getRepository('App:Ticket');
        $ticket = $ticketRepository->find($id);

        if (!$ticket) {
            return $this->json(['error' => 'Ticket not found.'], 404);
        }

        $matchCount = $this->lottoService->checkTicket($ticket, $data['drawResults']);

        return $this->json([
            'id' => $ticket->getId(),
            'numbers' => $ticket->getNumbers(),
            'drawResults' => $ticket->getDrawResults(),
            'matchCount' => $matchCount
        ]);
    }

    #[Route("/simulate", name: "run_simulation", methods: ["POST"])]
    public function runSimulation(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['numberOfTickets']) || !is_numeric($data['numberOfTickets']) || $data['numberOfTickets'] < 1) {
            return $this->json(['error' => 'You must provide a valid number of tickets.'], 400);
        }

        $numberOfTickets = (int) $data['numberOfTickets'];

        // Limit the number of tickets to prevent server overload
        if ($numberOfTickets > 1000) {
            $numberOfTickets = 1000;
        }

        try {
            $simulationResults = $this->lottoService->runSimulation($numberOfTickets);
            $statistics = $this->lottoService->getSimulationStatistics($simulationResults);

            return $this->json($statistics);
        } catch (\Exception $e) {
            return $this->json(['error' => 'An error occurred during simulation: ' . $e->getMessage()], 500);
        }
    }
}
