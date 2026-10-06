<?php

declare(strict_types=1);

namespace App\Processo\Controller;

use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaRepository;
use App\Processo\DTO\NotaTecnicaOutput;
use App\Processo\Entity\NotaTecnica;
use App\Processo\Entity\Processo;
use App\Processo\Exception\NotaTecnicaForaDoEscopoException;
use App\Processo\Exception\NotaTecnicaNaoEditavelException;
use App\Processo\Exception\NotaTecnicaNaoExcluivelException;
use App\Processo\Repository\NotaTecnicaRepository;
use App\Processo\Repository\ProcessoRepository;
use App\Processo\UseCase\CriarNotaTecnicaUseCase;
use App\Processo\UseCase\EditarNotaTecnicaUseCase;
use App\Processo\UseCase\ExcluirNotaTecnicaUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Shared\Service\SanitizadorTextoRico;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Notas técnicas de um processo — criar, editar e excluir, por XHR, no mesmo contrato JSON das
 * observações da pasta (`PastaController::detalhes*Observacao`), para o JS seguir o mesmo padrão.
 *
 * Guarda em três camadas, nesta ordem:
 *   1. o processo é do escritório da sessão — busca tenant-safe (`findOneByIdDoTenant`), nunca
 *      `find()` por PK, que o TenantFilter não cobre. Processo alheio responde 404, não 403.
 *   2. o usuário pode agir: a nota chega pela PASTA (`pasta_id`) ou direto pelo processo. Pela
 *      pasta, ela tem de ser do escritório e vincular este processo (senão 404), e vale
 *      `canAccessResource('pasta', edit)` — a mesma permissão das observações — e a pasta não pode
 *      ser lápide (excluída: somente-leitura, 403). Sem pasta, vale
 *      `canAccessResource('processo', edit)`.
 *   3. CSRF por ação, com id no token (`processo_nota_tecnica_<id>`), como as observações.
 *
 * A publicação (`publicacao_id`, opcional) também é resolvida tenant-safe; se não for deste
 * processo, o UseCase recusa e a resposta é 404 — o id na URL não vira gancho em publicação alheia.
 */
