<?php

declare(strict_types=1);

namespace App\Cobranca\Controller;

use App\Cobranca\DTO\EncerrarCasoInput;
use App\Cobranca\DTO\RegistrarTentativaCobrancaInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\SaldoNaoResolvidoException;
use App\Cobranca\Form\AcordoCriarType;
use App\Cobranca\Form\CancelarAcordoType;
use App\Cobranca\Form\ConcluirAcaoType;
use App\Cobranca\Form\DefinirProximaAcaoType;
use App\Cobranca\Form\EncerrarCasoType;
use App\Cobranca\Form\GerarRevisaoType;
use App\Cobranca\Form\ReconhecerValorAtualizadoType;
use App\Cobranca\Form\RegistrarLiquidacaoType;
use App\Cobranca\Form\RegistrarObrigacaoType;
use App\Cobranca\Form\RegistrarPagamentoType;
use App\Cobranca\Form\CorrigirPagamentoType;
use App\Cobranca\Form\RegistrarTentativaCobrancaType;
use App\Cobranca\Form\ResolverRevisaoType;
use App\Cobranca\Form\RomperAcordoType;
use App\Cobranca\Repository\CarteiraRepository;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\UseCase\EncerrarCasoUseCase;
use App\Cobranca\UseCase\ListarCasosUseCase;
use App\Cobranca\UseCase\MontarDetalheCasoUseCase;
use App\Cobranca\UseCase\RegistrarTentativaCobrancaUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Camada HTTP dos Casos de Cobrança (Etapa 8). Controller FINO: gate de módulo, resolução
 * tenant-safe por id (anti-IDOR), delegação aos UseCases de leitura, render de Output DTOs. A lista
 * reusa a máquina de filtros do Expediente; o detalhe é a tela central (SPEC §9/§26). Ações de
 * escrita (formulários) entram na Onda 8B — aqui é só leitura/navegação.
 */
#[Route('/cobrancas/casos')]
#[IsGranted('ROLE_USER')]
final class CasoController extends AbstractController
{
    use AutorizacaoCobranca;

