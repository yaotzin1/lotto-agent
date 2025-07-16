<?php

namespace App\Service;

use App\Entity\Ticket;
use App\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;

class LottoService
{
    /**
     * @var TicketRepository
     */
    private $ticketRepository;

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @param TicketRepository $ticketRepository
     * @param EntityManagerInterface $entityManager
     */
    public function __construct(TicketRepository $ticketRepository, EntityManagerInterface $entityManager)
    {
        $this->ticketRepository = $ticketRepository;
        $this->entityManager = $entityManager;
    }

    /**
     * Create a new ticket with the given numbers
     * 
     * @param array $numbers
     * @return Ticket
     */
    public function createTicket(array $numbers)
    {
        $ticket = new Ticket();
        $ticket->setNumbers($numbers);
        
        $this->ticketRepository->save($ticket);
        
        return $ticket;
    }

    /**
     * Generate a random ticket with 6 unique numbers between 1 and 49
     * 
     * @return Ticket
     */
    public function generateRandomTicket()
    {
        $numbers = $this->generateRandomNumbers(6, 1, 49);
        return $this->createTicket($numbers);
    }

    /**
     * Generate an array of random unique numbers
     * 
     * @param int $count Number of numbers to generate
     * @param int $min Minimum value (inclusive)
     * @param int $max Maximum value (inclusive)
     * @return array
     */
    public function generateRandomNumbers($count, $min, $max)
    {
        $numbers = range($min, $max);
        shuffle($numbers);
        return array_slice($numbers, 0, $count);
    }

    /**
     * Draw 6 random numbers for the lottery
     * 
     * @return array
     */
    public function drawNumbers()
    {
        return $this->generateRandomNumbers(6, 1, 49);
    }

    /**
     * Check a ticket against the draw results
     * 
     * @param Ticket $ticket
     * @param array $drawResults
     * @return int Number of matching numbers
     */
    public function checkTicket(Ticket $ticket, array $drawResults)
    {
        $ticket->setDrawResults($drawResults);
        $matchCount = $ticket->calculateMatchCount();
        
        $this->ticketRepository->save($ticket);
        
        return $matchCount;
    }

    /**
     * Run a simulation with the specified number of tickets
     * 
     * @param int $numberOfTickets
     * @return array Simulation results
     */
    public function runSimulation($numberOfTickets)
    {
        $drawResults = $this->drawNumbers();
        $tickets = [];
        $matchCounts = [0, 0, 0, 0, 0, 0, 0]; // Index represents number of matches (0-6)
        
        // Start a transaction for better performance
        $this->entityManager->beginTransaction();
        
        try {
            for ($i = 0; $i < $numberOfTickets; $i++) {
                $ticket = $this->generateRandomTicket();
                $ticket->setIsSimulated(true);
                
                $matchCount = $this->checkTicket($ticket, $drawResults);
                $matchCounts[$matchCount]++;
                
                $tickets[] = $ticket;
                
                // Flush every 100 tickets to avoid memory issues
                if ($i % 100 === 0 && $i > 0) {
                    $this->entityManager->flush();
                    $this->entityManager->clear(Ticket::class);
                }
            }
            
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw $e;
        }
        
        return [
            'drawResults' => $drawResults,
            'tickets' => $tickets,
            'matchCounts' => $matchCounts,
            'totalTickets' => $numberOfTickets
        ];
    }

    /**
     * Get statistics for a simulation
     * 
     * @param array $simulationResults
     * @return array
     */
    public function getSimulationStatistics(array $simulationResults)
    {
        $matchCounts = $simulationResults['matchCounts'];
        $totalTickets = $simulationResults['totalTickets'];
        
        $statistics = [];
        
        for ($i = 0; $i <= 6; $i++) {
            $count = $matchCounts[$i];
            $percentage = ($count / $totalTickets) * 100;
            
            $statistics[$i] = [
                'count' => $count,
                'percentage' => round($percentage, 2)
            ];
        }
        
        return [
            'drawResults' => $simulationResults['drawResults'],
            'statistics' => $statistics,
            'totalTickets' => $totalTickets
        ];
    }
}