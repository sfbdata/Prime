<?php

declare(strict_types=1);

namespace App\Dashboard\Controller;

use App\Dashboard\DTO\DefinirMetaDePastasInput;
use App\Dashboard\DTO\RemoverMetaDePastasInput;
use App\Dashboard\Exception\MetaDePastasInvalidaException;
use App\Dashboard\Exception\MetaDePastasNaoPermitidaException;
use App\Dashboard\UseCase\DefinirMetaDePastasDoMesUseCase;
use App\Dashboard\UseCase\RemoverMetaDePastasDoMesUseCase;
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
 * Meta de pastas do mês (DASH-016): definir e remover.
 *
 * Corpo JSON do definir: `{"valor": 40}` (inteiro de 1 a 9999); o remover não lê corpo. O escritório e o
 * autor são SEMPRE os da sessão; o mês é o de hoje.
 *
 * Guardas: escritório na sessão + módulo `bi` → 403; CSRF do header `X-CSRF-Token` (token `ajax`) → 403;
 * só um primário ativo (a régua do "Zerar") → 403; valor fora de 1 a 9999 ou corpo inválido → 400.
 */
#[Route('/dashboard/meta-pastas')]
final class MetaDePastasDoDashboardController extends AbstractController
{
    use ValidaCsrfAjaxTrait;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly DefinirMetaDePastasDoMesUseCase $definir,
        private readonly RemoverMetaDePastasDoMesUseCase $remover,
    ) {
    }

    #[Route('', name: 'dashboard_meta_pastas_definir', methods: ['POST'])]
    public function definir(Request $request): JsonResponse
    {
        return $this->responder($request, function (Tenant $tenant, User $usuario) use ($request): JsonResponse {
            $valor = $this->lerValor($request);
            if ($valor === null) {
                return $this->json(['erro' => 'Informe a meta como um número inteiro.'], Response::HTTP_BAD_REQUEST);
            }

            return $this->json($this->definir->executar(new DefinirMetaDePastasInput($tenant, $usuario, $valor, new \DateTimeImmutable()))->paraArray());
        });
    }

    #[Route('/remover', name: 'dashboard_meta_pastas_remover', methods: ['POST'])]
    public function remover(Request $request): JsonResponse
    {
        return $this->responder($request, fn (Tenant $tenant, User $usuario): JsonResponse => $this->json(
            $this->remover->executar(new RemoverMetaDePastasInput($tenant, $usuario, new \DateTimeImmutable()))->paraArray(),
        ));
    }

    /**
     * As guardas comuns às duas ações e a tradução das recusas do caso de uso em HTTP.
     *
     * @param callable(Tenant, User): JsonResponse $acao
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

        try {
            return $acao($tenant, $usuario);
        } catch (MetaDePastasNaoPermitidaException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (MetaDePastasInvalidaException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    private function lerValor(Request $request): ?int
    {
        try {
            $dados = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($dados) && is_int($dados['valor'] ?? null) ? $dados['valor'] : null;
    }
}
