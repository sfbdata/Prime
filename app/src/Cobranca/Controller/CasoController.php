<?php

declare(strict_types=1);

namespace App\Cobranca\Controller;

use App\Cobranca\DTO\AlterarPessoaCobradaInput;
use App\Cobranca\DTO\EncerrarCasoInput;
use App\Cobranca\DTO\JudicializarCasoInput;
use App\Cobranca\DTO\RegistrarTentativaCobrancaInput;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\CasoJaJudicializadoException;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Exception\PastaNaoEncontradaException;
use App\Cobranca\Exception\PessoaNaoEncontradaException;
use App\Cobranca\Exception\SaldoNaoResolvidoException;
use App\Cobranca\Form\AlterarPessoaCobradaType;
use App\Cobranca\Form\EncerrarCasoType;
use App\Cobranca\Form\JudicializarCasoType;
use App\Cobranca\Form\RegistrarTentativaCobrancaType;
use App\Cobranca\Repository\CarteiraRepository;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\Service\MontadorModaisCaso;
use App\Cobranca\UseCase\MontarDetalheCasoUseCase;
use App\Cobranca\UseCase\AlterarPessoaCobradaUseCase;
use App\Cobranca\UseCase\EncerrarCasoUseCase;
use App\Cobranca\UseCase\JudicializarCasoUseCase;
use App\Cobranca\UseCase\ListarCasosUseCase;
use App\Cobranca\UseCase\RegistrarTentativaCobrancaUseCase;
use App\Pasta\Repository\PastaRepository;
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
        private readonly MontadorModaisCaso $montadorModais,
        private readonly EncerrarCasoUseCase $encerrarCaso,
        private readonly RegistrarTentativaCobrancaUseCase $registrarTentativa,
        private readonly JudicializarCasoUseCase $judicializarCaso,
        private readonly PastaRepository $pastaRepository,
        private readonly AlterarPessoaCobradaUseCase $alterarPessoaCobrada,
        private readonly PessoaRepository $pessoaRepository,
    ) {
    }

    private const MODULO_PASTAS = 'pastas';

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
        // modais no Twig. Leitor puro não paga esse custo. Movimentação financeira é capacidade SEPARADA
        // de gerenciar (SPEC §22). A construção dos modais/documentos é compartilhada com a página do
        // objeto via `MontadorModaisCaso` (ajuste 2). O redirect deste deep-link para o objeto entra na
        // Fatia 5 (junto com os redirects de mutação e a atualização dos testes).
        $usuario = $this->usuarioLogado();
        $podeGerenciar = $this->permissionChecker->hasPermission($usuario, $tenant, 'resources.cobranca.gerenciar');
        $podeMovimentar = $this->permissionChecker->hasPermission($usuario, $tenant, 'resources.cobranca.movimentacao_financeira');
        $podeAcessarPastas = $this->permissionChecker->canAccessModule($usuario, $tenant, self::MODULO_PASTAS);

        $forms = $podeGerenciar ? $this->montadorModais->deMutacao($caso, $podeAcessarPastas) : [];
        if ($podeMovimentar) {
            $forms += $this->montadorModais->financeiros($caso);
        }

        $documentos = $this->montadorModais->documentosParaFm($caso);

        return $this->render('cobranca/caso/show.html.twig', [
            'caso' => $this->montarDetalheCaso->executar($caso),
            'forms' => $forms,
            'casoId' => $caso->getId(),
            'podeGerenciarDocumentos' => $podeGerenciar,
            'secoes' => $documentos['secoes'],
            'arquivosFm' => $documentos['arquivos'],
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

    #[Route('/{id}/pessoa-cobrada', name: 'cobranca_caso_alterar_pessoa', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function alterarPessoaCobrada(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $caso = $this->casoRepository->findOneByIdDoTenant($id, $tenant);
        if ($caso === null) {
            throw $this->createNotFoundException('Caso de cobrança não encontrado.');
        }

        $input = new AlterarPessoaCobradaInput();
        $input->casoId = $id;
        $form = $this->createForm(AlterarPessoaCobradaType::class, $input, [
            'pessoas' => $this->pessoaRepository->opcoesDoTenant($tenant),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->alterarPessoaCobrada->executar($input, $tenant, $this->usuarioLogado());
                $this->addFlash('success', 'Pessoa cobrada alterada.');
            } catch (CasoNaoEncontradoException | PessoaNaoEncontradaException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->flashErrosDoForm($form);
        }

        return $this->redirectToRoute('cobranca_caso_show', ['id' => $id]);
    }

    #[Route('/{id}/judicializar', name: 'cobranca_caso_judicializar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function judicializar(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }
        // Gate ADICIONAL: sem o módulo `pastas` não se pode escolher a Pasta a vincular (SPEC §16/§22).
        if (!$this->permissionChecker->canAccessModule($this->usuarioLogado(), $tenant, self::MODULO_PASTAS)) {
            return $this->semAcesso();
        }

        $caso = $this->casoRepository->findOneByIdDoTenant($id, $tenant);
        if ($caso === null) {
            throw $this->createNotFoundException('Caso de cobrança não encontrado.');
        }

        $input = new JudicializarCasoInput();
        $input->casoId = $id;
        $opcoes = ['pastas' => $this->pastaRepository->opcoesDoTenant($tenant)];
        $form = $this->createForm(JudicializarCasoType::class, $input, $opcoes);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->judicializarCaso->executar($input, $tenant, $this->usuarioLogado());
                $this->addFlash('success', 'Caso judicializado.');
            } catch (CasoNaoEncontradoException | CasoEncerradoException | CasoJaJudicializadoException | PastaNaoEncontradaException $e) {
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

}
