<?php

declare(strict_types=1);

namespace App\Dashboard\Controller;

use App\Dashboard\DTO\EncerrarAcompanhamentoInput;
use App\Dashboard\DTO\GerirAcompanhamentoInput;
use App\Dashboard\DTO\MarcarPessoaDaInteligenciaInput;
use App\Dashboard\DTO\ObterPerfilDaPessoaInput;
use App\Dashboard\Exception\AcompanhamentoEmConflitoException;
use App\Dashboard\Exception\AcompanhamentoInvalidoException;
use App\Dashboard\Exception\AcompanhamentoNaoPermitidoException;
use App\Dashboard\Exception\ColaboradorForaDoEscritorioException;
use App\Dashboard\Exception\PreferenciaInvalidaException;
use App\Dashboard\Periodo\PeriodoDoDashboard;
use App\Dashboard\UseCase\CriarAcompanhamentoDaPessoaUseCase;
use App\Dashboard\UseCase\EncerrarAcompanhamentoDaPessoaUseCase;
use App\Dashboard\UseCase\MarcarPessoaDaInteligenciaUseCase;
use App\Dashboard\UseCase\ObterPerfilDaPessoaUseCase;
use App\Dashboard\UseCase\RenovarAcompanhamentoDaPessoaUseCase;
use App\Dashboard\UseCase\RegistrarAuditoriaDoDesempenhoIndividualUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\AcessoAPasta;
use App\Tarefa\DTO\RedistribuirMetasInput;
use App\Tarefa\Exception\RedistribuicaoForaDoEscritorioException;
use App\Tarefa\Exception\RedistribuicaoNaoPermitidaException;
use App\Tarefa\Exception\RedistribuicaoRecusadaException;
use App\Tarefa\UseCase\ListarMetasParaRedistribuirUseCase;
use App\Tarefa\UseCase\RedistribuirMetasDaPessoaUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Shared\Trait\ValidaCsrfAjaxTrait;
use App\Tenant\Service\RegraDosPrimarios;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Análise individual da pessoa no painel BlueJus Intelligence (dc 01, botão da linha da tabela).
 *
 * - GET  /dashboard/inteligencia/pessoa/{id}: fragmento HTML do perfil (XHR). Sem efeito colateral
 *   além da auditoria da visualização (`audit_log`, ação `ia_pessoa`).
 * - POST /dashboard/inteligencia/pessoa/{id}/marca: "Marcar como analisado" e "Essa análise ajudou?"
 *   (JSON `{"acao": "analisado|sim|nao|parcial"}`), marca pessoal em `preferencia_usuario`.
 *
 * - POST .../acompanhamento, .../acompanhamento/renovar e .../acompanhamento/encerrar: "Criar acompanhamento"
 *   (JSON `{"prazo_dias": 1|2|7}`, nos dois primeiros) e "Encerrar acompanhamento". Registro do escritório
 *   (`dashboard_acompanhamento_pessoa`), nunca automático; duplicado ou sem aberto → 409, prazo inválido,
 *   o próprio gestor ou o Master como alvo → 400.
 * - GET  .../redistribuir (XHR): fragmento HTML com as metas ativas da pessoa (cadeado aplicado, tudo DESMARCADO)
 *   e o destino sugerido por regras fixas. POST .../redistribuir (JSON `{"movimentos": [{"meta_id", "destino_id"}]}`,
 *   no máximo 50): a confirmação explícita do gestor; meta de outro escritório → 404, lote inválido → 400
 *   (spec painel-acompanhamento-redistribuir, fatia B).
 *
 * Guardas: escritório na sessão + módulo `bi` → 403; só o Master ou um primário ativo abre a análise
 * individual (RegraDosPrimarios) → 403; o id tem de ser colaborador ativo do escritório → 404 (IDOR);
 * CSRF do header no POST → 403; ação desconhecida ou corpo inválido → 400.
 */
