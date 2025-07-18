<?php

namespace App\Controller;

use App\Service\DataLoader;
use App\Service\Evaluator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LottoController extends AbstractController
{
    public function __construct(
        private DataLoader $dataLoader,
        private Evaluator $evaluator,
        private string $dataDirectory = '%kernel.project_dir%/data'
    ) {
    }

    #[Route('/mini-lotto', name: 'mini_lotto', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $selectedNumbers = [];
        $drawCount = 50; // Default value
        $results = null;
        $combinations = [];
        $error = null;
        
        if ($request->isMethod('POST')) {
            // Get selected numbers from the form
            $selectedNumbers = $request->request->all()['numbers'] ?? [];
            $selectedNumbers = array_map('intval', $selectedNumbers);
            
            // Get draw count from the form
            $drawCount = (int) $request->request->get('drawCount', 50);
            
            // Validate input
            if (count($selectedNumbers) < 6 || count($selectedNumbers) > 12) {
                $error = 'Please select between 6 and 12 numbers.';
            } elseif ($drawCount < 50 || $drawCount > 500) {
                $error = 'Please select between 50 and 500 past draws.';
            } else {
                // Load draw data
                $draws = $this->dataLoader->loadFromCsv($drawCount);
                
                // Generate combinations
                $combinations = $this->evaluator->generateCombinations($selectedNumbers);
                
                // Evaluate hits
                $results = $this->evaluator->evaluateHits($combinations, $draws);
                
                // Add total combinations count
                $results['totalCombinations'] = count($combinations);
            }
        }
        
        return $this->render('lotto/index.html.twig', [
            'selectedNumbers' => $selectedNumbers,
            'drawCount' => $drawCount,
            'results' => $results,
            'combinationCount' => count($combinations),
            'error' => $error,
        ]);
    }
}