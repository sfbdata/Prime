<?php

declare(strict_types=1);

namespace App\Dashboard\Controller;

use App\Dashboard\DTO\MarcarAlertaDaInteligenciaInput;
use App\Dashboard\Exception\PreferenciaInvalidaException;
use App\Dashboard\UseCase\MarcarAlertaDaInteligenciaUseCase;
use App\Entity\Auth\User;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Shared\Trait\ValidaCsrfAjaxTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Marca de alerta da Central de Inteligência (botões do painel): POST /dashboard/inteligencia/alerta.
 *
 * Corpo JSON: `{"alerta": "vencidas", "acao": "resolvido"}` (resolvido | ignorado | sim | nao).
 * A marca é pessoal: escritório e usuário são SEMPRE os da sessão.
 *
 * Guardas: escritório na sessão + módulo `bi` → 403; CSRF do header `X-CSRF-Token` (token `ajax`) → 403;
 * alerta ou ação desconhecidos, ou corpo inválido → 400.
 */
final class MarcaDoAlertaDaInteligenciaController extends AbstractController
{
    use ValidaCsrfAjaxTrait;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly MarcarAlertaDaInteligenciaUseCase $marcarAlerta,
    ) {
    }

    #[Route('/dashboard/inteligencia/alerta', name: 'dashboard_inteligencia_alerta_marcar', methods: ['POST'])]
    public function marcar(Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->tenantContext->getCurrentTenant();
        if ($tenant === null || !$this->permissionChecker->canAccessModule($usuario, $tenant, 'bi')) {
            return $this->json(['erro' => 'Sem acesso ao módulo Dashboard.'], Response::HTTP_FORBIDDEN);
        }
        $this->validarCsrfAjax($request);

        $corpo = $this->lerCorpo($request);
        if ($corpo === null) {
            return $this->json(['erro' => 'Informe o alerta e a ação.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            return $this->json($this->marcarAlerta->executar(new MarcarAlertaDaInteligenciaInput($tenant, $usuario, $corpo[0], $corpo[1]))->paraArray());
        } catch (PreferenciaInvalidaException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /** @return array{0: string, 1: string}|null */
    private function lerCorpo(Request $request): ?array
    {
        try {
            $dados = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($dados) || !is_string($dados['alerta'] ?? null) || !is_string($dados['acao'] ?? null)) {
            return null;
        }

        return [$dados['alerta'], $dados['acao']];
    }
}
