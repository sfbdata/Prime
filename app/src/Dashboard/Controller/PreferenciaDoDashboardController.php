<?php

declare(strict_types=1);

namespace App\Dashboard\Controller;

use App\Dashboard\DTO\PreferenciasDoDashboardOutput;
use App\Dashboard\Exception\PreferenciaInvalidaException;
use App\Dashboard\UseCase\RestaurarPreferenciasDoDashboardUseCase;
use App\Dashboard\UseCase\SalvarPreferenciaDoDashboardUseCase;
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
 * Grava os ajustes do menu ⋮ da tabela Desempenho (desenho "01 - Dashboard 1.2.2",
 * "Personalização por usuário").
 *
 * Corpo JSON, um ajuste por vez:
 *   - `{"chave": "dashboard.densidade", "valor": "confortavel"}` — grava;
 *   - `{"restaurar": true}` — "Restaurar padrão" (apaga os ajustes do usuário neste escritório).
 * Resposta: `{"preferencias": {chave: valor, …}, "classes": "…"}` — o estilo completo depois da
 * gravação, que a tela adota como verdade.
 *
 * Guardas: escritório na sessão + módulo `bi` (o mesmo que abre o Dashboard) → 403; CSRF do
 * header `X-CSRF-Token` (token `ajax`) → 403; chave/valor fora do catálogo → 400. O usuário e o
 * escritório são SEMPRE os da sessão: um `user_id` no corpo é ignorado.
 */
#[Route('/dashboard')]
final class PreferenciaDoDashboardController extends AbstractController
{
    use ValidaCsrfAjaxTrait;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly SalvarPreferenciaDoDashboardUseCase $salvar,
        private readonly RestaurarPreferenciasDoDashboardUseCase $restaurar,
    ) {
    }

    #[Route('/preferencias', name: 'dashboard_preferencias_salvar', methods: ['POST'])]
    public function salvar(Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->tenantContext->getCurrentTenant();

        if ($tenant === null || !$this->permissionChecker->canAccessModule($usuario, $tenant, 'bi')) {
            return $this->json(['erro' => 'Sem acesso ao módulo Dashboard.'], Response::HTTP_FORBIDDEN);
        }

        $this->validarCsrfAjax($request);

        try {
            $dados = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['erro' => 'Requisição inválida.'], Response::HTTP_BAD_REQUEST);
        }

        if (!is_array($dados)) {
            return $this->json(['erro' => 'Requisição inválida.'], Response::HTTP_BAD_REQUEST);
        }

        if (($dados['restaurar'] ?? null) === true) {
            return $this->resposta($this->restaurar->executar($tenant, $usuario));
        }

        if (!isset($dados['chave']) || !is_string($dados['chave']) || !array_key_exists('valor', $dados)) {
            return $this->json(['erro' => 'Informe a chave e o valor da preferência.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $preferencias = $this->salvar->executar($tenant, $usuario, $dados['chave'], $dados['valor']);
        } catch (PreferenciaInvalidaException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->resposta($preferencias);
    }

    private function resposta(PreferenciasDoDashboardOutput $preferencias): JsonResponse
    {
        return $this->json([
            'preferencias' => $preferencias->paraArray(),
            'classes'      => $preferencias->classesCss(),
        ]);
    }
}
