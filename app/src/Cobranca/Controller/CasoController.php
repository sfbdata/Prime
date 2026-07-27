<?php

declare(strict_types=1);

namespace App\Cobranca\Controller;

use App\Cobranca\DTO\AlterarPessoaCobradaInput;
use App\Cobranca\DTO\EditarConfiguracaoCasoInput;
use App\Cobranca\DTO\EncerrarCasoInput;
use App\Cobranca\DTO\JudicializarCasoInput;
use App\Cobranca\DTO\EditarAnotacaoInput;
use App\Cobranca\DTO\RegistrarAnotacaoInput;
use App\Cobranca\DTO\RegistrarTentativaCobrancaInput;
use App\Cobranca\Exception\AnotacaoNaoEditavelException;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\EventoNaoEncontradoException;
use App\Cobranca\Exception\CasoJaJudicializadoException;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Exception\PastaNaoEncontradaException;
use App\Cobranca\Exception\PessoaNaoEncontradaException;
use App\Cobranca\Exception\SaldoNaoResolvidoException;
use App\Cobranca\Form\AlterarPessoaCobradaType;
use App\Cobranca\Form\EditarConfiguracaoCasoType;
use App\Cobranca\Form\EncerrarCasoType;
use App\Cobranca\Form\JudicializarCasoType;
use App\Cobranca\Form\EditarAnotacaoType;
use App\Cobranca\Form\RegistrarAnotacaoType;
use App\Cobranca\Form\RegistrarTentativaCobrancaType;
use App\Cobranca\Repository\CarteiraRepository;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\UseCase\AlterarPessoaCobradaUseCase;
use App\Cobranca\UseCase\EditarConfiguracaoCasoUseCase;
use App\Cobranca\UseCase\EncerrarCasoUseCase;
use App\Cobranca\UseCase\JudicializarCasoUseCase;
use App\Cobranca\UseCase\ListarCasosUseCase;
use App\Cobranca\UseCase\EditarAnotacaoUseCase;
use App\Cobranca\UseCase\ExcluirAnotacaoUseCase;
use App\Cobranca\UseCase\RegistrarAnotacaoUseCase;
use App\Cobranca\UseCase\RegistrarTentativaCobrancaUseCase;
use App\Entity\Tenant\Tenant;
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
        private readonly EncerrarCasoUseCase $encerrarCaso,
        private readonly RegistrarTentativaCobrancaUseCase $registrarTentativa,
        private readonly JudicializarCasoUseCase $judicializarCaso,
        private readonly PastaRepository $pastaRepository,
        private readonly AlterarPessoaCobradaUseCase $alterarPessoaCobrada,
        private readonly PessoaRepository $pessoaRepository,
        private readonly EditarConfiguracaoCasoUseCase $editarConfiguracaoCaso,
        private readonly RegistrarAnotacaoUseCase $registrarAnotacao,
        private readonly EditarAnotacaoUseCase $editarAnotacao,
        private readonly ExcluirAnotacaoUseCase $excluirAnotacao,
        private readonly EventoHistoricoRepository $eventoRepository,
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

        // Deep-link legado: o Caso deixou de ter tela própria (ajuste 2, Fatia 5). Resolvemos o caso de
        // forma tenant-safe — preservando o 404 cross-tenant — e redirecionamos para a página unificada
        // do Objeto, que é a canônica. Links antigos, importação e histórico continuam funcionando.
        $caso = $this->casoRepository->findOneByIdDoTenant($id, $tenant);
        if ($caso === null) {
            throw $this->createNotFoundException('Caso de cobrança não encontrado.');
        }

        return $this->redirectToRoute('cobranca_objeto_show', ['id' => $this->objetoIdDoCaso($caso)]);
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
            // B5 fica de fora aqui por baixo valor, não por ser inalcançável: o único campo editável
            // (`observacao`) é opcional e limitado a `Length(max: 255)`, com `maxlength=255` no cliente.
            // O usuário honesto é barrado no navegador; a única falha de validação de campo possível vem
            // de um POST forjado com >255 chars — que só perderia o próprio excesso. Não compensa reidratar.
            $this->flashErrosDoForm($form);
        }

        return $this->redirectToRoute('cobranca_objeto_show', ['id' => $this->objetoIdDoCaso($caso)]);
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
            // B5: erro de campo reabre o modal com o digitado; CSRF (erro de raiz) segue com flash.
            $this->tratarFormInvalido($request, $form, $this->objetoIdDoCaso($caso), 'alterarPessoa', 'modalAlterarPessoa', 'alterar_pessoa_cobrada');
        }

        return $this->redirectToRoute('cobranca_objeto_show', ['id' => $this->objetoIdDoCaso($caso)]);
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
            // B5: erro de campo reabre o modal com o digitado; CSRF (erro de raiz) segue com flash.
            $this->tratarFormInvalido($request, $form, $this->objetoIdDoCaso($caso), 'judicializar', 'modalJudicializar', 'judicializar_caso');
        }

        return $this->redirectToRoute('cobranca_objeto_show', ['id' => $this->objetoIdDoCaso($caso)]);
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
                $this->addFlash('success', 'Contato registrado.');
            } catch (CasoEncerradoException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            // B5: erro de campo reabre o modal com o digitado; CSRF (erro de raiz) segue com flash.
            $this->tratarFormInvalido($request, $form, $this->objetoIdDoCaso($caso), 'registrarTentativa', 'modalRegistrarTentativa', 'registrar_tentativa_cobranca');
        }

        return $this->redirectToRoute('cobranca_objeto_show', ['id' => $this->objetoIdDoCaso($caso)]);
    }

    /**
     * Anotação livre na linha do tempo (ajuste 2026-07). Mesmo gate e mesma resolução tenant-safe do
     * contato — o que muda é que aqui não há campo classificado: é texto e só. Volta para a aba
     * Cobrança do objeto (âncora `#tab-cobranca`), onde o editor e as "Anotações recentes" passaram a
     * morar depois da reorganização de UX (2026-07-26): o autor vê a anotação já na lista, sem trocar
     * de aba.
     */
    #[Route('/{id}/anotacoes', name: 'cobranca_anotacao_registrar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function registrarAnotacao(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $caso = $this->casoRepository->findOneByIdDoTenant($id, $tenant);
        if ($caso === null) {
            throw $this->createNotFoundException('Caso de cobrança não encontrado.');
        }

        $input = new RegistrarAnotacaoInput();
        $input->casoId = $id;
        $form = $this->createForm(RegistrarAnotacaoType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->registrarAnotacao->executar($input, $tenant, $this->usuarioLogado());
                $this->addFlash('success', 'Anotação registrada.');
            } catch (CasoEncerradoException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            // SPEC UX §7.3: erro de validação não pode apagar o que a pessoa escreveu. Reusa o mesmo
            // mecanismo B5 dos modais — payload one-shot na sessão, reidratado no `show` —, com
            // `modalId` vazio porque aqui não há modal para reabrir: o campo é inline. Erro de RAIZ
            // (CSRF) continua sendo rejeição limpa por flash, sem ecoar o payload.
            $this->tratarFormInvalido(
                $request,
                $form,
                $this->objetoIdDoCaso($caso),
                'registrarAnotacao',
                '',
                'registrar_anotacao',
            );
        }

        return $this->redirectToRoute(
            'cobranca_objeto_show',
            ['id' => $this->objetoIdDoCaso($caso), 'aba' => 'cobranca'],
        );
    }

    /**
     * Corrige o texto de uma anotação (48h, só o autor — ajuste 2026-07-22). As três guardas vivem no
     * UseCase/entidade; aqui só o gate de módulo e a tradução das exceções em flash.
     */
    #[Route('/anotacoes/{eventoId}/editar', name: 'cobranca_anotacao_editar', methods: ['POST'], requirements: ['eventoId' => '\d+'])]
    public function editarAnotacao(int $eventoId, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        // A guarda de tenant vem ANTES de qualquer validação de formulário: anotação de outro
        // escritório tem de responder 404 mesmo com CSRF ruim ou campo vazio. Validar primeiro faria
        // um POST forjado voltar com "sessão expirada" e esconder o anti-IDOR atrás de um redirect.
        $objetoId = $this->objetoIdDoEvento($eventoId, $tenant);
        if ($objetoId === null && $this->eventoRepository->findOneByIdDoTenant($eventoId, $tenant) === null) {
            throw $this->createNotFoundException('Anotação não encontrada.');
        }

        $input = new EditarAnotacaoInput();
        $input->eventoId = $eventoId;
        $form = $this->createForm(EditarAnotacaoType::class, $input);
        $form->handleRequest($request);

        try {
            if (!$form->isSubmitted() || !$form->isValid()) {
                $this->flashErrosDoForm($form);
            } else {
                $this->editarAnotacao->executar($input, $tenant, $this->usuarioLogado());
                $this->addFlash('success', 'Anotação corrigida.');
            }
        } catch (EventoNaoEncontradoException) {
            throw $this->createNotFoundException('Anotação não encontrada.');
        } catch (AnotacaoNaoEditavelException|CasoEncerradoException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        if ($objetoId === null) {
            return $this->redirectToRoute('cobranca_caso_index');
        }

        return $this->redirectToRoute('cobranca_objeto_show', ['id' => $objetoId, 'aba' => 'cobranca']);
    }

    /**
     * Apaga uma anotação (48h, só o autor — ajuste 2026-07-22). Exclusão DEFINITIVA, como na timeline
     * da Pasta: some da linha do tempo, sem deixar marca de "removida" (decisão do dono).
     */
    #[Route('/anotacoes/{eventoId}/excluir', name: 'cobranca_anotacao_excluir', methods: ['POST'], requirements: ['eventoId' => '\d+'])]
    public function excluirAnotacao(int $eventoId, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        // Guarda de tenant primeiro, pela mesma razão do editar: anotação de outro escritório é 404,
        // não "sessão expirada". Também guardamos para onde voltar — depois do remove, o caso já não
        // é alcançável pelo evento.
        $objetoId = $this->objetoIdDoEvento($eventoId, $tenant);
        if ($objetoId === null && $this->eventoRepository->findOneByIdDoTenant($eventoId, $tenant) === null) {
            throw $this->createNotFoundException('Anotação não encontrada.');
        }

        if (!$this->isCsrfTokenValid('excluir_anotacao_' . $eventoId, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Sessão expirada. Tente novamente.');

            return $this->redirectToRoute('cobranca_caso_index');
        }

        try {
            $this->excluirAnotacao->executar($eventoId, $tenant, $this->usuarioLogado());
            $this->addFlash('success', 'Anotação excluída.');
        } catch (EventoNaoEncontradoException) {
            throw $this->createNotFoundException('Anotação não encontrada.');
        } catch (AnotacaoNaoEditavelException|CasoEncerradoException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        if ($objetoId === null) {
            return $this->redirectToRoute('cobranca_caso_index');
        }

        return $this->redirectToRoute('cobranca_objeto_show', ['id' => $objetoId, 'aba' => 'cobranca']);
    }

    /** Resolve o objeto de destino do redirect a partir do evento, sempre tenant-safe. */
    private function objetoIdDoEvento(int $eventoId, Tenant $tenant): ?int
    {
        return $this->eventoRepository->findOneByIdDoTenant($eventoId, $tenant)?->getCaso()?->getObjeto()?->getId();
    }

    /**
     * Editar os honorários do caso âncora (Ajuste 2, Fatia A). Gate `resources.cobranca.gerenciar`,
     * resolução tenant-safe por id (anti-IDOR → 404). Salvar recalcula na hora as automáticas vivas do
     * caso (D-A2-3), delegado ao UseCase. Config de honorários NÃO é bloqueada por caso encerrado
     * (D-A2-7): é metadado, não movimento financeiro — mantém só a guarda multi-tenant.
     */
    #[Route('/{id}/configuracao-honorarios', name: 'cobranca_caso_editar_config', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function editarConfiguracaoHonorarios(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $caso = $this->casoRepository->findOneByIdDoTenant($id, $tenant);
        if ($caso === null) {
            throw $this->createNotFoundException('Caso de cobrança não encontrado.');
        }

        $input = new EditarConfiguracaoCasoInput();
        $input->casoId = $id;
        $form = $this->createForm(EditarConfiguracaoCasoType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->editarConfiguracaoCaso->executar($input, $tenant, $this->usuarioLogado());
                $this->addFlash('success', 'Honorários do caso atualizados.');
            } catch (CasoNaoEncontradoException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            // B5: erro de campo reabre o modal com o digitado; CSRF (erro de raiz) segue com flash.
            $this->tratarFormInvalido($request, $form, $this->objetoIdDoCaso($caso), 'editarConfigCaso', 'modalEditarConfigCaso', 'editar_configuracao_caso');
        }

        return $this->redirectToRoute('cobranca_objeto_show', ['id' => $this->objetoIdDoCaso($caso)]);
    }

}
