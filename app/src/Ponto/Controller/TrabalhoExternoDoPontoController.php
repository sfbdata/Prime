<?php

declare(strict_types=1);

namespace App\Ponto\Controller;

use App\Entity\Auth\User;
use App\Ponto\DTO\AlterarTrabalhoExternoInput;
use App\Ponto\Exception\FuncaoDoPontoRecusadaException;
use App\Ponto\Service\FuncoesDoPonto;
use App\Ponto\UseCase\AlterarTrabalhoExternoDoEscritorioUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Shared\Trait\ValidaCsrfAjaxTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A caixa "Permitir trabalho externo, com motivo e análise" da aba Configurações (spec docs/specs/ponto-desenho-0811.md,
 * E12 e P74). POST JSON `{permitir: bool}`, com o token `ajax` no `X-CSRF-Token` (o interceptador do base.html.twig põe).
 *
 * Mesmo molde da {@see FuncaoDoPontoController}: o escritório é SEMPRE o da sessão, nunca do pedido; quem não administra
 * o ponto dele recebe 403 antes do CSRF (a falha de autorização tem prioridade e fica testável). A rota cai com a tela
 * `ponto` desligada (prefixo `ponto_` no catálogo do Master).
 */
final class TrabalhoExternoDoPontoController extends AbstractController
{
    use ValidaCsrfAjaxTrait;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissoes,
        private readonly FuncoesDoPonto $funcoes,
        private readonly AlterarTrabalhoExternoDoEscritorioUseCase $alterar,
    ) {
    }

    #[Route('/ponto/configuracoes/trabalho-externo', name: 'ponto_configuracao_trabalho_externo', methods: ['POST'])]
    public function alterar(Request $request): JsonResponse
    {
        $eu = $this->getUser();
        $escritorio = $this->tenantContext->getCurrentTenant();
        if (!$eu instanceof User || $escritorio === null || !$this->permissoes->canAccessModule($eu, $escritorio, 'ponto')
            || !$this->funcoes->podeConfigurar($eu, $escritorio)) {
            return $this->json(['erro' => FuncaoDoPontoRecusadaException::semPermissao()->getMessage()], Response::HTTP_FORBIDDEN);
        }

        $this->validarCsrfAjax($request);

        $input = AlterarTrabalhoExternoInput::doJson($eu, $escritorio, $this->corpo($request));
        if ($input === null) {
            return $this->json(['erro' => 'Informe se o trabalho externo fica permitido.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $permitir = $this->alterar->executar($input);
        } catch (FuncaoDoPontoRecusadaException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        }

        return $this->json(['permitir' => $permitir]);
    }

    /** @return array<mixed> */
    private function corpo(Request $request): array
    {
        try {
            $dados = json_decode($request->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($dados) ? $dados : [];
    }
}
