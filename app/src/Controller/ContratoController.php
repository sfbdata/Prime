<?php

namespace App\Controller;

use App\Entity\Contrato;
use App\Repository\ContratoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/contrato')]
class ContratoController extends AbstractController
{
    #[Route('/', name: 'contrato_index', methods: ['GET'])]
    public function index(ContratoRepository $repo): Response
    {
        return $this->render('contrato/index.html.twig', [
            'contratos' => $repo->findAll(),
        ]);
    }

    #[Route('/{id}', name: 'contrato_show', methods: ['GET'])]
    public function show(Contrato $contrato): Response
    {
        return $this->render('contrato/show.html.twig', [
            'contrato' => $contrato,
        ]);
    }
}
