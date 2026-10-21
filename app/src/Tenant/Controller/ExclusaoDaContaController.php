<?php

declare(strict_types=1);

namespace App\Tenant\Controller;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\Tenant\TenantContext;
use App\Tenant\DTO\PedidoDeExclusaoInput;
use App\Tenant\Exception\PedidoDeExclusaoRecusado;
use App\Tenant\UseCase\DesistirDaExclusaoUseCase;
use App\Tenant\UseCase\PedirExclusaoDaContaUseCase;
use App\Tenant\UseCase\SolicitarCodigoDeExclusaoUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Excluir conta do escritório" no Meu Perfil (PR 5, docs/specs/conta-pedido-exclusao.md; desenho 07
 * L111-161): as duas etapas do modal e o "Desistir e reativar". Sempre o escritório da SESSÃO (nunca um id
 * vindo do formulário). Rotas sob /perfil: o primário de uma conta "suspensa a pedido" continua
 * alcançando-as (barreira (b) do TenantContextValidatorListener).
 *
 * Respostas JSON: 200 {ok, ...} ou {erro} com 403 (não é primário / conta do Master), 409 (estado), 422
 * (dado), 429 (limite de envios) e 503 (e-mail não saiu). CSRF próprio: `exclusao_conta`.
 */
#[Route('/perfil/conta/exclusao')]
final class ExclusaoDaContaController extends AbstractController
{
    public const CSRF = 'exclusao_conta';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SolicitarCodigoDeExclusaoUseCase $solicitarCodigo,
        private readonly PedirExclusaoDaContaUseCase $pedirExclusao,
        private readonly DesistirDaExclusaoUseCase $desistir,
        private readonly RateLimiterFactoryInterface $exclusaoContaCodigoLimiter,
    ) {
    }

    #[Route('/codigo', name: 'conta_exclusao_codigo', methods: ['POST'])]
    public function codigo(Request $request): JsonResponse
    {
        [$usuario, $tenant] = $this->contexto($request);
        if (!$this->exclusaoContaCodigoLimiter->create((string) $usuario->getId())->consume()->isAccepted()) {
            return $this->json(['erro' => 'Muitos envios em sequência. Aguarde alguns minutos e tente de novo.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            $email = $this->solicitarCodigo->executar($usuario, $tenant, PedidoDeExclusaoInput::fromRequest($request));
        } catch (PedidoDeExclusaoRecusado $e) {
            return $this->json(['erro' => $e->getMessage()], $e->status);
        }

        return $this->json(['ok' => true, 'email' => $email]);
    }

    #[Route('/pedir', name: 'conta_exclusao_pedir', methods: ['POST'])]
    public function pedir(Request $request): JsonResponse
    {
        [$usuario, $tenant] = $this->contexto($request);

        try {
            $this->pedirExclusao->executar($usuario, $tenant, PedidoDeExclusaoInput::fromRequest($request));
        } catch (PedidoDeExclusaoRecusado $e) {
            return $this->json(['erro' => $e->getMessage()], $e->status);
        }

        return $this->json(['ok' => true, 'aviso' => 'Conta suspensa. O pedido de exclusão foi enviado ao Master.']);
    }

    #[Route('/desistir', name: 'conta_exclusao_desistir', methods: ['POST'])]
    public function desistir(Request $request): JsonResponse
    {
        [$usuario, $tenant] = $this->contexto($request);

        try {
            $this->desistir->executar($usuario, $tenant);
        } catch (PedidoDeExclusaoRecusado $e) {
            return $this->json(['erro' => $e->getMessage()], $e->status);
        }

        return $this->json(['ok' => true, 'aviso' => 'Conta reativada. Que bom que você ficou!']);
    }

    /** @return array{User, Tenant} */
    private function contexto(Request $request): array
    {
        $usuario = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$usuario instanceof User || $tenant === null) {
            throw $this->createAccessDeniedException('Selecione um escritório.');
        }
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token inválido.');
        }

        return [$usuario, $tenant];
    }
}
