<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Entity\Auth\User;
use App\Pasta\Entity\PrioridadePasta;
use App\Pasta\UseCase\ListarMinhasDemandasUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DemandasController extends AbstractController
{
    public function __construct(
        private readonly ListarMinhasDemandasUseCase $listarMinhasDemandas,
    ) {}

    #[Route('/demandas', name: 'demandas_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();

        $cliente    = $request->query->get('cliente', '') ?: null;
        $prioridade = $request->query->get('prioridade', '') ?: null;

        $pastas = $this->listarMinhasDemandas->executar($usuario, $cliente, $prioridade);

        return $this->render('pasta/demandas.html.twig', [
            'pastas'      => $pastas,
            'filtros'     => [
                'cliente'    => $cliente ?? '',
                'prioridade' => $prioridade ?? '',
            ],
            'prioridades' => PrioridadePasta::cases(),
        ]);
    }
}
