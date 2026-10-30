<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Expediente\Exception\ColunasDoExpedienteInvalidasException;
use App\Expediente\Repository\MarcadorRepository;
use App\Expediente\Service\CsvDaListaDoExpediente;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\Service\ComplementosDaListaDeDemandas;
use App\Pasta\Service\NumeracaoDePastaInterface;
use App\Pasta\UseCase\GravarColunasDasDemandasUseCase;
use App\Pasta\UseCase\ListarMinhasDemandasUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Minhas Demandas no desenho 15 (`15 - Demandas - VS 30.10.26.dc.html`): a mesma Lista do Expediente (desenho 03),
 * sem o trilho, só com as pastas em que o usuário é o responsável. Tudo aqui exige o módulo `pastas`, como a tela;
 * as rotas do Expediente (que exigem o módulo `expediente`) não são usadas por esta tela.
 */
final class DemandasController extends AbstractController
{
    /** "Pastas por página" do Visualizar (dc `porPagOpcoes`); o padrão do desenho é 50. */
    private const POR_PAGINA        = [25, 50, 100];
    private const POR_PAGINA_PADRAO = 50;

    /** Colunas que o cabeçalho da tabela sabe ordenar (as mesmas do Expediente). */
    private const ORDENAVEIS = ['nup', 'cliente', 'reu', 'acao', 'prioridade', 'responsavel', 'marcadores', 'situacao'];

    /** Token único das ações da Lista nesta tela (colunas, F2, trancar, destacar). */
    public const CSRF_LISTA = 'demandas_lista';

    public function __construct(
        private readonly ListarMinhasDemandasUseCase $listarMinhasDemandas,
        private readonly GravarColunasDasDemandasUseCase $gravarColunas,
        private readonly ComplementosDaListaDeDemandas $complementos,
        private readonly PastaRepository $pastaRepository,
        private readonly MarcadorRepository $marcadorRepository,
        private readonly NumeracaoDePastaInterface $numeracaoDePasta,
        private readonly CsvDaListaDoExpediente $csvDaLista,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
    ) {}

    /** A tela; por XHR (filtro, página, ordem, depois de criar), só o painel do acervo. */
    #[Route('/demandas', name: 'demandas_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->tenantComModulo($usuario);

        // Demandas é uma listagem do domínio Pasta: gateada pelo módulo, como index/criar (B7).
        if ($tenant === null) {
            $this->addFlash('warning', 'Você não tem permissão para acessar as demandas.');

            return $this->redirectToRoute('homepage');
        }

        $dados = $this->dadosDaLista($request, $usuario, $tenant);
        if ($request->isXmlHttpRequest()) {
            return $this->render('pasta/demandas/_acervo.html.twig', $dados);
        }

