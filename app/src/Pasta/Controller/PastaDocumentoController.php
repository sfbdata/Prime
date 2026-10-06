<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\EditarDocumentoDaPastaInput;
use App\Pasta\DTO\ExploradorDeDocumentosOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\Repository\PastaDocumentoFavoritoRepository;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Repository\PastaSecaoRepository;
use App\Pasta\UseCase\AlternarFavoritoDeDocumentoUseCase;
use App\Pasta\UseCase\EditarDocumentoDaPastaUseCase;
use App\Pasta\UseCase\ExcluirItensDaPastaUseCase;
use App\Pasta\UseCase\MoverItensDaPastaUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * O documento da pasta na aba Documentos: editar (D3) e as ações em lote mover/excluir (D4).
 *
 * Padrão das rotas: permissão de EDITAR a pasta (`canAccessResource`), CSRF, posse provada por
 * consulta escopada em pasta + tenant (404 — nunca 403 — para o que não é desta pasta: não se
 * confirma a existência de um documento de outro escritório, nem de uma pasta irmã), e UseCase.
 *
 * `editar` é a rota `pasta_documento_edit` de sempre (mesmo caminho, nome, método e token): com
 * `X-Requested-With: XMLHttpRequest` responde JSON com o documento atualizado, na forma que o
 * explorador consome; sem, redireciona para a aba (`#documentos`) como fazia.
 *
 * O lote usa UM token por pasta (`pex_lote_<pastaId>`), e os ids vão no corpo — form ou JSON.
 *
 * A estrela (D2, `pasta_documentos_favorito`) é a exceção à permissão de EDITAR: favorito é
 * preferência pessoal, basta VER a pasta (como `PastaFavoritoController`). Token por pasta
 * (`pex_favorito_<pastaId>`); tipo e id do alvo no corpo; posse provada por consulta escopada.
 */
