<?php

declare(strict_types=1);

namespace App\Voto\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Cédula da unidade na assembleia de condomínio, pelo link pessoal `/votar/{token}` (spec
 * docs/specs/voto-assembleia-condominio.md, seção 5). ESQUELETO da frente F1: só reserva a rota, que a gestão já usa
 * para montar o link. A frente F3 preenche a página, o voto, a segurança e o limitador.
 */
final class CedulaController extends AbstractController
{
    #[Route('/votar/{token}', name: 'voto_assembleia_cedula', requirements: ['token' => '[a-f0-9]{40}'], methods: ['GET'])]
    public function cedula(string $token): Response
    {
        return new Response('Em preparação.', Response::HTTP_OK, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
