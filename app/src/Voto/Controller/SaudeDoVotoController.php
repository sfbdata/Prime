<?php

declare(strict_types=1);

namespace App\Voto\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Saúde do container `voto` (spec docs/specs/bluejus-voto.md, fase A2): responde `ok` em texto, sem cache, para o
 * Samuel conferir o endereço do Voto depois de subir o container na VPS (docs/runbooks/voto-subir-na-vps.md).
 *
 * Rota `voto_publico_`: só o container de papel `voto` a serve ({@see \App\Voto\EventListener\PapelDoContainer}); no
 * BlueJus é 404. Como toda rota `voto_`, fica atrás da chave `voto` do catálogo do Master: desligada, a resposta é a
 * página "em preparação" (503).
 */
final class SaudeDoVotoController extends AbstractController
{
    #[Route('/saude-do-voto', name: 'voto_publico_saude', methods: ['GET'])]
    public function __invoke(): Response
    {
        return new Response('ok', Response::HTTP_OK, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
