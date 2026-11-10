<?php

declare(strict_types=1);

namespace App\Ponto\Controller;

use App\Entity\Auth\User;
use App\Ponto\DTO\AlterarLimitesDoBancoInput;
use App\Ponto\Exception\FuncaoDoPontoRecusadaException;
use App\Ponto\Exception\LimitesDoBancoRecusadosException;
use App\Ponto\Service\FuncoesDoPonto;
use App\Ponto\UseCase\AlterarLimitesDoBancoDoEscritorioUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Shared\Trait\ValidaCsrfAjaxTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Os campos "Limite do banco (horas)" e "Prazo de compensação (meses)" da aba Configurações (spec
 * docs/specs/ponto-desenho-0811.md, E17 e P73; desenho 16 L250 e L251). POST JSON `{limiteHoras?: int, prazoMeses?: int}`,
 * com o token `ajax` no `X-CSRF-Token` (o interceptador do base.html.twig põe). Devolve `{limiteHoras, prazoMeses}`
 * gravados; a recusa é `{erro}`.
 *
 * Mesmo molde da {@see TrabalhoExternoDoPontoController}: o escritório é SEMPRE o da sessão, nunca do pedido; quem não
 * administra o ponto dele recebe 403 antes do CSRF (a falha de autorização tem prioridade e fica testável). A rota cai
 * com a tela `ponto` desligada (prefixo `ponto_` no catálogo do Master).
 */
final class LimitesDoBancoDoPontoController extends AbstractController
{
    use ValidaCsrfAjaxTrait;

    public const SEM_NUMERO_INTEIRO = 'Informe um número inteiro.';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissoes,
        private readonly FuncoesDoPonto $funcoes,
        private readonly AlterarLimitesDoBancoDoEscritorioUseCase $alterar,
    ) {
    }

    #[Route('/ponto/configuracoes/banco-de-horas', name: 'ponto_configuracao_banco_de_horas', methods: ['POST'])]
    public function alterar(Request $request): JsonResponse
    {
        $eu = $this->getUser();
        $escritorio = $this->tenantContext->getCurrentTenant();
        if (!$eu instanceof User || $escritorio === null || !$this->permissoes->canAccessModule($eu, $escritorio, 'ponto')
            || !$this->funcoes->podeConfigurar($eu, $escritorio)) {
            return $this->json(['erro' => FuncaoDoPontoRecusadaException::semPermissao()->getMessage()], Response::HTTP_FORBIDDEN);
        }

        $this->validarCsrfAjax($request);

        $input = AlterarLimitesDoBancoInput::doJson($eu, $escritorio, $this->corpo($request));
        if ($input === null) {
            return $this->json(['erro' => self::SEM_NUMERO_INTEIRO], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            return $this->json($this->alterar->executar($input)->paraJson());
        } catch (FuncaoDoPontoRecusadaException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (LimitesDoBancoRecusadosException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
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