#[Route('/dashboard/inteligencia/pessoa/{id}', requirements: ['id' => '\d+'])]
final class PerfilDaPessoaDoDashboardController extends AbstractController
{
    use ValidaCsrfAjaxTrait;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly RegraDosPrimarios $regraDosPrimarios,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly ObterPerfilDaPessoaUseCase $obterPerfil,
        private readonly MarcarPessoaDaInteligenciaUseCase $marcarPessoa,
        private readonly RegistrarAuditoriaDoDesempenhoIndividualUseCase $auditoria,
        private readonly CriarAcompanhamentoDaPessoaUseCase $criarAcompanhamento,
        private readonly RenovarAcompanhamentoDaPessoaUseCase $renovarAcompanhamento,
        private readonly EncerrarAcompanhamentoDaPessoaUseCase $encerrarAcompanhamento,
        private readonly ListarMetasParaRedistribuirUseCase $listarParaRedistribuir,
        private readonly RedistribuirMetasDaPessoaUseCase $redistribuir,
    ) {
    }

    #[Route('', name: 'dashboard_inteligencia_pessoa', methods: ['GET'])]
    public function perfil(int $id, Request $request): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->autorizar($usuario);

        $veioData = $request->query->has('data_de') || $request->query->has('data_ate');
        $filtros  = PeriodoDoDashboard::comPadrao([
            'data_de'  => (string) $request->query->get('data_de', ''),
            'data_ate' => (string) $request->query->get('data_ate', ''),
        ], $veioData, new \DateTimeImmutable());

        try {
            $perfil = $this->obterPerfil->executar(new ObterPerfilDaPessoaInput(
                $tenant,
                $usuario,
                $id,
                $filtros,
                new \DateTimeImmutable(),
                $this->acessoAPasta->idsOcultosPara($usuario, (int) $tenant->getId()),
            ));
        } catch (ColaboradorForaDoEscritorioException) {
            throw $this->createNotFoundException('Colaborador não encontrado.');
        }

        $this->auditoria->visualizou($tenant, $usuario, $id);

        return $this->render('dashboard/_perfil_pessoa.html.twig', ['perfil' => $perfil, 'filtros' => $filtros]);
    }

    #[Route('/marca', name: 'dashboard_inteligencia_pessoa_marcar', methods: ['POST'])]
    public function marcar(int $id, Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->autorizar($usuario);
        $this->validarCsrfAjax($request);

        $acao = $this->lerAcao($request);
        if ($acao === null) {
            return $this->json(['erro' => 'Informe a ação.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            return $this->json($this->marcarPessoa->executar(new MarcarPessoaDaInteligenciaInput($tenant, $usuario, $id, $acao))->paraArray());
        } catch (ColaboradorForaDoEscritorioException) {
            throw $this->createNotFoundException('Colaborador não encontrado.');
        } catch (PreferenciaInvalidaException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/acompanhamento', name: 'dashboard_inteligencia_pessoa_acomp_criar', methods: ['POST'])]
    public function criarAcompanhamento(int $id, Request $request): JsonResponse
    {
        return $this->gerirAcompanhamento($request, fn (GerirAcompanhamentoInput $in) => $this->criarAcompanhamento->executar($in), $id, true);
    }

    #[Route('/acompanhamento/renovar', name: 'dashboard_inteligencia_pessoa_acomp_renovar', methods: ['POST'])]
    public function renovarAcompanhamento(int $id, Request $request): JsonResponse
    {
        return $this->gerirAcompanhamento($request, fn (GerirAcompanhamentoInput $in) => $this->renovarAcompanhamento->executar($in), $id, true);
    }

    #[Route('/acompanhamento/encerrar', name: 'dashboard_inteligencia_pessoa_acomp_encerrar', methods: ['POST'])]
    public function encerrarAcompanhamento(int $id, Request $request): JsonResponse
    {
        $encerrar = fn (GerirAcompanhamentoInput $in) => $this->encerrarAcompanhamento->executar(new EncerrarAcompanhamentoInput($in->tenant, $in->ator, $in->pessoaId, $in->agora));

        return $this->gerirAcompanhamento($request, $encerrar, $id, false);
    }

    #[Route('/redistribuir', name: 'dashboard_inteligencia_pessoa_redistribuir', methods: ['GET'])]
    public function redistribuirLista(int $id): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->autorizar($usuario);

        try {
            $lista = $this->listarParaRedistribuir->executar($tenant, $usuario, $id, $this->acessoAPasta->idsOcultosPara($usuario, (int) $tenant->getId()));
        } catch (RedistribuicaoForaDoEscritorioException) {
            throw $this->createNotFoundException('Colaborador não encontrado.');
        } catch (RedistribuicaoNaoPermitidaException | RedistribuicaoRecusadaException $e) {
            return new Response($e->getMessage(), $e instanceof RedistribuicaoNaoPermitidaException ? Response::HTTP_FORBIDDEN : Response::HTTP_BAD_REQUEST);
        }

        return $this->render('dashboard/_redistribuir_pessoa.html.twig', ['lista' => $lista, 'limite' => RedistribuirMetasDaPessoaUseCase::LIMITE_DE_METAS]);
    }

    #[Route('/redistribuir', name: 'dashboard_inteligencia_pessoa_redistribuir_confirmar', methods: ['POST'])]
    public function redistribuirConfirmar(int $id, Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->autorizar($usuario);
        $this->validarCsrfAjax($request);

        $movimentos = $this->lerMovimentos($request);
        if ($movimentos === null) {
            return $this->json(['erro' => 'Escolha as metas e o destino de cada uma.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $resultado = $this->redistribuir->executar(new RedistribuirMetasInput($tenant, $usuario, $id, $movimentos, new \DateTimeImmutable()));
        } catch (RedistribuicaoForaDoEscritorioException) {
            throw $this->createNotFoundException('Meta ou colaborador não encontrado.');
        } catch (RedistribuicaoNaoPermitidaException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (RedistribuicaoRecusadaException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($resultado->paraArray());
    }

    /** @return list<array{meta_id: int, destino_id: int}>|null nulo quando o corpo não tem a forma esperada */
    private function lerMovimentos(Request $request): ?array
    {
        try {
            $dados = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $lista = is_array($dados) ? ($dados['movimentos'] ?? null) : null;
        if (!is_array($lista) || !array_is_list($lista)) {
            return null;
        }

        $movimentos = [];
        foreach ($lista as $item) {
            if (!is_array($item) || !is_int($item['meta_id'] ?? null) || !is_int($item['destino_id'] ?? null)) {
                return null;
            }
            $movimentos[] = ['meta_id' => $item['meta_id'], 'destino_id' => $item['destino_id']];
        }

        return $movimentos;
    }

    /**
     * Cadeia comum das três portas: escritório e perfil (403), CSRF (403), prazo do corpo quando a porta o
     * pede (400), UseCase e a tradução das recusas do domínio (403, 404, 400, 409).
     *
     * @param callable(GerirAcompanhamentoInput): \App\Dashboard\DTO\AcompanhamentoDaPessoaOutput $acao
     */
    private function gerirAcompanhamento(Request $request, callable $acao, int $id, bool $pedePrazo): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->autorizar($usuario);
        $this->validarCsrfAjax($request);

        $prazo = $pedePrazo ? $this->lerPrazo($request) : 0;
        if ($prazo === null) {
            return $this->json(['erro' => 'Escolha um prazo de revisão de 1, 2 ou 7 dias.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            return $this->json($acao(new GerirAcompanhamentoInput($tenant, $usuario, $id, $prazo, new \DateTimeImmutable()))->paraArray());
        } catch (ColaboradorForaDoEscritorioException) {
            throw $this->createNotFoundException('Colaborador não encontrado.');
        } catch (AcompanhamentoNaoPermitidoException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (AcompanhamentoInvalidoException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (AcompanhamentoEmConflitoException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
    }

    private function lerPrazo(Request $request): ?int
    {
        try {
            $dados = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $prazo = is_array($dados) ? ($dados['prazo_dias'] ?? null) : null;

        return is_int($prazo) ? $prazo : null;
    }

    private function autorizar(User $usuario): Tenant
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null || !$this->permissionChecker->canAccessModule($usuario, $tenant, 'bi')) {
            throw $this->createAccessDeniedException('Sem acesso ao módulo Dashboard.');
        }
        if (!$this->regraDosPrimarios->podeGerirPrimarios($usuario, $tenant)) {
            throw $this->createAccessDeniedException('Seu perfil não pode abrir análises individuais.');
        }

        return $tenant;
    }

    private function lerAcao(Request $request): ?string
    {
        try {
            $dados = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($dados) && is_string($dados['acao'] ?? null) ? $dados['acao'] : null;
    }
}
