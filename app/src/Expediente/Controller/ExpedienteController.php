<?php

declare(strict_types=1);

namespace App\Expediente\Controller;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Expediente\DTO\CriarMarcadorDTO;
use App\Expediente\Repository\MarcadorRepository;
use App\Expediente\UseCase\CriarMarcadorUseCase;
use App\Expediente\UseCase\EditarMarcadorUseCase;
use App\Expediente\UseCase\ExcluirMarcadorUseCase;
use App\Expediente\UseCase\ReordenarMarcadorUseCase;
use App\Expediente\UseCase\SincronizarMarcadoresDaPastaUseCase;
use App\Expediente\Service\CsvDaListaDoExpediente;
use App\Pasta\Attribute\PastaPorId;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\Repository\PastaDestaqueRepository;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\NumeracaoDePastaInterface;
use App\Pasta\UseCase\AlternarTrancaDaPastaUseCase;
use App\Pasta\UseCase\DestacarPastaUseCase;
use App\Pasta\UseCase\PedirAcessoAPastaTrancadaUseCase;
use App\Pasta\UseCase\RenomearClienteDaPastaUseCase;
use App\Repository\UserRepository;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ExpedienteController extends AbstractController
{
    private const PER_PAGE = 50;

    /** "Pastas por página" do Visualizar (desenho 1.1, E12). */
    private const POR_PAGINA = [25, 50, 100];

    /** Token único das ações da Lista (F2, trancar, destacar, pedir acesso, reordenar). */
    public const CSRF_LISTA = 'expediente_lista';

    public function __construct(
        private readonly PastaRepository $pastaRepository,
        private readonly UserRepository $userRepository,
        private readonly MarcadorRepository $marcadorRepository,
        private readonly CriarMarcadorUseCase $criarMarcadorUseCase,
        private readonly ExcluirMarcadorUseCase $excluirMarcadorUseCase,
        private readonly EditarMarcadorUseCase $editarMarcadorUseCase,
        private readonly SincronizarMarcadoresDaPastaUseCase $sincronizarMarcadoresUseCase,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly ValidatorInterface $validator,
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly ReordenarMarcadorUseCase $reordenarMarcadorUseCase,
        private readonly RenomearClienteDaPastaUseCase $renomearClienteUseCase,
        private readonly AlternarTrancaDaPastaUseCase $alternarTrancaUseCase,
        private readonly DestacarPastaUseCase $destacarPastaUseCase,
        private readonly PedirAcessoAPastaTrancadaUseCase $pedirAcessoUseCase,
        private readonly PastaDestaqueRepository $destaqueRepository,
        private readonly NumeracaoDePastaInterface $numeracaoDePasta,
        private readonly CsvDaListaDoExpediente $csvDaLista,
    ) {
    }

    /**
     * Parâmetros que o deep-link `?painel=acervo-geral` repassa ao XHR do painel. Allowlist de
     * propósito: `busca` fica de fora — é texto livre (pode trazer nome de cliente, PII) e não
     * deve viajar pela barra de endereço (ver a persistência do painel no index.html.twig).
     */
    private const PARAMS_DEEP_LINK_ACERVO = [
        'status', 'responsavel', 'prioridade', 'data_de', 'data_ate', 'criado_por', 'ordenar', 'direcao', 'page',
        'expediente', 'por_pagina',
    ];

    #[Route('/expediente', name: 'expediente_index')]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user   = $this->getUser();
        $tenant = $this->assertAccess($user);

        $marcadores      = $this->marcadorRepository->findRaizPorTenant($tenant);
        $todosMarcadores = $this->marcadorRepository->findTodosPorTenant($tenant);
        $contagemPastas  = $this->pastaRepository->countPorMarcadores($tenant);

        return $this->render('expediente/index.html.twig', [
            'pastas'          => $marcadores,
            'todosMarcadores' => $todosMarcadores,
            'contagemPastas'  => $contagemPastas,
            'painelInicial'   => $this->painelInicialDoDeepLink($request),
            'contagensExpediente' => $this->pastaRepository->contagensDosFiltrosDoExpediente($tenant),
            'totalPastas'     => $this->pastaRepository->countByFilters([], $tenant),
            'souAdministrador' => $this->permissionChecker->isAdministradorDoEscritorio($user, $tenant),
        ]);
    }

    /**
     * URL do painel que a tela abre de cara quando chega por link (ex.: número clicável do
     * Dashboard): `?painel=acervo-geral&criado_por=5&data_de=...`. Devolve null sem o deep-link,
     * e a tela segue o comportamento de sempre (restaura o estado salvo ou abre o acervo limpo).
     * Os filtros só são repassados; quem os valida e aplica é o próprio endpoint do painel.
     */
    private function painelInicialDoDeepLink(Request $request): ?string
    {
        if ($request->query->get('painel') !== 'acervo-geral') {
            return null;
        }

        $query  = $request->query->all();
        $params = [];
        foreach (self::PARAMS_DEEP_LINK_ACERVO as $chave) {
            $valor = $query[$chave] ?? null;
            if (is_string($valor) && trim($valor) !== '') {
                $params[$chave] = trim($valor);
            }
        }

        return $this->generateUrl('expediente_acervo_geral', $params);
    }

    #[Route('/expediente/marcador', name: 'expediente_marcador_criar', methods: ['POST'])]
    public function criarMarcador(Request $request): JsonResponse
    {
        /** @var User $user */
        $user   = $this->getUser();
        $tenant = $this->assertAccess($user);

        if (!$this->isCsrfTokenValid('marcador', $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token inválido.'], Response::HTTP_FORBIDDEN);
        }

        $cor = $request->request->get('cor');

        $dto = new CriarMarcadorDTO(
            nome:  trim((string) $request->request->get('nome', '')),
            paiId: $request->request->get('pai_id') !== null && $request->request->get('pai_id') !== ''
                ? (int) $request->request->get('pai_id')
                : null,
            cor:   ($cor === '' || $cor === null) ? null : (string) $cor,
            descricao: $request->request->has('descricao') ? (string) $request->request->get('descricao') : null,
        );

        $erros = $this->validator->validate($dto);
        if (count($erros) > 0) {
            return $this->json(['erro' => (string) $erros->get(0)->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $marcador = $this->criarMarcadorUseCase->executar($dto, $user, $tenant);
        } catch (\DomainException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'id'          => $marcador->getId(),
            'nome'        => $marcador->getNome(),
            'cor'         => $marcador->getCor(),
            'descricao'   => $marcador->getDescricao(),
            'paiId'       => $marcador->getPai()?->getId(),
            'csrfExcluir' => $this->csrfTokenManager->getToken('excluir_marcador_' . $marcador->getId())->getValue(),
            'csrfEditar'  => $this->csrfTokenManager->getToken('editar_marcador_' . $marcador->getId())->getValue(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/expediente/marcador/{id}', name: 'expediente_marcador_editar', methods: ['PATCH'])]
    public function editarMarcador(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user   = $this->getUser();
        $tenant = $this->assertAccess($user);

        if (!$this->isCsrfTokenValid('editar_marcador_' . $id, $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token inválido.'], Response::HTTP_FORBIDDEN);
        }

        $nome = trim((string) $request->request->get('nome', ''));
        if ($nome === '' || mb_strlen($nome) > 100) {
            return $this->json(['erro' => 'Nome inválido.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $cor = $request->request->get('cor');
        $cor = ($cor === '' || $cor === null) ? null : (string) $cor;

        try {
            // "Dentro de (submarcador)" (E12) só muda o pai quando o campo vem: o modal antigo não o manda.
            $trocarPai = $request->request->has('pai_id');
            $paiId     = (string) $request->request->get('pai_id', '');
            $marcador  = $this->editarMarcadorUseCase->executar(
                $id,
                $nome,
                $cor,
                $tenant,
                $request->request->has('descricao') ? (string) $request->request->get('descricao') : null,
                $trocarPai,
                $paiId === '' ? null : (int) $paiId,
            );
        } catch (\DomainException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'id'        => $marcador->getId(),
            'nome'      => $marcador->getNome(),
            'cor'       => $marcador->getCor(),
            'descricao' => $marcador->getDescricao(),
            'paiId'     => $marcador->getPai()?->getId(),
        ]);
    }

    #[Route('/expediente/marcador/{id}', name: 'expediente_marcador_excluir', methods: ['DELETE'])]
    public function excluirMarcador(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user   = $this->getUser();
        $tenant = $this->assertAccess($user);

        if (!$this->isCsrfTokenValid('excluir_marcador_' . $id, $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token inválido.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $marcador = $this->excluirMarcadorUseCase->executar($id, $tenant);
        } catch (\DomainException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'id'    => $marcador->getId(),
            'paiId' => $marcador->getPai()?->getId(),
        ]);
    }

    #[Route('/expediente/marcador/{id}/pastas', name: 'expediente_marcador_pastas', methods: ['GET'])]
    public function pastasPorMarcador(int $id, Request $request): Response
    {
        /** @var User $user */
        $user   = $this->getUser();
        $tenant = $this->assertAccess($user);

        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('expediente_index');
        }

        $marcador = $this->marcadorRepository->findPorTenant($id, $tenant);

        if ($marcador === null) {
            return $this->json(['erro' => 'Marcador não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        $filters             = $this->filtrosDaRequest($request);
        $hasFilters          = array_filter($filters, fn($v) => $v !== '');
        [$ordenar, $direcao] = $this->ordenacaoDaRequest($request);

        $page = max(1, $request->query->getInt('page', 1));

        $totalItems = $hasFilters
            ? $this->pastaRepository->countByFiltrosEMarcador($filters, $marcador, $tenant)
            : $this->pastaRepository->countPorMarcador($marcador, $tenant);

        $porPagina  = $this->porPaginaDaRequest($request);
        $totalPages = max(1, (int) ceil($totalItems / $porPagina));
        $page       = min($page, $totalPages);

        $pastas = $hasFilters
            ? $this->pastaRepository->findByFiltrosEMarcador($filters, $marcador, $tenant, $page, $porPagina, $ordenar, $direcao, $user)
            : $this->pastaRepository->findPorMarcador($marcador, $tenant, $page, $porPagina, $ordenar, $direcao, $user);

        $urlPainel = $this->generateUrl('expediente_marcador_pastas', ['id' => $id]);

        return $this->render('expediente/_painel_marcador.html.twig', [
            'marcador'     => $marcador,
            'pastas'       => $pastas,
            'filters'      => $filters,
            'ordenar'      => $ordenar,
            'direcao'      => $direcao,
            'responsaveis' => $this->userRepository->findColaboradoresAtivosPorTenant($tenant),
            'fotosResponsaveis' => $this->userRepository->findFotoPorColaboradores($tenant),
            'formAction'   => $urlPainel,
            'pagination'   => [
                'current_page' => $page,
                'per_page'     => $porPagina,
                'total_items'  => $totalItems,
                'total_pages'  => $totalPages,
            ],
        ] + $this->extrasDaLista($pastas, $user, $tenant));
    }

    // Pasta excluída (lápide) é somente-leitura: o PastaSomenteLeituraListener recusa pelo id.
    #[Route('/expediente/pasta/{id}/marcadores', name: 'expediente_pasta_marcadores', methods: ['POST'])]
    #[PastaPorId('id')]
    public function atualizarMarcadoresDaPasta(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user   = $this->getUser();
        $tenant = $this->assertAccess($user);

        if (!$this->isCsrfTokenValid('pasta_marcadores_' . $id, $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token inválido.'], Response::HTTP_FORBIDDEN);
        }

        $marcadorIds = $request->request->all('marcadores') ?? [];
        $marcadorIds = array_map('intval', array_filter($marcadorIds, fn($v) => $v !== '' && $v !== null));

        try {
            $pasta = $this->sincronizarMarcadoresUseCase->executar($id, $marcadorIds, $tenant);
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->json(['erro' => $e->getMessage(), 'trace' => $e->getTraceAsString()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json([
            'pastaId'    => $pasta->getId(),
            // array_values é obrigatório: removeMarcador() deixa lacunas nos índices da coleção
            // e toArray()/array_map() preservam essas chaves, fazendo json_encode() serializar
            // um OBJETO ({"2":{…}}) em vez de uma lista. O JS consumidor faz marcadores.map(),
            // que estoura TypeError nesse formato — a gravação já ocorreu, mas a tela acusa
            // "Erro de comunicação." e o modal não fecha.
            'marcadores' => array_values(array_map(
                fn($m) => ['id' => $m->getId(), 'nome' => $m->getNome(), 'cor' => $m->getCor()],
                $pasta->getMarcadores()->toArray()
            )),
        ]);
    }

    #[Route('/expediente/marcador/{id}/reordenar', name: 'expediente_marcador_reordenar', methods: ['POST'])]
    public function reordenarMarcador(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user   = $this->getUser();
        $tenant = $this->assertAccess($user);

        if (!$this->isCsrfTokenValid(self::CSRF_LISTA, $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token inválido.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $this->reordenarMarcadorUseCase->executar(
                $id,
                $request->request->getInt('alvo_id'),
                $request->request->getBoolean('depois'),
                $tenant,
            );
        } catch (\DomainException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['ok' => true]);
    }

    // F2 da Lista: muda só o identificador da pasta. Lápide recusada pelo PastaSomenteLeituraListener.
    #[Route('/expediente/pasta/{id}/nome', name: 'expediente_pasta_nome', methods: ['POST'])]
    public function renomearClienteDaPasta(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var User $user */
        $user   = $this->getUser();
        $tenant = $this->assertAccess($user);

        if (!$this->isCsrfTokenValid(self::CSRF_LISTA, $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token inválido.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $nome = $this->renomearClienteUseCase->executar($pasta, (string) $request->request->get('nome', ''), $user, $tenant);
        } catch (PastaDeOutroEscritorioException) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        } catch (SemPermissaoParaEditarPastaException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (\DomainException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['nome' => $nome]);
    }

    #[Route('/expediente/pasta/{id}/trancar', name: 'expediente_pasta_trancar', methods: ['POST'])]
    public function alternarTrancaDaPasta(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var User $user */
        $user   = $this->getUser();
        $tenant = $this->assertAccess($user);

        if (!$this->isCsrfTokenValid(self::CSRF_LISTA, $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token inválido.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $trancada = $this->alternarTrancaUseCase->executar($pasta, $user, $tenant);
        } catch (PastaDeOutroEscritorioException) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        } catch (AccessDeniedException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        }

        return $this->json([
            'trancada' => $trancada,
            'por'      => $pasta->getTrancadaPor()?->getFullName(),
            'em'       => $pasta->getTrancadaEm()?->format('d/m/Y'),
        ]);
    }

    #[Route('/expediente/pasta/{id}/destaque', name: 'expediente_pasta_destaque', methods: ['POST'])]
    public function destacarPasta(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var User $user */
        $user   = $this->getUser();
        $tenant = $this->assertAccess($user);

        if (!$this->isCsrfTokenValid(self::CSRF_LISTA, $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token inválido.'], Response::HTTP_FORBIDDEN);
        }

        $cor = (string) $request->request->get('cor', '');

        try {
            $final = $this->destacarPastaUseCase->executar($pasta, $cor === '' ? null : $cor, $user, $tenant);
        } catch (PastaDeOutroEscritorioException) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        } catch (SemPermissaoParaVerPastaException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (\DomainException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['cor' => $final]);
    }

    #[Route('/expediente/pasta/{id}/pedir-acesso', name: 'expediente_pasta_pedir_acesso', methods: ['POST'])]
    public function pedirAcessoAPasta(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var User $user */
        $user   = $this->getUser();
        $tenant = $this->assertAccess($user);

        if (!$this->isCsrfTokenValid(self::CSRF_LISTA, $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token inválido.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $avisados = $this->pedirAcessoUseCase->executar($pasta, $user, $tenant, $this->generateUrl('pasta_show', ['id' => $pasta->getId()]));
        } catch (PastaDeOutroEscritorioException) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        } catch (\DomainException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['avisados' => $avisados]);
    }

    /**
     * "Exportar colunas visíveis (CSV)" (E12): todas as pastas da consulta (não só a página), com os mesmos
     * filtros, a mesma ordem e só as colunas pedidas. `marcador` restringe ao painel de um marcador.
     */
    #[Route('/expediente/exportar.csv', name: 'expediente_exportar_csv', methods: ['GET'])]
    public function exportarCsv(Request $request): Response
    {
        /** @var User $user */
        $user   = $this->getUser();
        $tenant = $this->assertAccess($user);

        $filters             = $this->filtrosDaRequest($request);
        [$ordenar, $direcao] = $this->ordenacaoDaRequest($request);
        $colunas             = array_filter(explode(',', (string) $request->query->get('colunas', '')));

        $marcadorId = $request->query->getInt('marcador');
        if ($marcadorId > 0) {
            $marcador = $this->marcadorRepository->findPorTenant($marcadorId, $tenant);
            if ($marcador === null) {
                throw $this->createNotFoundException('Marcador não encontrado.');
            }
            $total  = $this->pastaRepository->countByFiltrosEMarcador($filters, $marcador, $tenant);
            $pastas = $this->pastaRepository->findByFiltrosEMarcador($filters, $marcador, $tenant, 1, max(1, $total), $ordenar, $direcao, $user);
        } else {
            $total  = $this->pastaRepository->countByFilters($filters, $tenant);
            $pastas = $this->pastaRepository->findByFilters($filters, $tenant, 1, max(1, $total), $ordenar, $direcao, $user);
        }

        $ids = array_map(static fn (Pasta $p): int => (int) $p->getId(), $pastas);
        $csv = $this->csvDaLista->gerar($pastas, $colunas, $this->pastaRepository->reusPorPasta($ids, $tenant));

        return new Response($csv, Response::HTTP_OK, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="expediente-pastas.csv"',
            'Cache-Control'       => 'no-store',
        ]);
    }

    /**
     * Nova Pasta do desenho 1.1 (E12): o próximo número, só informativo (o servidor numera de novo ao
     * salvar, com trava), e os clientes parecidos com quantas pastas cada um já tem.
     */
    #[Route('/expediente/nova-pasta/sugestoes', name: 'expediente_nova_pasta_sugestoes', methods: ['GET'])]
    public function sugestoesDaNovaPasta(Request $request): JsonResponse
    {
        /** @var User $user */
        $user   = $this->getUser();
        $tenant = $this->assertAccess($user);

        $nomes = $this->pastaRepository->nomesDeClienteParecidos((string) $request->query->get('q', ''), $tenant);

        return $this->json([
            'proximoNumero' => (string) ($this->numeracaoDePasta->maiorNumero($tenant) + 1),
            'clientes'      => array_map(
                static fn (string $nome, int $total): array => ['nome' => $nome, 'total' => $total],
                array_keys($nomes),
                array_values($nomes),
            ),
        ]);
    }

    #[Route('/expediente/painel/acervo-geral', name: 'expediente_acervo_geral')]
    public function acervoGeral(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $tenant = $this->assertAccess($user);

        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('expediente_index');
        }

        $filters             = $this->filtrosDaRequest($request);
        [$ordenar, $direcao] = $this->ordenacaoDaRequest($request);

        $page = max(1, $request->query->getInt('page', 1));

        $totalItems = $this->pastaRepository->countByFilters($filters, $tenant);
        $porPagina  = $this->porPaginaDaRequest($request);
        $totalPages = max(1, (int) ceil($totalItems / $porPagina));
        $page       = min($page, $totalPages);
        $pastas     = $this->pastaRepository->findByFilters($filters, $tenant, $page, $porPagina, $ordenar, $direcao, $user);

        return $this->render('expediente/_acervo_geral.html.twig', [
            'pastas'       => $pastas,
            'filters'      => $filters,
            'ordenar'      => $ordenar,
            'direcao'      => $direcao,
            'responsaveis' => $this->userRepository->findColaboradoresAtivosPorTenant($tenant),
            'fotosResponsaveis' => $this->userRepository->findFotoPorColaboradores($tenant),
            'formAction'   => $this->generateUrl('expediente_acervo_geral'),
            'pagination'   => [
                'current_page' => $page,
                'per_page'     => $porPagina,
                'total_items'  => $totalItems,
                'total_pages'  => $totalPages,
            ],
        ] + $this->extrasDaLista($pastas, $user, $tenant));
    }

    /**
     * Extrai os filtros da listagem de pastas a partir da query string.
     *
     * @return array<string, string>
     */
    private function filtrosDaRequest(Request $request): array
    {
        return [
            'busca'       => trim((string) $request->query->get('busca', '')),
            'status'      => $request->query->get('status', ''),
            'responsavel' => $request->query->get('responsavel', ''),
            'prioridade'  => $request->query->get('prioridade', ''),
            'data_de'     => $request->query->get('data_de', ''),
            'data_ate'    => $request->query->get('data_ate', ''),
            'criado_por'  => $request->query->get('criado_por', ''),
            'expediente'  => in_array($request->query->get('expediente', ''), PastaRepository::FILTROS_EXPEDIENTE, true)
                ? (string) $request->query->get('expediente')
                : '',
        ];
    }

    private function porPaginaDaRequest(Request $request): int
    {
        $n = $request->query->getInt('por_pagina', self::PER_PAGE);

        return in_array($n, self::POR_PAGINA, true) ? $n : self::PER_PAGE;
    }

    /**
     * O que a Lista do desenho 1.1 mostra além da pasta (E12): réus, selo "Nx", destaques do usuário, quem
     * é administrador (trancar) e o token das ações. Uma consulta por item, para a página inteira.
     *
     * @param Pasta[] $pastas
     * @return array<string, mixed>
     */
    private function extrasDaLista(array $pastas, User $user, Tenant $tenant): array
    {
        $ids      = array_map(static fn (Pasta $p): int => (int) $p->getId(), $pastas);
        $clientes = array_values(array_unique(array_filter(array_map(
            static fn (Pasta $p): ?int => $p->getClientePrincipal()?->getId(),
            $pastas,
        ))));

        return [
            'reusPorPasta'      => $this->pastaRepository->reusPorPasta($ids, $tenant),
            'pastasPorCliente'  => $this->pastaRepository->totalDePastasPorClientePrincipal($clientes, $tenant),
            'destaques'         => $this->destaqueRepository->coresDoUsuario($user, $tenant),
            'souAdministrador'  => $this->permissionChecker->isAdministradorDoEscritorio($user, $tenant),
            'csrfLista'         => $this->csrfTokenManager->getToken(self::CSRF_LISTA)->getValue(),
        ];
    }

    /**
     * Coluna e direção de ordenação vindas do cabeçalho da tabela. A coluna passa por
     * uma allowlist (nomes que o repositório sabe ordenar); direção só asc/desc.
     *
     * @return array{0: string, 1: string}
     */
    private function ordenacaoDaRequest(Request $request): array
    {
        $colunas = ['nup', 'cliente', 'acao', 'prioridade', 'responsavel', 'marcadores', 'situacao'];
        $ordenar = (string) $request->query->get('ordenar', '');
        $ordenar = in_array($ordenar, $colunas, true) ? $ordenar : '';
        $direcao = strtolower((string) $request->query->get('direcao', 'desc')) === 'asc' ? 'asc' : 'desc';

        return [$ordenar, $direcao];
    }

    private function assertAccess(User $user): Tenant
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null || !$this->permissionChecker->canAccessModule($user, $tenant, 'expediente')) {
            throw $this->createAccessDeniedException('Sem acesso ao módulo Expediente.');
        }

        return $tenant;
    }
}
