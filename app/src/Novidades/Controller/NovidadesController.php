<?php

declare(strict_types=1);

namespace App\Novidades\Controller;

use App\Novidades\Service\LeitorDeNovidades;
use App\Shared\VersaoDoSistema;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Página global de Novidades: o que cada versão trouxe, para qualquer usuário logado. Não lê
 * nada de escritório (o conteúdo é o mesmo para todos), então não há filtro de tenant aqui.
 */
#[IsGranted('ROLE_USER')]
final class NovidadesController extends AbstractController
{
    public function __construct(private readonly LeitorDeNovidades $leitor) {}

    #[Route('/novidades', name: 'novidades_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('novidades/index.html.twig', [
            'versoes' => $this->leitor->versoes(),
            'versaoAtual' => VersaoDoSistema::ATUAL,
        ]);
    }
}