#[Route('/processos/{id}/nota-tecnica', requirements: ['id' => '\d+'])]
final class NotaTecnicaController extends AbstractController
{
    private const PASTA_SOMENTE_LEITURA = 'Esta pasta foi excluída e está somente para leitura. Restaure a pasta para voltar a editá-la.';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly ProcessoRepository $processoRepository,
        private readonly PastaRepository $pastaRepository,
        private readonly PublicacaoDjenRepository $publicacaoRepository,
        private readonly NotaTecnicaRepository $notaRepository,
        private readonly CriarNotaTecnicaUseCase $criarUseCase,
        private readonly EditarNotaTecnicaUseCase $editarUseCase,
        private readonly ExcluirNotaTecnicaUseCase $excluirUseCase,
    ) {
    }

    #[Route('', name: 'processo_nota_tecnica_criar', methods: ['POST'])]
    public function criar(int $id, Request $request, SanitizadorTextoRico $sanitizador, CsrfTokenManagerInterface $csrf): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $tenant      = $this->tenantContext->getCurrentTenant();

        $processo = $tenant !== null ? $this->processoRepository->findOneByIdDoTenant($id, $tenant) : null;
        if ($tenant === null || $processo === null) {
            return $this->json(['erro' => 'Processo não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        if ($negado = $this->negarSeNaoPodeAgir($request, $processo, $currentUser, $tenant)) {
            return $negado;
        }

        if (!$this->isCsrfTokenValid('processo_nota_tecnica_' . $id, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        $conteudo = trim((string) $request->request->get('conteudo', ''));
        if ($conteudo === '') {
            return $this->json(['erro' => 'A nota técnica não pode ser vazia.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $publicacao   = null;
        $publicacaoId = $request->request->getInt('publicacao_id');
        if ($publicacaoId > 0) {
            $publicacao = $this->publicacaoRepository->findOneByIdDoTenant($publicacaoId, $tenant);
            if ($publicacao === null) {
                return $this->json(['erro' => 'Publicação não encontrada.'], Response::HTTP_NOT_FOUND);
            }
        }

        try {
            $nota = $this->criarUseCase->executar($processo, $currentUser, $tenant, $conteudo, $publicacao);
        } catch (NotaTecnicaForaDoEscopoException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'id'                 => $nota->id,
            // `conteudo` é o valor CRU (volta para dentro do editor ao editar); `conteudoHtml` é o
            // que a tela exibe — sanitizado aqui para o JS poder inserir como HTML com segurança.
            'conteudo'           => $nota->conteudo,
            'conteudoHtml'       => $sanitizador->paraExibicao($nota->conteudo),
            'autorNome'          => $nota->autorNome,
            'criadaEm'           => $nota->criadaEm->format('d/m/Y H:i'),
            'publicacaoId'       => $nota->publicacaoId,
            'movimentacaoRotulo' => $nota->movimentacaoRotulo,
            'csrfEditar'         => $csrf->getToken('processo_nota_tecnica_editar_' . $nota->id)->getValue(),
            'csrfExcluir'        => $csrf->getToken('processo_nota_tecnica_excluir_' . $nota->id)->getValue(),
            'urlEditar'          => $this->generateUrl('processo_nota_tecnica_editar', ['id' => $id, 'notaId' => $nota->id]),
            'urlExcluir'         => $this->generateUrl('processo_nota_tecnica_excluir', ['id' => $id, 'notaId' => $nota->id]),
            // O cartão pronto, pelo MESMO partial do Twig: uma estrutura só, nos dois caminhos.
            'html'               => $this->renderView('processo/_nota_tecnica_item.html.twig', [
                'nota'               => $nota,
                'mostrarMovimentacao' => $publicacao === null,
            ]),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{notaId}/editar', name: 'processo_nota_tecnica_editar', requirements: ['notaId' => '\d+'], methods: ['POST'])]
    public function editar(int $id, int $notaId, Request $request, SanitizadorTextoRico $sanitizador): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $tenant      = $this->tenantContext->getCurrentTenant();

        $processo = $tenant !== null ? $this->processoRepository->findOneByIdDoTenant($id, $tenant) : null;
        if ($tenant === null || $processo === null) {
            return $this->json(['erro' => 'Processo não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        if ($negado = $this->negarSeNaoPodeAgir($request, $processo, $currentUser, $tenant)) {
            return $negado;
        }

        $nota = $this->notaDoProcesso($notaId, $processo, $tenant);
        if ($nota === null) {
            return $this->json(['erro' => 'Nota técnica não encontrada.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isCsrfTokenValid('processo_nota_tecnica_editar_' . $notaId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $saida = $this->editarUseCase->executar($nota, $currentUser, $tenant, (string) $request->request->get('conteudo', ''));
        } catch (NotaTecnicaNaoEditavelException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'conteudo'     => $saida->conteudo,
            'conteudoHtml' => $sanitizador->paraExibicao($saida->conteudo),
            'editadaEm'    => $saida->editadaEm?->format('d/m/Y H:i'),
        ]);
    }

    #[Route('/{notaId}/excluir', name: 'processo_nota_tecnica_excluir', requirements: ['notaId' => '\d+'], methods: ['POST'])]
    public function excluir(int $id, int $notaId, Request $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $tenant      = $this->tenantContext->getCurrentTenant();

        $processo = $tenant !== null ? $this->processoRepository->findOneByIdDoTenant($id, $tenant) : null;
        if ($tenant === null || $processo === null) {
            return $this->json(['erro' => 'Processo não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        if ($negado = $this->negarSeNaoPodeAgir($request, $processo, $currentUser, $tenant)) {
            return $negado;
        }

        $nota = $this->notaDoProcesso($notaId, $processo, $tenant);
        if ($nota === null) {
            return $this->json(['erro' => 'Nota técnica não encontrada.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isCsrfTokenValid('processo_nota_tecnica_excluir_' . $notaId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $this->excluirUseCase->executar($nota, $currentUser, $tenant);
        } catch (NotaTecnicaNaoExcluivelException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        }

        return $this->json(['sucesso' => true]);
    }

    /**
     * Camada 2 da guarda. `null` = pode agir; senão, a resposta de recusa (404 para pasta que
     * não é do escritório ou não vincula o processo — não revela que existe; 403 sem permissão).
     */
    private function negarSeNaoPodeAgir(Request $request, Processo $processo, User $user, Tenant $tenant): ?JsonResponse
    {
        $pastaId = $request->request->getInt('pasta_id');

        if ($pastaId > 0) {
            // find() por PK não passa pelo TenantFilter: o dono da pasta é conferido explicitamente.
            $pasta = $this->pastaRepository->find($pastaId);
            if ($pasta === null || $pasta->getTenant() !== $tenant || !$pasta->temProcesso($processo)) {
                return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
            }

            if (!$this->permissionChecker->canAccessResource($user, $tenant, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_EDIT)) {
                return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
            }

            // Pasta excluída (lápide) é somente-leitura — o MESMO critério (`estaExcluida()`) e a
            // mesma mensagem do `PastaSomenteLeituraListener`, que não alcança esta rota: ela
            // recebe o processo, e a pasta chega só pelo `pasta_id` do corpo.
            if ($pasta->estaExcluida()) {
                return $this->json(['erro' => self::PASTA_SOMENTE_LEITURA], Response::HTTP_FORBIDDEN);
            }

            return null;
        }

        if (!$this->permissionChecker->canAccessResource($user, $tenant, AccessRequest::RESOURCE_PROCESSO, (int) $processo->getId(), AccessRequest::ACTION_EDIT)) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    /** A nota precisa ser do escritório E deste processo — o id na URL não abre nota de outro. */
    private function notaDoProcesso(int $notaId, Processo $processo, Tenant $tenant): ?NotaTecnica
    {
        $nota = $this->notaRepository->findOneByIdDoTenant($notaId, $tenant);
        if ($nota === null || $nota->getProcesso()->getId() !== $processo->getId()) {
            return null;
        }

        return $nota;
    }
}