        return $this->render('pasta/demandas.html.twig', $dados + [
            'marcadores' => $this->marcadorRepository->findRaizPorTenant($tenant),
        ]);
    }

    /**
     * Menu "Colunas da tabela": o ajuste do usuário logado neste escritório, na chave das Demandas (corpo JSON
     * `{colunas: {id: bool}|null}`; `null` volta ao padrão do desenho 15). Token da Lista no cabeçalho `X-CSRF-Token`.
     */
    #[Route('/demandas/colunas', name: 'demandas_colunas_gravar', methods: ['POST'])]
    public function gravarColunas(Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->exigirModulo($usuario);

        if (!$this->isCsrfTokenValid(self::CSRF_LISTA, (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->json(['erro' => 'Token inválido.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $this->gravarColunas->executar($tenant, $usuario, $this->colunasDoCorpo($request));
        } catch (ColunasDoExpedienteInvalidasException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['ok' => true]);
    }

    /** "Exportar colunas visíveis (CSV)": todas as minhas demandas da consulta, com os filtros e a ordem da tela. */
    #[Route('/demandas/exportar.csv', name: 'demandas_exportar_csv', methods: ['GET'])]
    public function exportarCsv(Request $request): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->exigirModulo($usuario);

        [$ordenar, $direcao] = $this->ordenacaoDaRequest($request);
        $pastas  = $this->listarMinhasDemandas->executar($usuario, $tenant, $this->filtrosDaRequest($request), 1, null, $ordenar, $direcao)['pastas'];
        $colunas = array_filter(explode(',', (string) $request->query->get('colunas', '')));
        $ids     = array_map(static fn (Pasta $p): int => (int) $p->getId(), $pastas);
        $csv     = $this->csvDaLista->gerar($pastas, $colunas, $this->pastaRepository->reusPorPasta($ids, $tenant), $this->ocultasPara($usuario, $pastas));

        return new Response($csv, Response::HTTP_OK, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="expediente-pastas.csv"',
            'Cache-Control'       => 'no-store',
        ]);
    }

    /**
     * Nova demanda (modal Nova Pasta do desenho): o próximo número, só informativo (o servidor numera de novo ao
     * salvar), e os clientes parecidos com quantas pastas cada um já tem e se há um cadastro só por trás do nome.
     */
    #[Route('/demandas/nova-pasta/sugestoes', name: 'demandas_nova_pasta_sugestoes', methods: ['GET'])]
    public function sugestoesDaNovaPasta(Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->exigirModulo($usuario);

        $totais    = $this->pastaRepository->nomesDeClienteParecidos((string) $request->query->get('q', ''), $tenant);
        $nomes     = array_map('strval', array_keys($totais));
        $cadastros = $this->pastaRepository->cadastroUnicoPorNomeDeCliente($nomes, $tenant);

        return $this->json([
            'proximoNumero' => (string) ($this->numeracaoDePasta->maiorNumero($tenant) + 1),
            'clientes'      => array_map(
                static fn (string $nome, int $total): array => ['nome' => $nome, 'total' => $total, 'cadastro' => isset($cadastros[mb_strtoupper(trim($nome))])],
                $nomes,
                array_values($totais),
            ),
        ]);
    }

    /** @return array<string, mixed> */
    private function dadosDaLista(Request $request, User $usuario, Tenant $tenant): array
    {
        $filtros             = $this->filtrosDaRequest($request);
        [$ordenar, $direcao] = $this->ordenacaoDaRequest($request);
        $porPagina           = $this->porPaginaDaRequest($request);
        $resultado           = $this->listarMinhasDemandas->executar($usuario, $tenant, $filtros, $request->query->getInt('page', 1), $porPagina, $ordenar, $direcao);

        return [
            'pastas'     => $resultado['pastas'],
            'filters'    => $filtros,
            'ordenar'    => $ordenar,
            'direcao'    => $direcao,
            'formAction' => $this->generateUrl('demandas_index'),
            'csrfLista'  => $this->csrfTokenManager->getToken(self::CSRF_LISTA)->getValue(),
            'pagination' => [
                'current_page' => $resultado['pagina'],
                'per_page'     => $porPagina,
                'total_items'  => $resultado['total'],
                'total_pages'  => max(1, (int) ceil($resultado['total'] / $porPagina)),
            ],
        ] + $this->complementos->para($resultado['pastas'], $usuario, $tenant);
    }

    /** @return array<string, string> */
    private function filtrosDaRequest(Request $request): array
    {
        $filtros = [];
        foreach (['busca', 'status', 'responsavel', 'prioridade', 'data_de', 'data_ate'] as $chave) {
            $filtros[$chave] = trim((string) $request->query->get($chave, ''));
        }

        return $filtros;
    }

    /** @return array{0: string, 1: string} */
    private function ordenacaoDaRequest(Request $request): array
    {
        $ordenar = (string) $request->query->get('ordenar', '');
        $direcao = strtolower((string) $request->query->get('direcao', 'desc')) === 'asc' ? 'asc' : 'desc';

        // Sem ordenação explícita, mantém o comportamento histórico das Demandas: urgentes primeiro.
        return [in_array($ordenar, self::ORDENAVEIS, true) ? $ordenar : 'prioridade', $direcao];
    }

    private function porPaginaDaRequest(Request $request): int
    {
        $n = $request->query->getInt('por_pagina', self::POR_PAGINA_PADRAO);

        return in_array($n, self::POR_PAGINA, true) ? $n : self::POR_PAGINA_PADRAO;
    }

    /**
     * @param Pasta[] $pastas
     * @return array<int, true>
     */
    private function ocultasPara(User $usuario, array $pastas): array
    {
        return array_map(static fn (): bool => true, array_filter(
            $this->acessoAPasta->niveisEmLote($usuario, $pastas),
            static fn (NivelDeAcesso $n): bool => !$n->podeVer(),
        ));
    }

    /** O campo `colunas` do corpo JSON; corpo que não é objeto JSON ou sem o campo é recusado. */
    private function colunasDoCorpo(Request $request): mixed
    {
        try {
            $corpo = $request->toArray();
        } catch (JsonException) {
            throw new ColunasDoExpedienteInvalidasException();
        }

        if (!array_key_exists('colunas', $corpo)) {
            throw new ColunasDoExpedienteInvalidasException();
        }

        return $corpo['colunas'];
    }

    private function tenantComModulo(User $usuario): ?Tenant
    {
        $tenant = $this->tenantContext->getCurrentTenant();

        return $tenant !== null && $this->permissionChecker->canAccessModule($usuario, $tenant, 'pastas') ? $tenant : null;
    }

    private function exigirModulo(User $usuario): Tenant
    {
        return $this->tenantComModulo($usuario) ?? throw $this->createAccessDeniedException('Sem acesso ao módulo Pastas.');
    }
}