#[Route('/pasta')]
final class PastaDocumentoController extends AbstractController
{
    /** Ids (documentos + subpastas) aceitos numa ação em lote; acima disso é 422 sem consulta. */
    public const TETO_DE_ITENS_POR_LOTE = 2000;

    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly RemocaoAposTransacao $remocao,
        private readonly PastaDocumentoRepository $documentos,
        private readonly PastaSecaoRepository $secoes,
        private readonly EditarDocumentoDaPastaUseCase $editarUseCase,
        private readonly MoverItensDaPastaUseCase $moverItensUseCase,
        private readonly ExcluirItensDaPastaUseCase $excluirItensUseCase,
        private readonly AlternarFavoritoDeDocumentoUseCase $favoritoUseCase,
        private readonly PastaDocumentoFavoritoRepository $favoritos,
    ) {
    }

    #[Route('/documento/{id}/editar', name: 'pasta_documento_edit', methods: ['POST'])]
    public function editar(int $id, Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $tenant      = $this->tenantContext->getCurrentTenant();
        $xhr         = $request->isXmlHttpRequest();

        $documento = $tenant !== null ? $this->documentos->findByIdAndTenant($id, $tenant) : null;
        if ($documento === null || $tenant === null) {
            return $this->erroDaEdicao($xhr, 'Documento não encontrado.', Response::HTTP_NOT_FOUND);
        }

        $pastaId = (int) $documento->getPasta()?->getId();
        if (!$this->permissionChecker->canAccessResource($currentUser, $tenant, 'pasta', $pastaId, 'edit')) {
            return $this->erroDaEdicao($xhr, 'Você não tem permissão para editar documentos desta pasta.', Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('edit_documento_' . $id, (string) $request->request->get('_token'))) {
            // Sem XHR o comportamento é o de sempre (403); em JSON, o mesmo 400 das outras rotas da aba.
            return $this->erroDaEdicao($xhr, 'Token de segurança inválido.', $xhr ? Response::HTTP_BAD_REQUEST : Response::HTTP_FORBIDDEN);
        }

        $input = new EditarDocumentoDaPastaInput(
            categoria: $this->textoOuNull($request, 'categoria'),
            descricao: $this->textoOuNull($request, 'descricao'),
            numero: $this->textoOuNull($request, 'numero'),
            nomeBase: $this->textoOuNull($request, 'nomeBase'),
        );

        try {
            $this->editarUseCase->executar($documento, $currentUser, $tenant, $input);
        } catch (\InvalidArgumentException $e) {
            if ($xhr) {
                return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('pasta_show', ['id' => $pastaId, '_fragment' => 'documentos']);
        }

        if ($xhr) {
            // A estrela vai com o estado real: a tela substitui a linha pelo que vier aqui, e um
            // `favorito: false` fixo apagaria a estrela de quem tinha marcado (e a hora, a
            // posição dela no topo).
            $favoritoEm = $this->favoritos->marcadoEm($documento, $currentUser, $tenant);

            return $this->json(['ok' => true, 'documento' => $this->arquivoParaATela($documento, $favoritoEm !== null, $favoritoEm)]);
        }

        $this->addFlash('success', 'Documento atualizado com sucesso.');

        // `#documentos`: o `pasta-show.js` abre a aba do fragmento — é o que devolve o usuário
        // à lista de onde ele editou, sem depender de flag em sessionStorage.
        return $this->redirectToRoute('pasta_show', ['id' => $pastaId, '_fragment' => 'documentos']);
    }

    #[Route('/{id}/documentos/mover-lote', name: 'pasta_documentos_mover_lote', methods: ['POST'])]
    public function moverLote(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $tenant      = $this->tenantContext->getCurrentTenant();

        $lote = $this->loteAutorizado($pasta, $currentUser, $tenant, $request);
        if ($lote instanceof JsonResponse) {
            return $lote;
        }
        [$documentos, $secoes, $carga, $tenant] = $lote;

        // `destinoId` ausente, null ou '' = raiz. Qualquer outro valor só vale se for um id
        // (a mesma regra de `idsInteiros`): "0", negativo, texto ou array → 404, sem efeito —
        // um `(int)` cego transformaria "abc" em raiz e moveria tudo para lá em silêncio.
        $destino   = null;
        $destinoId = self::idDeDestino($carga['destinoId'] ?? null);
        if ($destinoId === false) {
            return $this->json(['erro' => 'Pasta de destino não encontrada.'], Response::HTTP_NOT_FOUND);
        }
        if ($destinoId !== null) {
            // Escopada por pasta + tenant: um id de subpasta de outra pasta (irmã ou de outro
            // escritório) não é encontrado — 404, sem dizer se existe.
            $destino = $this->secoes->findByIdAndPastaAndTenant($destinoId, $pasta, $tenant);
            if ($destino === null) {
                return $this->json(['erro' => 'Pasta de destino não encontrada.'], Response::HTTP_NOT_FOUND);
            }
        }

        try {
            $resultado = $this->moverItensUseCase->executar($pasta, $documentos, $secoes, $destino, $currentUser, $tenant);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (AccessDeniedException) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json([
            'ok'        => true,
            'movidos'   => ['documentos' => $resultado->documentos, 'secoes' => $resultado->secoes],
            'destinoId' => $resultado->destinoId,
        ]);
    }

    #[Route('/{id}/documentos/excluir-lote', name: 'pasta_documentos_excluir_lote', methods: ['POST'])]
    public function excluirLote(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $tenant      = $this->tenantContext->getCurrentTenant();

        $lote = $this->loteAutorizado($pasta, $currentUser, $tenant, $request);
        if ($lote instanceof JsonResponse) {
            return $lote;
        }
        [$documentos, $secoes, , $tenant] = $lote;

        try {
            $resultado = $this->excluirItensUseCase->executar($pasta, $documentos, $secoes, $currentUser, $tenant);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (AccessDeniedException) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        // Só DEPOIS do COMMIT (INV-6): as linhas já saíram; uma falha de disco aqui vira registro
        // no log, não 500 — o mesmo mecanismo de `pasta_documento_delete` e `pasta_secao_excluir`.
        $this->remocao->remover($resultado->chaves, 'PastaDocumentoController::excluirLote');

        return $this->json([
            'ok'                  => true,
            'documentosRemovidos' => $resultado->documentosRemovidos,
            'subpastasRemovidas'  => $resultado->subpastasRemovidas,
            'arquivosRemovidos'   => $resultado->arquivosRemovidos,
        ]);
    }

    /**
     * Liga/desliga a estrela de um arquivo (`tipo: documento`) ou de uma subpasta (`tipo: pasta`)
     * para o usuário logado. Corpo (form ou JSON): `{_token, tipo, alvoId, marcado: 1|0}`.
     * Idempotente: o pedido diz o estado desejado. Resposta: `{ok: true, marcado: bool,
     * favoritoEm: string|null}` — a hora em que ficou marcado (a de antes, se já estava), NULL
     * quando desmarcado; a tela ordena o topo por ela.
     *
     * Guardas, nesta ordem: pasta deste escritório (404 — o resolver busca por PK e o TenantFilter
     * não se aplica a `find()`); permissão de VER (403); CSRF `pex_favorito_<id>` (400, o mesmo das
     * outras rotas JSON da aba); corpo válido (422); alvo DESTA pasta e deste escritório (404 —
     * id de pasta irmã ou de outro escritório não é encontrado, sem dizer se existe).
     */
    #[Route('/{id}/documentos/favorito', name: 'pasta_documentos_favorito', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function favorito(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $tenant      = $this->tenantContext->getCurrentTenant();
        $pastaId     = (int) $pasta->getId();

        if ($tenant === null || $pasta->getTenant() !== $tenant) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->permissionChecker->canAccessResource($currentUser, $tenant, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_VIEW)) {
            return $this->json(['erro' => 'Sem permissão para ver esta pasta.'], Response::HTTP_FORBIDDEN);
        }

        $carga = $this->cargaDoLote($request);
        $token = $carga['_token'] ?? '';
        if (!$this->isCsrfTokenValid(ExploradorDeDocumentosOutput::idDoTokenDeFavorito($pastaId), is_string($token) ? $token : '')) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_BAD_REQUEST);
        }

        $tipo    = $carga['tipo'] ?? null;
        $marcado = self::booleanoDoCorpo($carga['marcado'] ?? null);
        if (($tipo !== 'documento' && $tipo !== 'pasta') || $marcado === null) {
            return $this->json(['erro' => 'Pedido inválido.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $alvoId = self::idOuNull($carga['alvoId'] ?? null);
        $alvo   = null;
        if ($alvoId !== null) {
            $alvo = $tipo === 'documento'
                ? ($this->documentos->findTodosDaPasta([$alvoId], $pasta, $tenant)[0] ?? null)
                : $this->secoes->findByIdAndPastaAndTenant($alvoId, $pasta, $tenant);
        }
        if ($alvo === null) {
            return $this->json(['erro' => $tipo === 'documento' ? 'Documento não encontrado.' : 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $marcadoAgora = $this->favoritoUseCase->executar($pasta, $alvo, $marcado, $currentUser, $tenant);
        } catch (PastaDeOutroEscritorioException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (SemPermissaoParaVerPastaException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        }

        return $this->json([
            'ok'         => true,
            'marcado'    => $marcadoAgora,
            'favoritoEm' => $marcadoAgora ? $this->favoritos->marcadoEm($alvo, $currentUser, $tenant) : null,
        ]);
    }

    /** `marcado` do corpo: 1/0 (int ou string, como o form manda) ou booleano do JSON. O resto é NULL. */
    private static function booleanoDoCorpo(mixed $valor): ?bool
    {
        return match ($valor) {
            1, '1', true   => true,
            0, '0', false  => false,
            default        => null,
        };
    }

    /**
     * O prólogo comum das duas ações em lote: permissão de edição da pasta, CSRF `pex_lote_<id>`,
     * ids do corpo e posse de TODOS eles provada numa consulta por tipo. Qualquer id que não seja
     * desta pasta e deste escritório → 404 antes de qualquer efeito.
     *
     * @return JsonResponse|array{list<PastaDocumento>, list<PastaSecao>, array<string, mixed>, Tenant}
     */
    private function loteAutorizado(Pasta $pasta, User $currentUser, ?Tenant $tenant, Request $request): JsonResponse|array
    {
        $pastaId = (int) $pasta->getId();

        if ($tenant === null || !$this->permissionChecker->canAccessResource($currentUser, $tenant, 'pasta', $pastaId, 'edit')) {
            return $this->json(['erro' => 'Sem permissão para editar esta pasta.'], Response::HTTP_FORBIDDEN);
        }

        $carga = $this->cargaDoLote($request);
        if (!$this->isCsrfTokenValid(ExploradorDeDocumentosOutput::idDoTokenDeLote($pastaId), (string) ($carga['_token'] ?? ''))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_BAD_REQUEST);
        }

        // Teto ANTES de interpretar os ids e de qualquer consulta: a pasta de produção tem 1.128
        // documentos; um corpo com dezenas de milhares de ids é pedido forjado, não seleção.
        if (self::tamanhoBruto($carga['documentos'] ?? []) + self::tamanhoBruto($carga['secoes'] ?? []) > self::TETO_DE_ITENS_POR_LOTE) {
            return $this->json(
                ['erro' => sprintf('Seleção acima do limite de %d itens por ação.', self::TETO_DE_ITENS_POR_LOTE)],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $idsDocumentos = self::idsInteiros($carga['documentos'] ?? []);
        $idsSecoes     = self::idsInteiros($carga['secoes'] ?? []);
        if ($idsDocumentos === null) {
            return $this->json(['erro' => 'Documento não encontrado.'], Response::HTTP_NOT_FOUND);
        }
        if ($idsSecoes === null) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        }
        if ($idsDocumentos === [] && $idsSecoes === []) {
            return $this->json(['erro' => 'Nenhum item selecionado.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $documentos = $this->documentos->findTodosDaPasta($idsDocumentos, $pasta, $tenant);
        if (count($documentos) !== count($idsDocumentos)) {
            return $this->json(['erro' => 'Documento não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        $secoes = $this->secoes->findTodasDaPasta($idsSecoes, $pasta, $tenant);
        if (count($secoes) !== count($idsSecoes)) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        }

        return [$documentos, $secoes, $carga, $tenant];
    }

    /**
     * O corpo do lote: formulário (`documentos[]`) ou JSON — o explorador usa os dois estilos nas
     * rotas de hoje (form nos modais, JSON no reordenar).
     *
     * @return array<string, mixed>
     */
    private function cargaDoLote(Request $request): array
    {
        if ($request->getContentTypeFormat() === 'json') {
            $dados = json_decode((string) $request->getContent(), true);

            return is_array($dados) ? $dados : [];
        }

        return $request->request->all();
    }

    /**
     * Ids inteiros positivos, sem repetição — ou NULL quando algum valor não é um id (não há como
     * provar a posse do que não é id; a resposta é a mesma de id de outra pasta).
     *
     * @return list<int>|null
     */
    private static function idsInteiros(mixed $valores): ?array
    {
        if (!is_array($valores)) {
            return null;
        }

        $ids = [];
        foreach ($valores as $valor) {
            $id = self::idOuNull($valor);
            if ($id === null) {
                return null;
            }
            $ids[$id] = $id;
        }

        return array_values($ids);
    }

    /** Um id: inteiro positivo, ou string só de dígitos sem zero à esquerda. O resto não é id. */
    private static function idOuNull(mixed $valor): ?int
    {
        if (is_int($valor)) {
            return $valor >= 1 ? $valor : null;
        }

        if (is_string($valor) && preg_match('/^[1-9]\d*$/', $valor) === 1) {
            return (int) $valor;
        }

        return null;
    }

    /**
     * O destino de um mover-lote: NULL = raiz (ausente, null ou ''); int = id a provar; FALSE =
     * valor que não é id nem raiz (array, "0", negativo, texto).
     */
    private static function idDeDestino(mixed $valor): int|null|false
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return self::idOuNull($valor) ?? false;
    }

    /** Quantos valores vieram no corpo, antes de interpretá-los; o que não é lista conta zero. */
    private static function tamanhoBruto(mixed $valores): int
    {
        return is_array($valores) ? count($valores) : 0;
    }

    private function textoOuNull(Request $request, string $chave): ?string
    {
        $valor = $request->request->get($chave);

        return is_scalar($valor) ? (string) $valor : null;
    }

    private function erroDaEdicao(bool $xhr, string $mensagem, int $status): Response
    {
        if ($xhr) {
            return $this->json(['erro' => $mensagem], $status);
        }

        if ($status === Response::HTTP_NOT_FOUND) {
            throw $this->createNotFoundException($mensagem);
        }

        throw $this->createAccessDeniedException($mensagem);
    }

    /** @return array<string, mixed> */
    private function arquivoParaATela(PastaDocumento $documento, bool $favorito, ?string $favoritoEm = null): array
    {
        return ExploradorDeDocumentosOutput::arquivo(
            $documento,
            ExploradorDeDocumentosOutput::CATEGORIAS,
            fn (string $rota, array $params): string => $this->generateUrl($rota, $params),
            fn (string $idDoToken): string => $this->csrfTokenManager->getToken($idDoToken)->getValue(),
            $favorito,
            $favoritoEm,
        );
    }
}