    private const POR_PAGINA = 20;

    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly CarteiraRepository $carteiraRepository,
        private readonly ListarCasosUseCase $listarCasos,
        private readonly MontarDetalheCasoUseCase $montarDetalheCaso,
        private readonly EncerrarCasoUseCase $encerrarCaso,
        private readonly RegistrarTentativaCobrancaUseCase $registrarTentativa,
        private readonly ObrigacaoRepository $obrigacaoRepository,
    ) {
    }

    #[Route('', name: 'cobranca_caso_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $tenant = $this->tenantComModulo();
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $filtros = [
            'busca' => trim((string) $request->query->get('busca', '')),
            'status' => (string) $request->query->get('status', ''),
            'carteira' => (string) $request->query->get('carteira', ''),
        ];
        $ordenar = (string) $request->query->get('ordenar', '') ?: 'atualizacao';
        $direcao = strtolower((string) $request->query->get('direcao', 'desc')) === 'asc' ? 'asc' : 'desc';
        $pagina = max(1, (int) $request->query->get('page', 1));

        $resultado = $this->listarCasos->executar($tenant, $filtros, $pagina, self::POR_PAGINA, $ordenar, $direcao);
        $total = $resultado['total'];

        $dados = [
            'casos' => $resultado['itens'],
            'total' => $total,
            'pagina' => $pagina,
            'total_paginas' => (int) max(1, ceil($total / self::POR_PAGINA)),
            'filtros' => $filtros + ['ordenar' => $ordenar, 'direcao' => $direcao],
        ];

        if ($request->isXmlHttpRequest()) {
            return $this->render('cobranca/caso/_resultado.html.twig', $dados);
        }

        // A barra de facetas só existe na página cheia (fica fora do fragmento XHR).
        $dados['carteiras'] = $this->carteiraRepository->opcoesFacetaDoTenant($tenant);

        return $this->render('cobranca/caso/index.html.twig', $dados);
    }

    #[Route('/{id}', name: 'cobranca_caso_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $tenant = $this->tenantComModulo();
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $caso = $this->casoRepository->findOneByIdDoTenant($id, $tenant);
        if ($caso === null) {
            throw $this->createNotFoundException('Caso de cobrança não encontrado.');
        }

        // Só monta as views dos formulários para quem tem a capacidade — o mesmo gate que esconde os
        // modais no Twig. Leitor puro não paga esse custo. Movimentação financeira é capacidade
        // SEPARADA de gerenciar (SPEC §22): um caixa pode movimentar sem gerenciar, e vice-versa.
        $usuario = $this->usuarioLogado();
        $podeGerenciar = $this->permissionChecker->hasPermission($usuario, $tenant, 'resources.cobranca.gerenciar');
        $podeMovimentar = $this->permissionChecker->hasPermission($usuario, $tenant, 'resources.cobranca.movimentacao_financeira');

        $forms = $podeGerenciar ? $this->formulariosDeMutacao($caso) : [];
        if ($podeMovimentar) {
            $forms += $this->formulariosFinanceiros($caso);
        }

        return $this->render('cobranca/caso/show.html.twig', [
            'caso' => $this->montarDetalheCaso->executar($caso),
            'forms' => $forms,
        ]);
    }

    #[Route('/{id}/encerrar', name: 'cobranca_caso_encerrar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function encerrar(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $caso = $this->casoRepository->findOneByIdDoTenant($id, $tenant);
        if ($caso === null) {
            throw $this->createNotFoundException('Caso de cobrança não encontrado.');
        }

        $input = new EncerrarCasoInput();
        $input->casoId = $id;
        $form = $this->createForm(EncerrarCasoType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->encerrarCaso->executar($input, $tenant, $this->usuarioLogado());
                $this->addFlash('success', 'Caso encerrado.');
            } catch (CasoEncerradoException | SaldoNaoResolvidoException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->flashErrosDoForm($form);
        }

        return $this->redirectToRoute('cobranca_caso_show', ['id' => $id]);
    }

    #[Route('/{id}/tentativas', name: 'cobranca_tentativa_registrar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function registrarTentativa(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $caso = $this->casoRepository->findOneByIdDoTenant($id, $tenant);
        if ($caso === null) {
            throw $this->createNotFoundException('Caso de cobrança não encontrado.');
        }

        $input = new RegistrarTentativaCobrancaInput();
        $input->casoId = $id;
        $form = $this->createForm(RegistrarTentativaCobrancaType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->registrarTentativa->executar($input, $tenant, $this->usuarioLogado());
                $this->addFlash('success', 'Tentativa de cobrança registrada.');
            } catch (CasoEncerradoException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->flashErrosDoForm($form);
        }

        return $this->redirectToRoute('cobranca_caso_show', ['id' => $id]);
    }

    /**
     * Views (vazias) dos formulários de mutação renderizados como modais no detalhe do Caso. O
     * processamento POST vive em cada controller de recurso; aqui só o render. Cresce por fatia da 8B.
     *
     * @return array<string, \Symfony\Component\Form\FormView>
     */
    private function formulariosDeMutacao(CasoCobranca $caso): array
    {
        $opcoesObrigacoes = AcordoCriarType::opcoesObrigacoes($this->obrigacaoRepository->doCasoExigiveis($caso));

        return [
            'registrarObrigacao' => $this->createForm(RegistrarObrigacaoType::class)->createView(),
            'reconhecerValor' => $this->createForm(ReconhecerValorAtualizadoType::class)->createView(),
            'encerrarCaso' => $this->createForm(EncerrarCasoType::class)->createView(),
            'definirProximaAcao' => $this->createForm(DefinirProximaAcaoType::class)->createView(),
            'concluirAcao' => $this->createForm(ConcluirAcaoType::class)->createView(),
            'registrarTentativa' => $this->createForm(RegistrarTentativaCobrancaType::class)->createView(),
            'gerarRevisao' => $this->createForm(GerarRevisaoType::class)->createView(),
            'resolverRevisao' => $this->createForm(ResolverRevisaoType::class)->createView(),
            'acordoCriar' => $this->createForm(AcordoCriarType::class, null, ['obrigacoes' => $opcoesObrigacoes])->createView(),
            'romperAcordo' => $this->createForm(RomperAcordoType::class)->createView(),
            'cancelarAcordo' => $this->createForm(CancelarAcordoType::class)->createView(),
        ];
    }

    /**
     * Views dos formulários financeiros (8B-D) renderizados como modais na aba Pagamentos &
     * Liquidações. Gated pela capacidade `resources.cobranca.movimentacao_financeira` (separada de
     * gerenciar). Pagamento/correção reusam as obrigações exigíveis do caso para o select de alocação.
     *
     * @return array<string, \Symfony\Component\Form\FormView>
     */
    private function formulariosFinanceiros(CasoCobranca $caso): array
    {
        $opcoesObrigacoes = AcordoCriarType::opcoesObrigacoes($this->obrigacaoRepository->doCasoExigiveis($caso));

        return [
            'registrarPagamento' => $this->createForm(RegistrarPagamentoType::class, null, ['obrigacoes' => $opcoesObrigacoes])->createView(),
            'corrigirPagamento' => $this->createForm(CorrigirPagamentoType::class, null, ['obrigacoes' => $opcoesObrigacoes])->createView(),
            'registrarLiquidacao' => $this->createForm(RegistrarLiquidacaoType::class)->createView(),
        ];
    }
}
