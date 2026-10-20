<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Attribute\PastaPelaFilha;
use App\Pasta\DTO\EditarDocumentoDaPastaInput;
use App\Pasta\DTO\ExploradorDeDocumentosOutput;
use App\Pasta\DTO\ZipDeDocumentosOutput;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\Repository\PastaDocumentoFavoritoRepository;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Repository\PastaSecaoRepository;
use App\Pasta\Service\VagasDeZipDoEscritorio;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use App\Pasta\UseCase\AlternarFavoritoDeDocumentoUseCase;
use App\Pasta\UseCase\CopiarDocumentosDaPastaUseCase;
use App\Pasta\UseCase\EditarDocumentoDaPastaUseCase;
use App\Pasta\UseCase\ExcluirItensDaPastaUseCase;
use App\Pasta\UseCase\ListarLixeiraDaPastaUseCase;
use App\Pasta\UseCase\MontarZipDeDocumentosUseCase;
use App\Pasta\UseCase\MoverItensDaPastaUseCase;
use App\Pasta\UseCase\RestaurarItensDaPastaUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Shared\Doctrine\Filter\AcessoALixeira;
use App\Shared\Http\EntregaDeArquivoGerado;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * O documento da pasta na aba Documentos: editar (D3), as ações em lote mover/excluir (D4) e a
 * lixeira — restaurar e listar (D7).
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
 * Lixeira (D7): `excluir-lote` não apaga mais nada — marca a lápide (`excluido_em`/`por`), e a
 * resposta ganha `lixeira: true` e os ids afetados para o "Desfazer". `restaurar` recebe os mesmos
 * ids e token, e prova a posse procurando os itens NA LIXEIRA da pasta — a consulta e o UseCase
 * rodam dentro de `AcessoALixeira::comLixeiraVisivel()`, o único jeito de enxergá-la. `lixeira`
 * (GET, JSON) lista o que está lá para a UI. As três exigem a permissão de EDITAR: restaura quem
 * pode excluir (S-10).
 *
 * A estrela (D2, `pasta_documentos_favorito`) é a exceção à permissão de EDITAR: favorito é
 * preferência pessoal, basta VER a pasta (como `PastaFavoritoController`). Token por pasta
 * (`pex_favorito_<pastaId>`); tipo e id do alvo no corpo; posse provada por consulta escopada.
 *
 * `zip` (D5) é a outra exceção: quem pode VER a pasta pode baixar o que vê. Mesmo corpo e token
 * do lote (`{_token, documentos[], secoes[]}`), por `<form target=_blank>` — a resposta é o
 * próprio .zip (download em stream, apagado depois de enviado); a recusa é JSON para XHR e, para o
 * formulário da tela, uma página HTML curta no mesmo status (DOC-140). `copiar` (D6) exige
 * EDITAR: `{_token, documentos[], destinoId|null}`, só arquivos, e responde `{ok, copiados: [<arquivo na forma do explorador>]}`.
 */
#[Route('/pasta')]
final class PastaDocumentoController extends AbstractController
{
    /** Ids (documentos + subpastas) aceitos numa ação em lote; acima disso é 422 sem consulta. */
    public const TETO_DE_ITENS_POR_LOTE = 2000;

