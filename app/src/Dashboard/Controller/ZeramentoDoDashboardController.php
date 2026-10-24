<?php

declare(strict_types=1);

namespace App\Dashboard\Controller;

use App\Dashboard\DTO\DesfazerZeramentosInput;
use App\Dashboard\DTO\PedirZeramentoInput;
use App\Dashboard\DTO\ZerarColunaInput;
use App\Dashboard\Exception\ColaboradorForaDoEscritorioException;
use App\Dashboard\Exception\ColunaNaoZeravelException;
use App\Dashboard\Exception\ZeramentoNaoPermitidoException;
use App\Dashboard\UseCase\DesfazerZeramentosDoDashboardUseCase;
use App\Dashboard\UseCase\PedirZeramentoDoDashboardUseCase;
use App\Dashboard\UseCase\ZerarColunaDoDashboardUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Shared\Trait\ValidaCsrfAjaxTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Zerar" das colunas da tabela Desempenho (DASH-014): zerar, desfazer e pedir para zerar.
 *
 * Corpo JSON: `{"coluna": "metas", "pessoa": 12}` (`pessoa` ausente ou nulo = a equipe toda; o
 * desfazer só lê a coluna). O escritório e o autor são SEMPRE os da sessão.
 *
 * Guardas: escritório na sessão + módulo `bi` (o que abre o Dashboard) → 403; CSRF do header
 * `X-CSRF-Token` (token `ajax`) → 403; zerar e desfazer só para o Master ou um primário ativo → 403;
 * coluna fora da tabela → 400; pessoa que não é colaboradora ativa do escritório → 404 (IDOR).
 */
#[Route('/dashboard/zerar')]
final class ZeramentoDoDashboardController extends AbstractController
{
    use ValidaCsrfAjaxTrait;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly ZerarColunaDoDashboardUseCase $zerar,
        private readonly DesfazerZeramentosDoDashboardUseCase $desfazer,
        private readonly PedirZeramentoDoDashboardUseCase $pedir,
    ) {
    }

    #[Route('', name: 'dashboard_zerar', methods: ['POST'])]
    public function zerar(Request $request): JsonResponse
    {
        return $this->responder($request, function (Tenant $tenant, User $usuario, array $dados): JsonResponse {
            $input = new ZerarColunaInput($tenant, $usuario, $dados['coluna'], $dados['pessoa'], new \DateTimeImmutable());

            return $this->json($this->zerar->executar($input)->paraArray());
        });
    }

    #[Route('/desfazer', name: 'dashboard_zerar_desfazer', methods: ['POST'])]
    public function desfazer(Request $request): JsonResponse
    {
        return $this->responder($request, function (Tenant $tenant, User $usuario, array $dados): JsonResponse {
            $input = new DesfazerZeramentosInput($tenant, $usuario, $dados['coluna'], new \DateTimeImmutable());

            return $this->json(['desfeitos' => $this->desfazer->executar($input)]);
        });
    }

    #[Route('/pedir', name: 'dashboard_zerar_pedir', methods: ['POST'])]
    public function pedir(Request $request): JsonResponse
    {
        return $this->responder($request, function (Tenant $tenant, User $usuario, array $dados): JsonResponse {
            $input = new PedirZeramentoInput($tenant, $usuario, $dados['coluna'], $dados['pessoa'], new \DateTimeImmutable());

            return $this->json($this->pedir->executar($input)->paraArray());
        });
    }

    /**
     * As guardas comuns às três ações e a tradução das recusas do caso de uso em HTTP.
     *
     * @param callable(Tenant, User, array{coluna: string, pessoa: ?int}): JsonResponse $acao
     */
    private function responder(Request $request, callable $acao): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->tenantContext->getCurrentTenant();
        if ($tenant === null || !$this->permissionChecker->canAccessModule($usuario, $tenant, 'bi')) {
            return $this->json(['erro' => 'Sem acesso ao módulo Dashboard.'], Response::HTTP_FORBIDDEN);
        }
        $this->validarCsrfAjax($request);
        $dados = $this->lerCorpo($request);
        if ($dados === null) {
            return $this->json(['erro' => 'Requisição inválida.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            return $acao($tenant, $usuario, $dados);
        } catch (ZeramentoNaoPermitidoException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (ColunaNaoZeravelException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (ColaboradorForaDoEscritorioException) {
            return $this->json(['erro' => 'Colaborador não encontrado.'], Response::HTTP_NOT_FOUND);
        }
    }

    /** @return array{coluna: string, pessoa: ?int}|null */
    private function lerCorpo(Request $request): ?array
    {
        try {
            $dados = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($dados) || !is_string($dados['coluna'] ?? null)) {
            return null;
        }
        $pessoa = $dados['pessoa'] ?? null;
        if ($pessoa !== null && !is_int($pessoa)) {
            return null;
        }

        return ['coluna' => $dados['coluna'], 'pessoa' => $pessoa];
    }
}
