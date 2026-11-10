<?php

declare(strict_types=1);

namespace App\Ponto\Controller;

use App\Entity\Auth\User;
use App\Ponto\DTO\AlterarFuncaoDoPontoInput;
use App\Ponto\Exception\FuncaoDoPontoRecusadaException;
use App\Ponto\Service\FuncoesDoPonto;
use App\Ponto\UseCase\AlterarFuncaoDoPontoNoEscritorioUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Shared\Trait\ValidaCsrfAjaxTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A chave "Escritório" de uma função do Ponto, na aba Configurações (spec docs/specs/ponto-desenho-0811.md, E7 e
 * P67). POST JSON `{ligada: bool}`, com o token `ajax` no `X-CSRF-Token` (o interceptador do base.html.twig põe).
 *
 * O escritório é SEMPRE o da sessão, nunca do pedido: não há id de escritório no endereço nem no corpo. Quem não
 * administra o ponto dele recebe 403 antes do CSRF (a falha de autorização tem prioridade e fica testável). A chave
 * "Global" é do Master, pela rota `master_recurso_alternar`. A rota cai com a tela `ponto` desligada (prefixo
 * `ponto_` no catálogo do Master).
 */
final class FuncaoDoPontoController extends AbstractController
{
    use ValidaCsrfAjaxTrait;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissoes,
        private readonly FuncoesDoPonto $funcoes,
        private readonly AlterarFuncaoDoPontoNoEscritorioUseCase $alterar,
    ) {
    }

    #[Route('/ponto/configuracoes/funcoes/{chave}', name: 'ponto_configuracao_funcao', requirements: ['chave' => '[a-z][a-z-]{0,39}'], methods: ['POST'])]
    public function alterar(string $chave, Request $request): JsonResponse
    {
        $eu = $this->getUser();
        $escritorio = $this->tenantContext->getCurrentTenant();
        if (!$eu instanceof User || $escritorio === null || !$this->permissoes->canAccessModule($eu, $escritorio, 'ponto')
            || !$this->funcoes->podeConfigurar($eu, $escritorio)) {
            return $this->json(['erro' => FuncaoDoPontoRecusadaException::semPermissao()->getMessage()], Response::HTTP_FORBIDDEN);
        }

        $this->validarCsrfAjax($request);

        $input = AlterarFuncaoDoPontoInput::doJson($eu, $escritorio, $chave, $this->corpo($request));
        if ($input === null) {
            return $this->json(['erro' => 'Informe se a função fica ligada ou desligada.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $funcao = $this->alterar->executar($input);
        } catch (FuncaoDoPontoRecusadaException $e) {
            return $this->recusa($e);
        }

        return $this->json(['chave' => $funcao->chave, 'global' => $funcao->global, 'escritorio' => $funcao->escritorio]);
    }

    /**
     * A recusa do caso de uso: 403 sem permissão, 422 nas outras. Travada pelo Master, a resposta leva `global: false`:
     * o Master pode ter desligado o Global depois que a aba carregou, e a tela trava a chave do escritório na hora.
     */
    private function recusa(FuncaoDoPontoRecusadaException $e): JsonResponse
    {
        if ($e->motivo === FuncaoDoPontoRecusadaException::SEM_PERMISSAO) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        }
        $corpo = ['erro' => $e->getMessage()];
        if ($e->motivo === FuncaoDoPontoRecusadaException::TRAVADA_PELO_MASTER) {
            $corpo['global'] = false;
        }

        return $this->json($corpo, Response::HTTP_UNPROCESSABLE_ENTITY);
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