    /** O cookie que confirma à aba da pasta que o .zip começou a ser enviado (DOC-116). */
    public const PREFIXO_DO_COOKIE_DE_CONFIRMACAO = 'pex_zip_';

    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly PastaDocumentoRepository $documentos,
        private readonly PastaSecaoRepository $secoes,
        private readonly EditarDocumentoDaPastaUseCase $editarUseCase,
        private readonly MoverItensDaPastaUseCase $moverItensUseCase,
        private readonly ExcluirItensDaPastaUseCase $excluirItensUseCase,
        private readonly RestaurarItensDaPastaUseCase $restaurarItensUseCase,
        private readonly ListarLixeiraDaPastaUseCase $listarLixeiraUseCase,
        private readonly AcessoALixeira $lixeira,
        private readonly AlternarFavoritoDeDocumentoUseCase $favoritoUseCase,
        private readonly PastaDocumentoFavoritoRepository $favoritos,
        private readonly MontarZipDeDocumentosUseCase $montarZipUseCase,
        private readonly CopiarDocumentosDaPastaUseCase $copiarUseCase,
        private readonly EntregaDeArquivoGerado $entregaGerada,
        // `limiter.pasta_documentos_zip` (SEC-004). Interface, não a classe concreta: a injeção
        // de `RateLimiterFactory` por autowiring é deprecada no Symfony 7.4 (e deixa trocar por
        // dublê em teste, como no AnaliseDaPastaController).
        private readonly RateLimiterFactoryInterface $pastaDocumentosZipLimiter,
        // SEC-004b: no máximo 2 .zips simultâneos por escritório (trava não bloqueante).
        private readonly VagasDeZipDoEscritorio $vagasDeZip,
        private readonly VisibilidadeDasAbasDaPasta $visibilidadeDasAbas,
    ) {
    }

    #[Route('/documento/{id}/editar', name: 'pasta_documento_edit', methods: ['POST'])]
    #[PastaPelaFilha(entidade: PastaDocumento::class, argumento: 'id')]
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

        $destino = $this->destinoDoLote($carga, $pasta, $tenant);
        if ($destino === false) {
            return $this->json(['erro' => 'Pasta de destino não encontrada.'], Response::HTTP_NOT_FOUND);
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

    /**
     * Manda a seleção para a LIXEIRA (D7). A resposta mantém as chaves de antes (`ok`,
     * `documentosRemovidos`, `subpastasRemovidas`, `arquivosRemovidos`) e ganha `lixeira: true` e
     * `ids` — o que o "Desfazer" manda de volta a `restaurar`. Nada sai do disco aqui: a linha e
     * o arquivo ficam até a purga.
     */
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

        return $this->json([
            'ok'                  => true,
            'lixeira'             => true,
            'documentosRemovidos' => $resultado->documentosRemovidos,
            'subpastasRemovidas'  => $resultado->subpastasRemovidas,
            'arquivosRemovidos'   => $resultado->arquivosRemovidos,
            'ids'                 => ['documentos' => $resultado->idsDocumentos, 'secoes' => $resultado->idsSecoes],
        ]);
    }

    /**
     * Tira da lixeira (D7): mesmo corpo e token do lote (`{_token, documentos[], secoes[]}`), mesma
     * permissão de quem exclui (S-10). A posse é provada procurando os ids NA LIXEIRA desta pasta
     * e deste escritório — item vivo, de pasta irmã ou de outro escritório → 404 sem efeito.
     * Consulta, UseCase e `flush` rodam dentro do escopo da `AcessoALixeira`; o filtro volta
     * antes da resposta. Resposta: `{ok, restaurados: {documentos, secoes}, paraARaiz}`.
     */
    #[Route('/{id}/documentos/restaurar', name: 'pasta_documentos_restaurar', methods: ['POST'])]
    public function restaurar(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $tenant      = $this->tenantContext->getCurrentTenant();

        return $this->lixeira->comLixeiraVisivel(function () use ($pasta, $currentUser, $tenant, $request): JsonResponse {
            $lote = $this->loteAutorizado($pasta, $currentUser, $tenant, $request, naLixeira: true);
            if ($lote instanceof JsonResponse) {
                return $lote;
            }
            [$documentos, $secoes, , $tenant] = $lote;

            try {
                $resultado = $this->restaurarItensUseCase->executar($pasta, $documentos, $secoes, $currentUser, $tenant);
            } catch (\InvalidArgumentException $e) {
                return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            } catch (AccessDeniedException) {
                return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
            }

            return $this->json([
                'ok'          => true,
                'restaurados' => ['documentos' => $resultado->documentos, 'secoes' => $resultado->secoes],
                'paraARaiz'   => $resultado->paraARaiz,
            ]);
        });
    }

    /**
     * A lixeira desta pasta, em JSON, para a UI (D7): itens com nome, tipo, excluído em/por e o
     * caminho original. Permissão de EDITAR (quem pode excluir e restaurar). Pasta de outro
     * escritório → 404 (o resolver busca por PK; o tenant é conferido aqui).
     */
    #[Route('/{id}/documentos/lixeira', name: 'pasta_documentos_lixeira', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function lixeira(Pasta $pasta): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $tenant      = $this->tenantContext->getCurrentTenant();
        $pastaId     = (int) $pasta->getId();

        if ($tenant === null || $pasta->getTenant() !== $tenant) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->permissionChecker->canAccessResource($currentUser, $tenant, 'pasta', $pastaId, 'edit')) {
            return $this->json(['erro' => 'Sem permissão para editar esta pasta.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $lixeira = $this->listarLixeiraUseCase->executar($pasta, $tenant, $this->abasOcultas($pasta, $currentUser));
        } catch (AccessDeniedException) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['ok' => true] + $lixeira->paraJson());
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
        if ($alvo === null || ($alvo instanceof PastaDocumento && $this->temContratoOculto([$alvo], $pasta, $currentUser))) {
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

    /**
     * O .zip de uma seleção (D5): corpo e token do lote, permissão de VER. Os tetos (500 arquivos /
     * 1 GB pela soma de `tamanho_bytes`) são conferidos no UseCase ANTES de abrir arquivo → 422 com
     * a mensagem. Subpasta selecionada leva a subárvore viva; o que está na lixeira nunca entra. A
     * resposta é o arquivo em stream, apagado depois de enviado (`EntregaDeArquivoGerado`); o
     * registro no `audit_log` é do UseCase.
     *
     * SEC-004: cada .zip lê até 1 GB, grava um temporário do mesmo tamanho e prende um worker do
     * PHP até o fim do envio — com o pool pequeno da VPS, um punhado de cliques derruba o sistema
     * do escritório. Por isso há um limitador por USUÁRIO (`pasta_documentos_zip`, 3 por minuto),
     * consumido DEPOIS dos guardas do lote (pedido recusado por CSRF, posse ou permissão não gasta
     * a cota de ninguém) e ANTES do UseCase, que é a parte cara. Estourou → 429 em JSON, com
     * `Retry-After` em segundos.
     *
     * SEC-004b: a cota é por usuário, então vários colegas juntos ainda prenderiam todos os
     * workers. Depois do limitador, o pedido ocupa uma das 2 vagas de .zip do ESCRITÓRIO
     * (`VagasDeZipDoEscritorio`, sem esperar). Sem vaga → 429 em JSON. Com vaga, ela é devolvida
     * no `kernel.terminate` — depois de o .zip inteiro ter sido enviado — ou aqui mesmo, quando o
     * UseCase recusa e não há .zip a enviar. Ordem: guardas → limitador → vaga → UseCase.
     *
     * DOC-140: a tela pede o .zip por `<form target=_blank>` — sem `X-Requested-With`. Para esse
     * pedido, QUALQUER recusa (400/403/404/422/429) vira uma página HTML mínima em pt-BR, com a
     * mensagem e um "Fechar", no MESMO status (e o mesmo `Retry-After`), em vez do JSON cru na aba
     * nova. Pedido XHR continua recebendo o JSON de sempre. O download em stream não muda.
     */
    #[Route('/{id}/documentos/zip', name: 'pasta_documentos_zip', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function zip(Pasta $pasta, Request $request): Response
    {
        $resposta = $this->respostaDoZip($pasta, $request);
        if ($resposta instanceof JsonResponse && $resposta->getStatusCode() >= 400 && !$request->isXmlHttpRequest()) {
            return $this->paginaDeRecusaDoZip($resposta);
        }

        return $resposta;
    }

    /**
     * A página da recusa do .zip (DOC-140): a mensagem que o JSON traria — escrita pelo próprio
     * controller ou pelo UseCase, nunca dado do pedido —, escapada pelo Twig, com o status e o
     * `Retry-After` do JSON. Não repete id, NUP nem nome de arquivo.
     */
    private function paginaDeRecusaDoZip(JsonResponse $json): Response
    {
        $dados    = json_decode((string) $json->getContent(), true);
        $mensagem = is_array($dados) && is_string($dados['erro'] ?? null) && $dados['erro'] !== ''
            ? $dados['erro']
            : 'Não foi possível preparar o .zip.';

        $pagina = $this->render('pasta/zip_recusado.html.twig', ['mensagem' => $mensagem], new Response('', $json->getStatusCode()));
        if ($json->headers->has('Retry-After')) {
            $pagina->headers->set('Retry-After', (string) $json->headers->get('Retry-After'));
        }

        return $pagina;
    }

    private function respostaDoZip(Pasta $pasta, Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $tenant      = $this->tenantContext->getCurrentTenant();

        $lote = $this->loteAutorizado($pasta, $currentUser, $tenant, $request, acao: AccessRequest::ACTION_VIEW);
        if ($lote instanceof JsonResponse) {
            return $lote;
        }
        [$documentos, $secoes, , $tenant] = $lote;

        $cota = $this->pastaDocumentosZipLimiter->create((string) $currentUser->getId())->consume();
        if (!$cota->isAccepted()) {
            $resposta = $this->json(
                ['erro' => 'Muitos pedidos de .zip em sequência. Aguarde um minuto e tente de novo.'],
                Response::HTTP_TOO_MANY_REQUESTS,
            );
            $resposta->headers->set('Retry-After', (string) max(1, $cota->getRetryAfter()->getTimestamp() - time()));

            return $resposta;
        }

        $vaga = $this->vagasDeZip->ocupar($tenant);
        if ($vaga === null) {
            return $this->json(
                ['erro' => 'Já há downloads em .zip em andamento neste escritório. Tente de novo em instantes.'],
                Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        try {
            $saida = $this->montarZipUseCase->executar($pasta, $documentos, $secoes, $currentUser, $tenant, $this->abasOcultas($pasta, $currentUser));
        } catch (\InvalidArgumentException $e) {
            $vaga->liberar();

            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (AccessDeniedException) {
            $vaga->liberar();

            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        $resposta = $this->entregaGerada->resposta($saida->arquivo, $saida->nomeDoZip, 'application/zip');
        // DOC-116: o nome do desenho tem espaço e pode ter acento — `filename*` em UTF-8 e o
        // `filename` ASCII transliterado ("Seção" → "Secao"), em vez do "_" que o Symfony poria.
        $resposta->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $saida->nomeDoZip,
            MontarZipDeDocumentosUseCase::nomeAsciiDoZip($saida->nomeDoZip),
        );
        $this->confirmarDownloadNaTela($resposta, $request, $saida);

        return $resposta;
    }

    /**
     * O "Baixando <nome> · N arquivos, M pastas" do desenho (DOC-116) só pode aparecer quando o
     * SERVIDOR confirmou: o .zip sai por `<form target=_blank>` e a aba da pasta não vê a resposta.
     * A tela manda um `confirmacao` aleatório; quando o .zip começa a ser enviado, vai junto um
     * cookie de 60 s `pex_zip_<confirmacao>` com o nome e as contagens, que a aba da pasta lê e
     * apaga. Sem `confirmacao` válido, nada é posto. O cookie não leva nada que o usuário já não
     * veja (nome do arquivo e contagens) e é `SameSite=Strict`.
     */
    private function confirmarDownloadNaTela(Response $resposta, Request $request, ZipDeDocumentosOutput $saida): void
    {
        $confirmacao = $request->request->get('confirmacao');
        if (!is_string($confirmacao) || preg_match('/^[A-Za-z0-9]{8,40}$/', $confirmacao) !== 1) {
            return;
        }

        $resposta->headers->setCookie(Cookie::create(
            self::PREFIXO_DO_COOKIE_DE_CONFIRMACAO . $confirmacao,
            json_encode(['nome' => $saida->nomeDoZip, 'arquivos' => $saida->arquivos, 'pastas' => $saida->pastas], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            time() + 60,
            '/',
            null,
            $request->isSecure(),
            false, // a aba da pasta lê o cookie por JS
            false,
            Cookie::SAMESITE_STRICT,
        ));
    }

    /**
     * Copia documentos para uma subpasta desta pasta ou para a raiz (D6): `{_token, documentos[],
     * destinoId|null}`, permissão de EDITAR. Só arquivos — `secoes[]` no corpo é 422. O destino
     * segue a regra do mover-lote (ausente/null/'' = raiz; id de pasta irmã, de outro escritório
     * ou na lixeira → 404). Resposta: `{ok, copiados: [<arquivo na forma do explorador>]}` — a tela
     * insere as linhas sem recarregar.
     */
    #[Route('/{id}/documentos/copiar', name: 'pasta_documentos_copiar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function copiar(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $tenant      = $this->tenantContext->getCurrentTenant();

        $lote = $this->loteAutorizado($pasta, $currentUser, $tenant, $request);
        if ($lote instanceof JsonResponse) {
            return $lote;
        }
        [$documentos, $secoes, $carga, $tenant] = $lote;

        if ($secoes !== []) {
            return $this->json(['erro' => 'Só arquivos podem ser copiados.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $destino = $this->destinoDoLote($carga, $pasta, $tenant);
        if ($destino === false) {
            return $this->json(['erro' => 'Pasta de destino não encontrada.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $resultado = $this->copiarUseCase->executar($pasta, $documentos, $destino, $currentUser, $tenant);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (AccessDeniedException) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json([
            'ok'       => true,
            'copiados' => array_map(fn (PastaDocumento $copia): array => $this->arquivoParaATela($copia, false), $resultado->copias),
        ]);
    }

    /** `marcado` do corpo: 1/0 (int ou string, como o form manda) ou booleano do JSON. O resto é NULL. */
    /**
     * As abas desta pasta ocultas para quem pede (spec pasta-ocultar-aba).
     *
     * @return list<string>
     */
    private function abasOcultas(Pasta $pasta, User $usuario): array
    {
        return array_map(static fn (AbaDaPasta $a): string => $a->value, $this->visibilidadeDasAbas->abasOcultasPara($pasta, $usuario));
    }

    /**
     * Algum dos documentos é contrato de honorários com o Financeiro oculto para quem pede? A ação
     * em lote o trata como id inexistente: 404, sem confirmar que ele existe (regra única
     * `VisibilidadeDasAbasDaPasta::documentoVisivel`).
     *
     * @param list<PastaDocumento> $documentos
     */
    private function temContratoOculto(array $documentos, Pasta $pasta, User $usuario): bool
    {
        if ($documentos === []) {
            return false;
        }

        return \count(VisibilidadeDasAbasDaPasta::documentosVisiveis($documentos, $this->abasOcultas($pasta, $usuario))) !== \count($documentos);
    }

    private static function booleanoDoCorpo(mixed $valor): ?bool
    {
        return match ($valor) {
            1, '1', true   => true,
            0, '0', false  => false,
            default        => null,
        };
    }

    /**
     * O prólogo comum das ações em lote: pasta deste escritório (404 — o resolver busca por PK e
     * o TenantFilter não se aplica a `find()`), permissão sobre a pasta (`$acao`: EDITAR por
     * padrão; VER no .zip), CSRF `pex_lote_<id>`, ids do corpo e posse de TODOS eles provada numa
     * consulta por tipo. Qualquer id que não seja desta pasta e deste escritório → 404 antes de
     * qualquer efeito.
     *
     * Com `$naLixeira`, a posse é provada entre os itens NA LIXEIRA da pasta (restaurar): um id
     * de item vivo responde o mesmo 404 — não há o que restaurar nele, e a tela não precisa
     * distinguir. Quem chama assim precisa estar dentro de `AcessoALixeira::comLixeiraVisivel()`.
     *
     * @return JsonResponse|array{list<PastaDocumento>, list<PastaSecao>, array<string, mixed>, Tenant}
     */
    private function loteAutorizado(Pasta $pasta, User $currentUser, ?Tenant $tenant, Request $request, bool $naLixeira = false, string $acao = AccessRequest::ACTION_EDIT): JsonResponse|array
    {
        $pastaId = (int) $pasta->getId();

        if ($tenant === null || $pasta->getTenant() !== $tenant) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->permissionChecker->canAccessResource($currentUser, $tenant, AccessRequest::RESOURCE_PASTA, $pastaId, $acao)) {
            return $this->json(
                ['erro' => $acao === AccessRequest::ACTION_VIEW ? 'Sem permissão para ver esta pasta.' : 'Sem permissão para editar esta pasta.'],
                Response::HTTP_FORBIDDEN,
            );
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

        $documentos = $naLixeira
            ? $this->documentos->findNaLixeiraDaPasta($idsDocumentos, $pasta, $tenant)
            : $this->documentos->findTodosDaPasta($idsDocumentos, $pasta, $tenant);
        // Contrato com o Financeiro oculto responde como id que não existe (ocultar aba).
        if (count($documentos) !== count($idsDocumentos) || $this->temContratoOculto($documentos, $pasta, $currentUser)) {
            return $this->json(['erro' => 'Documento não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        $secoes = $naLixeira
            ? $this->secoes->findNaLixeiraDaPasta($idsSecoes, $pasta, $tenant)
            : $this->secoes->findTodasDaPasta($idsSecoes, $pasta, $tenant);
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

    /**
     * O `destinoId` do corpo resolvido numa subpasta DESTA pasta e deste escritório (mover-lote e
     * copiar): NULL = raiz; FALSE = não encontrado — valor que não é id (um `(int)` cego
     * transformaria "abc" em raiz e moveria tudo para lá em silêncio), id de subpasta de outra
     * pasta (irmã ou de outro escritório) ou na lixeira. 404 sem dizer se existe.
     *
     * @param array<string, mixed> $carga
     */
    private function destinoDoLote(array $carga, Pasta $pasta, Tenant $tenant): PastaSecao|null|false
    {
        $destinoId = self::idDeDestino($carga['destinoId'] ?? null);
        if ($destinoId === false) {
            return false;
        }
        if ($destinoId === null) {
            return null;
        }

        return $this->secoes->findByIdAndPastaAndTenant($destinoId, $pasta, $tenant) ?? false;
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
