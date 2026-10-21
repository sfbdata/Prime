<?php

declare(strict_types=1);

namespace App\Expediente\Controller;

use App\Entity\Auth\User;
use App\Expediente\DTO\ResultadoDaBuscaRapidaOutput;
use App\Expediente\UseCase\BuscarRapidoUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Busca rápida de pastas (Ctrl + K em toda página logada, desenho 02 EXPEDIENTES L405-421). Devolve até 8
 * resultados em JSON para a janela montada por public/js/pasta-busca-rapida.js.
 *
 * A URL de todo resultado é a da pasta (`pasta_show`), inclusive a da pasta oculta pelo cadeado
 * (`restrito`): lá a barreira do #120 mostra a tela "Pasta trancada", com o "Pedir acesso" dela.
 */
final class BuscaRapidaController extends AbstractController
{
    public function __construct(
        private readonly BuscarRapidoUseCase $buscarRapido,
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/expediente/busca-rapida', name: 'expediente_busca_rapida', methods: ['GET'])]
    public function buscar(Request $request): JsonResponse
    {
        $user   = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$user instanceof User || $tenant === null || !$this->permissionChecker->canAccessModule($user, $tenant, 'pastas')) {
            throw $this->createAccessDeniedException('Sem acesso ao módulo Pastas.');
        }

        $saida = $this->buscarRapido->executar((string) $request->query->get('q', ''), $user, $tenant);

        $resposta = $this->json([
            'resultados' => array_map($this->itemDaResposta(...), $saida->resultados),
        ]);
        $resposta->headers->set('Cache-Control', 'no-store');

        return $resposta;
    }

    /** @return array<string, mixed> */
    private function itemDaResposta(ResultadoDaBuscaRapidaOutput $r): array
    {
        return [
            'grupo'     => $r->grupo,
            'titulo'    => $r->titulo,
            'subtitulo' => $r->subtitulo,
            'pastaId'   => $r->pastaId,
            'nup'       => $r->nup,
            'url'       => $this->generateUrl('pasta_show', ['id' => $r->pastaId]),
            'restrito'  => $r->restrito,
        ];
    }
}
