<?php

namespace App\Controller;

use App\Controller\Trait\ResourceAccessTrait;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Entity\Auth\User;
use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Processo\Entity\Processo;
use App\Processo\Entity\ParteProcesso;
use App\Processo\Entity\MovimentacaoProcesso;
use App\Cliente\Repository\ClienteRepository;
use App\Cliente\Repository\ClientePFRepository;
use App\Cliente\Repository\ClientePJRepository;
use App\Cliente\Service\PendenciasDoCadastro;
use App\Repository\ClienteDocumentoRepository;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Repository\PastaDocumentoFavoritoRepository;
use App\Pasta\Repository\PastaRepository;
use App\Processo\Repository\ProcessoRepository;
use App\Processo\Exception\TribunalNaoIdentificadoException;
use App\Processo\Service\TribunalCnjResolver;
use App\Entity\Permission\AccessRequest;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Expediente\Repository\MarcadorRepository;
use App\Shared\Http\EntregaDeArquivo;
use App\Shared\Armazenamento\Sha256DeArquivo;
use Psr\Log\LoggerInterface;
use App\Twig\ArquivoIconeExtension;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Pasta\Service\ConferenciaDeAnexosDoChecklist;
use App\Pasta\Service\ConflitoDeNumeroCnj;
use App\Pasta\Service\OrganizacaoSugeridaPorFase;
use App\Pasta\Service\PastaTimelineAssembler;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Pasta\Entity\PastaChecklistItem;
use App\Pasta\Exception\MensagemPastaNaoEditavelException;
use App\Pasta\Exception\MensagemPastaNaoExcluivelException;
use App\Pasta\Exception\ObservacaoDetalhesNaoEditavelException;
use App\Pasta\Exception\ObservacaoDetalhesNaoExcluivelException;
use App\Pasta\Exception\ObservacaoFinanceiraNaoEditavelException;
use App\Pasta\Exception\ObservacaoFinanceiraNaoExcluivelException;
use App\Pasta\Entity\PrioridadePasta;
use App\Pasta\DTO\CriarPastaDTO;
use App\Pasta\DTO\EditarPastaDTO;
use App\Pasta\DTO\PastaFinanceiroOutput;
use App\Pasta\DTO\PastaVizinhasOutput;
use App\Pasta\DTO\PastaPagamentosOutput;
use App\Pasta\DTO\PastaMetasResumoOutput;
use App\Pasta\DTO\PastaPendenciasOutput;
use App\Pasta\DTO\PastaPushOutput;
use App\Pasta\DTO\ExploradorDeDocumentosOutput;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Inteligencia\DTO\AnalisesDaPastaOutput;
use App\Inteligencia\Service\DisponibilidadeDeInteligencia;
use App\Inteligencia\UseCase\ListarAnalisesDaPastaUseCase;
use App\Pasta\DTO\PastaPrazoOutput;
use App\Pasta\DTO\TimelineItemDTO;
use App\Pasta\DTO\TimelineItemType;
use App\Pasta\UseCase\AdicionarChecklistItemUseCase;
use App\Pasta\UseCase\CriarPastaUseCase;
use App\Sync\Service\ReconciliadorDePasta;
use App\Sync\Service\SincronizacaoPastaDispatcher;
use App\Pasta\UseCase\DefinirClientePrincipalUseCase;
use App\Pasta\UseCase\DefinirProcessoPrincipalUseCase;
use App\Pasta\UseCase\DesvincularProcessoUseCase;
use App\Pasta\UseCase\EditarPastaUseCase;
use App\Pasta\UseCase\VincularProcessoUseCase;
use App\Pasta\UseCase\AlterarPrioridadeUseCase;
use App\Pasta\UseCase\AlterarSituacaoContratoUseCase;
use App\Pasta\UseCase\AtualizarValorCausaUseCase;
use App\Pasta\UseCase\EditarChecklistItemUseCase;
use App\Pasta\UseCase\EditarObservacaoDetalhesUseCase;
use App\Pasta\UseCase\EditarObservacaoFinanceiraUseCase;
use App\Pasta\UseCase\EditarMensagemPastaUseCase;
use App\Pasta\UseCase\EnviarMensagemPastaUseCase;
use App\Pasta\UseCase\ExcluirMensagemPastaUseCase;
use App\Pasta\UseCase\EnviarObservacaoDetalhesUseCase;
use App\Pasta\UseCase\ExcluirObservacaoDetalhesUseCase;
use App\Pasta\UseCase\EnviarObservacaoFinanceiraUseCase;
use App\Pasta\UseCase\ExcluirObservacaoFinanceiraUseCase;
use App\Pasta\UseCase\ExcluirChecklistItemUseCase;
use App\Pasta\UseCase\ExcluirItensDaPastaUseCase;
use App\Pasta\UseCase\ExcluirPastaUseCase;
use App\Pasta\UseCase\ResultadoExclusaoPasta;
use App\Pasta\UseCase\RestaurarPastaUseCase;
use App\Pasta\UseCase\ReordenarChecklistItensUseCase;
use App\Pasta\UseCase\ToggleChecklistItemUseCase;
use App\Pasta\Repository\PastaChecklistItemRepository;
use App\Pasta\Repository\PastaObservacaoDetalhesRepository;
use App\Pasta\Repository\PastaObservacaoFinanceiraRepository;
use App\Pasta\Repository\PastaPagamentoRepository;
use App\Pasta\Repository\PastaSecaoRepository;
use App\Pasta\Entity\PastaSecao;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Http\FonteDeUploadHttp;
use App\Shared\Service\CompressaoDeArquivoArmazenado;
use App\Shared\Service\SanitizadorTextoRico;
use App\Pasta\Service\MencoesDoRegistro;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * PastaController - Gerencia pastas de clientes.
 *
 * Estrutura de rotas:
 * - GET  /pasta              → Lista todas as pastas
 * - GET  /pasta/nova         → Formulário de criação
 * - POST /pasta/nova         → Cria nova pasta
 * - GET  /pasta/{id}                         → Exibe detalhes da pasta
 * - GET  /pasta/{id}/editar                  → Formulário de edição
 * - POST /pasta/{id}/editar                  → Atualiza pasta
 * - POST /pasta/{id}/deletar                 → Remove pasta
 * - POST /pasta/{id}/documento/upload        → Faz upload de documento
 * - GET  /pasta/documento/{id}/download      → Faz download de documento
 * - POST /pasta/documento/{id}/deletar       → Remove documento
 */
#[Route('/pasta')]
class PastaController extends AbstractController
{
    use ResourceAccessTrait;
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PastaRepository $pastaRepository,
        private readonly PastaDocumentoRepository $pastaDocumentoRepository,
        private readonly ClienteDocumentoRepository $clienteDocumentoRepository,
        private readonly ProcessoRepository $processoRepository,
        private readonly ClienteRepository $clienteRepository,
        private readonly ClientePFRepository $clientePFRepository,
        private readonly ClientePJRepository $clientePJRepository,
        private readonly UserRepository $userRepository,
        private readonly ValidatorInterface $validator,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly EntregaDeArquivo $entrega,
        private readonly CompressaoDeArquivoArmazenado $compressao,
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly PastaTimelineAssembler $timelineAssembler,
        private readonly EnviarMensagemPastaUseCase $enviarMensagemUseCase,
        private readonly EditarMensagemPastaUseCase $editarMensagemUseCase,
        private readonly ExcluirMensagemPastaUseCase $excluirMensagemUseCase,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly MarcadorRepository $marcadorRepository,
        private readonly CasoCobrancaRepository $casoCobrancaRepository,
        private readonly AlterarSituacaoContratoUseCase $alterarSituacaoContratoUseCase,
        private readonly AtualizarValorCausaUseCase $atualizarValorCausaUseCase,
        private readonly EnviarObservacaoFinanceiraUseCase $enviarObservacaoFinanceiraUseCase,
        private readonly EditarObservacaoFinanceiraUseCase $editarObservacaoFinanceiraUseCase,
        private readonly ExcluirObservacaoFinanceiraUseCase $excluirObservacaoFinanceiraUseCase,
        private readonly PastaObservacaoFinanceiraRepository $observacaoFinanceiraRepository,
        private readonly PastaPagamentoRepository $pastaPagamentoRepository,
        // A MESMA formatação de tamanho que o filtro `formatar_bytes` do Twig usa:
        // a linha que o JS insere depois do upload fica ao lado das que o Twig
        // desenhou, e o `formatarBytes` privado deste controller escreve
        // "380.5 KB" onde o Twig escreve "380,5 KB".
        private readonly ArquivoIconeExtension $arquivoIcone,
        private readonly EnviarObservacaoDetalhesUseCase $enviarObservacaoDetalhesUseCase,
        private readonly EditarObservacaoDetalhesUseCase $editarObservacaoDetalhesUseCase,
        private readonly ExcluirObservacaoDetalhesUseCase $excluirObservacaoDetalhesUseCase,
        private readonly PastaObservacaoDetalhesRepository $observacaoDetalhesRepository,
        private readonly PastaChecklistItemRepository $checklistRepository,
        private readonly AdicionarChecklistItemUseCase $adicionarChecklistItemUseCase,
        private readonly ToggleChecklistItemUseCase $toggleChecklistItemUseCase,
        private readonly EditarChecklistItemUseCase $editarChecklistItemUseCase,
        private readonly ExcluirChecklistItemUseCase $excluirChecklistItemUseCase,
        private readonly ReordenarChecklistItensUseCase $reordenarChecklistItensUseCase,
        private readonly AlterarPrioridadeUseCase $alterarPrioridadeUseCase,
        private readonly PastaSecaoRepository $secaoRepository,
        private readonly UserTenantRepository $userTenantRepo,
        private readonly CriarPastaUseCase $criarPastaUseCase,
        private readonly EditarPastaUseCase $editarPastaUseCase,
        private readonly ExcluirPastaUseCase $excluirPastaUseCase,
        private readonly RestaurarPastaUseCase $restaurarPastaUseCase,
        private readonly VincularProcessoUseCase $vincularProcessoUseCase,
        private readonly DesvincularProcessoUseCase $desvincularProcessoUseCase,
        private readonly DefinirProcessoPrincipalUseCase $definirProcessoPrincipalUseCase,
        private readonly DefinirClientePrincipalUseCase $definirClientePrincipalUseCase,
        private readonly SincronizacaoPastaDispatcher $syncDispatcher,
        private readonly PublicacaoDjenRepository $publicacaoDjenRepository,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('', name: 'pasta_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('expediente_index');
    }

    #[Route('/nova', name: 'pasta_new', methods: ['POST'])]
    public function criar(Request $request): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        // A modal de nova pasta envia por AJAX (o aviso de duplicada é modal, não página). Sem JS,
        // o POST cai no caminho de sempre (render/redirect) — degradação graciosa, mesmo controller.
        $ajax = $request->isXmlHttpRequest();

        if (!$this->permissionChecker->canAccessModule($currentUser, $tenant, 'pastas')) {
            if ($ajax) {
                return new JsonResponse(
                    ['status' => 'erro', 'mensagem' => 'Você não tem permissão para acessar o módulo de pastas.'],
                    Response::HTTP_FORBIDDEN,
                );
            }
            $this->addFlash('warning', 'Você não tem permissão para acessar o módulo de pastas.');
            return $this->redirectToRoute('expediente_index');
        }

        // R1: o número NÃO vem mais da tela — é sequência interna do escritório, atribuída pelo
        // GerarNumeroDePasta. O campo saiu do modal justamente para encerrar a colisão manual
        // (em produção, 3 números duplicados por duas pessoas criando a mesma pasta ao mesmo
        // tempo). `nup: null` = gerar. Os caminhos que TÊM número de origem (importação do CSV do
        // acervo e descoberta pelo Drive) continuam passando o seu direto ao UseCase.
        $nomeCliente = ($v = trim((string) $request->request->get('nome_cliente', ''))) !== '' ? $v : null;
        $nomeAcao    = ($v = trim((string) $request->request->get('nome_acao', ''))) !== '' ? $v : null;

        // Aviso de duplicada (D12.5). A numeração automática fechou a colisão de NÚMERO, não a de
        // PASTA: duas pessoas abrindo o mesmo caso ao mesmo tempo agora ganham 1232 e 1233 — duas
        // pastas do mesmo processo, e invisíveis para a consulta que procura número repetido.
        // Aqui o sistema AVISA e pede confirmação; nunca bloqueia, porque o mesmo cliente pode ter
        // vários casos parecidos de verdade.
        if (!$request->request->getBoolean('confirmar') && $tenant !== null) {
            $semelhantes = $this->pastaRepository->findSemelhantesPorClienteEAcao($tenant, $nomeCliente, $nomeAcao);
            if ($semelhantes !== []) {
                $dados = [
                    'semelhantes' => $semelhantes,
                    // O TOTAL não é o tamanho da lista: ela é truncada em 5 e há par com 27
                    // pastas em produção. A tela mostra as 5 mais recentes e diz o total real.
                    'total'       => $this->pastaRepository->contarSemelhantesPorClienteEAcao($tenant, $nomeCliente, $nomeAcao),
                    'nomeCliente' => $nomeCliente,
                    'nomeAcao'    => $nomeAcao,
                ];
                if ($ajax) {
                    // Devolve só o miolo da modal, pronto para o JS injetar e exibir.
                    return new JsonResponse([
                        'status' => 'duplicada',
                        'html'   => $this->renderView('pasta/_aviso_duplicada_modal.html.twig', $dados),
                    ]);
                }
                // Sem JS: página inteira de confirmação (fallback, mesmo conteúdo).
                return $this->render('pasta/confirmar_duplicada.html.twig', $dados);
            }
        }

        $dto = new CriarPastaDTO(
            nomeCliente: $nomeCliente,
            nomeAcao: $nomeAcao,
        );

        try {
            $pasta = $this->criarPastaUseCase->executar($dto, $currentUser, $tenant);
        } catch (\InvalidArgumentException $e) {
            // Defensivo e simétrico ao caminho não-AJAX (que já tinha este catch): hoje o endpoint
            // sempre gera o número (nup=null), então o UseCase não lança aqui — por isso não há
            // teste HTTP do 422. Mantido para não divergir do fallback se uma validação futura
            // passar a lançar nesta rota.
            if ($ajax) {
                return new JsonResponse(
                    ['status' => 'erro', 'mensagem' => $e->getMessage()],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('expediente_index');
        }

        if ($tenant !== null) {
            $this->syncDispatcher->despachar($pasta, $currentUser, $tenant);
        }

        if ($ajax) {
            return new JsonResponse([
                'status'   => 'ok',
                'redirect' => $this->generateUrl('pasta_show', ['id' => $pasta->getId()]),
            ]);
        }

        $this->addFlash('success', 'Pasta criada com sucesso.');

        return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId()]);
    }

    #[Route('/{id}', name: 'pasta_show', methods: ['GET'])]
    public function show(
        Pasta $pasta,
        ListarAnalisesDaPastaUseCase $listarAnalisesIa,
        DisponibilidadeDeInteligencia $disponibilidadeIa,
        PastaDocumentoFavoritoRepository $favoritosDeDocumento,
    ): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();

        $pastaId = (int) $pasta->getId();
        if ($redirect = $this->denyResourceAccessUnlessGranted($this->permissionChecker, $this->tenantContext->getCurrentTenant(), AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_VIEW, 'pasta_index', $pasta->getNup() ?? '#' . $pastaId)) {
            return $redirect;
        }

        $tenant    = $this->tenantContext->getCurrentTenant();
        $tenantId  = $tenant?->getId() ?? 0;
        $processoId = $pasta->getProcessoPrincipal()?->getId();

        $timelineItems   = $this->timelineAssembler->montar($pasta, $tenant, $tenantId, $processoId);
        $todosMarcadores = $this->marcadorRepository->findTodosPorTenant($tenant);

        // O redesenho parte a timeline em dois destinos que antes disputavam a
        // mesma coluna: o que uma PESSOA escreveu fica na zona quente da aba
        // Dados ("Anotações do caso"), e o que o SISTEMA registrou vai para o
        // drawer do Histórico. O histórico automático ocupava mais altura que o
        // caso inteiro; a divisão é a razão de ser do redesenho.
        $anotacoes = array_values(array_filter(
            $timelineItems,
            static fn (TimelineItemDTO $i) => $i->tipo === TimelineItemType::MENSAGEM,
        ));
        $eventosHistorico = array_values(array_filter(
            $timelineItems,
            static fn (TimelineItemDTO $i) => $i->tipo === TimelineItemType::EVENTO,
        ));

        // Cartão "Próximos prazos" do trilho: as metas abertas mais perto do
        // vencimento. Sai da coleção que a aba Metas já carrega — nenhuma
        // consulta a mais por causa deste cartão.
        $proximosPrazos = PastaPrazoOutput::proximas($pasta->getTarefas());

        $usuarios = $tenant !== null
            ? $this->userRepository->findColaboradoresAtivosPorTenant($tenant)
            : [];

        // Fotos do menu de responsável, num mapa userId => fotoUrl. Uma consulta para
        // todos, em vez de acordar o profile de cada colaborador na renderização.
        $fotosResponsaveis = $tenant !== null
            ? $this->userRepository->findFotoPorColaboradores($tenant)
            : [];

        $documentosContrato      = $tenant !== null
            ? $this->pastaDocumentoRepository->findByPastaECategoria($pasta, PastaDocumento::CATEGORIA_CONTRATO)
            : [];
        $observacoesFinanceiras  = $tenant !== null
            ? $this->observacaoFinanceiraRepository->findByPasta($pasta, $tenant)
            : [];

        // Card Pagamentos do trilho da aba Financeiro: o que o cliente combinou
        // pagar por ESTE caso. Nada a ver com a Cobrança, onde o dinheiro é a
        // dívida de um devedor numa carteira.
        $pagamentosDaPasta = $tenant !== null
            ? $this->pastaPagamentoRepository->findByPasta($pasta, $tenant)
            : [];
        $observacoesDetalhes     = $tenant !== null
            ? $this->observacaoDetalhesRepository->findByPasta($pasta, $tenant)
            : [];

        $checklistItens      = $tenant !== null ? $this->checklistRepository->findByPasta($pasta, $tenant) : [];
        $totalChecklist      = count($checklistItens);
        $concluidosChecklist = count(array_filter($checklistItens, fn($i) => $i->isConcluido()));

        $conferenciaChecklist = ConferenciaDeAnexosDoChecklist::daPasta($checklistItens, $pasta->getDocumentos(), $tenant); // selo "sem anexo" + pendência da aba (DOC-74/76)
        $secoes = $tenant !== null ? $this->secaoRepository->findByPasta($pasta, $tenant) : [];

        // Explorador da aba Documentos: pastas, arquivos, rótulos e tokens num JSON só, de UMA
        // consulta (seção joinada). As contagens recursivas de cada pasta — o aviso de exclusão
        // "contém 3 subpastas e 127 arquivos" precisa delas ANTES do clique — saem em memória.
        $explorador = ExploradorDeDocumentosOutput::montar(
            $secoes,
            $tenant !== null ? $this->pastaDocumentoRepository->findByPastaComSecao($pasta, $tenant) : [],
            self::DOCUMENT_TYPES,
            fn (string $rota, array $params): string => $this->generateUrl($rota, $params),
            fn (string $idDoToken): string => $this->csrfTokenManager->getToken($idDoToken)->getValue(),
            $pastaId,
            $tenant !== null ? $favoritosDeDocumento->idsFavoritosDaPasta($currentUser, $pasta, $tenant) : [], // estrelas DO usuário logado (D2)
        );

        // Faixa do topo da aba Financeiro. A média por CPF é do cliente PRINCIPAL da pasta —
        // o marcado explicitamente, ou o de cadastro mais antigo enquanto ninguém marcou nada.
        // Sem cliente vinculado não há CPF para agrupar, e a tela mostra travessão em vez de
        // inventar um número.
        $primeiroCliente = $pasta->getClientePrincipal();
        $mediaCpf        = $primeiroCliente !== null && $tenant !== null
            ? $this->pastaRepository->mediaValorCausaPorCliente($primeiroCliente, $tenant)
            : null;

        // Aba Push Processual: as publicações captadas do DJEN para os processos DESTA pasta.
        // O casamento é pelo NÚMERO CNJ e não pela FK `processo` da publicação — a FK só é
        // gravada durante a sincronização, então publicação que chegou ANTES de o processo entrar
        // no cadastro nunca a recebe (em produção, 8 das 59 publicações de pastas estavam assim).
        $numerosDosProcessos = [];
        foreach ($pasta->getPastaProcessos() as $vinculo) {
            $numerosDosProcessos[] = $vinculo->getProcesso()->getNumeroProcesso();
        }
        $push = PastaPushOutput::montar(
            $tenant !== null
                ? $this->publicacaoDjenRepository->listarItensPorNumerosDoTenant($tenant, $numerosDosProcessos, self::PUSH_LIMITE)
                : [],
            $numerosDosProcessos,
            self::PUSH_LIMITE,
        );

        // BlueJus IA na aba Push (spec inteligencia-resumo-do-push §3.6): sem `modules.inteligencia.view`
        // a aba não mostra nada de IA, então nem se consulta — `null` é o sinal para o template.
        // Com a plataforma SEM IA também não se consulta nada da IA (as tabelas podem nem existir
        // num deploy parcial): o template recebe um estado vazio e mostra o botão desabilitado com
        // o motivo real ("IA não configurada nesta instalação").
        $analisesIa = null;
        if ($tenant !== null
            && $this->permissionChecker->canAccessModule($currentUser, $tenant, DisponibilidadeDeInteligencia::MODULO)
        ) {
            $analisesIa = $disponibilidadeIa->plataformaConfigurada()
                ? $listarAnalisesIa->executar($pasta, $tenant)
                : AnalisesDaPastaOutput::semInteligenciaNaPlataforma($push->total);
        }

        // Setas ‹ › do cabeçalho: a pasta de cima e a de baixo na ordem da lista do Expediente.
        // Não dependem de filtro nem de sessão — quem chega por link direto navega igual.
        // O "N de M" entre elas sai do mesmo conjunto e da mesma ordem.
        $vizinhas = PastaVizinhasOutput::montar(
            $this->pastaRepository->vizinhasNoAcervo($pasta),
            $this->pastaRepository->posicaoNoAcervo($pasta),
        );

        return $this->render('pasta/show.html.twig', [
            'pasta'                       => $pasta,
            'vizinhas'                    => $vizinhas,
            'documentTypeOptions'         => self::DOCUMENT_TYPES,
            'documentosPorTipo'           => $this->groupDocumentsByType($pasta),
            'timelineItems'               => $timelineItems,
            'anotacoes'                   => $anotacoes,
            'eventosHistorico'            => $eventosHistorico,
            'proximosPrazos'              => $proximosPrazos,
            'todosMarcadores'             => $todosMarcadores,
            'usuarios'                    => $usuarios,
            'fotosResponsaveis'           => $fotosResponsaveis,
            'documentosContrato'          => $documentosContrato,
            'financeiro'                  => PastaFinanceiroOutput::montar($pasta, $primeiroCliente, $mediaCpf),
            'observacoesFinanceiras'      => $observacoesFinanceiras,
            'pagamentos'                  => PastaPagamentosOutput::montar(
                $pagamentosDaPasta,
                null,
                $tenant !== null ? $this->pastaPagamentoRepository->correcoesDeValor($pagamentosDaPasta, $tenant) : [],
            ),
            'observacoesDetalhes'         => $observacoesDetalhes,
            'checklistItens'              => $checklistItens,
            'totalChecklist'              => $totalChecklist,
            'concluidosChecklist'         => $concluidosChecklist,
            'conferenciaChecklist'        => $conferenciaChecklist,
            'conflitoCnj'                 => ConflitoDeNumeroCnj::daPasta($pasta, $numerosDosProcessos, $tenant),
            'organizacaoSugerida'         => OrganizacaoSugeridaPorFase::porFase(),
            'secoes'                      => $secoes,
            'explorador'                  => $explorador,
            'push'                        => $push,
            'analisesIa'                  => $analisesIa,
            // Linha vermelha sob as abas (desenho 1.2.3): calculada aqui, a tela só mostra.
            'pendencias'                  => PastaPendenciasOutput::montar($pasta, $push->naoLidas, $pagamentosDaPasta, itensMarcadosSemAnexo: $conferenciaChecklist->totalMarcadosSemAnexo()),
            'metasResumo'                 => PastaMetasResumoOutput::montar($pasta),
            // Atalho para a UNIDADE cobrada — só existe em pasta que veio de uma
            // judicialização (6 das 1.099 em produção). `null` nas demais, e o cabeçalho
            // simplesmente não desenha o campo.
            'unidadeCobrada'              => $tenant === null
                ? null
                : $this->casoCobrancaRepository->unidadeCobradaDaPasta($pasta, $tenant),
        ]);
    }

    #[Route('/{id}/cliente/novo', name: 'pasta_cliente_novo', methods: ['POST'])]
    public function novoCliente(Pasta $pasta, Request $request): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();

        $pastaId = (int) $pasta->getId();
        if ($redirect = $this->denyResourceAccessUnlessGranted($this->permissionChecker, $this->tenantContext->getCurrentTenant(), AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_EDIT, 'pasta_index', $pasta->getNup() ?? '#' . $pastaId)) {
            return $redirect;
        }

        $isXhr = $request->isXmlHttpRequest();

        if (!$this->isCsrfTokenValid('pasta_cliente_novo_' . $pasta->getId(), (string) $request->request->get('_token'))) {
            if ($isXhr) {
                return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('error', 'Token de segurança inválido.');
            return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId(), '_fragment' => 'partes']);
        }

        $tipo = $request->request->get('tipo');
        $dados = $request->request->all();

        if ($tipo === 'pf') {
            $cliente = new ClientePF();
            $nomeCompleto = trim((string) ($dados['nomeCompleto'] ?? ''));
            $cpf          = trim((string) ($dados['cpf'] ?? ''));
            $rg           = trim((string) ($dados['rg'] ?? ''));
            $rgOrgao      = trim((string) ($dados['rgOrgaoExpedidor'] ?? ''));

            if ($nomeCompleto === '' || $cpf === '' || $rg === '' || $rgOrgao === '') {
                if ($isXhr) {
                    return $this->json(['erro' => 'Nome completo, CPF, RG e órgão expedidor são obrigatórios.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $this->addFlash('error', 'Nome completo, CPF, RG e órgão expedidor são obrigatórios.');
                return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId(), '_fragment' => 'partes']);
            }

            $cliente->setNomeCompleto($nomeCompleto);
            $cliente->setCpf($cpf);
            $cliente->setRg($rg);
            $cliente->setRgOrgaoExpedidor($rgOrgao);

            $rgData = trim((string) ($dados['rgDataEmissao'] ?? ''));
            if ($rgData !== '') {
                $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $rgData);
                if ($dt !== false) {
                    $cliente->setRgDataEmissao($dt);
                }
            }

            $nascimento = trim((string) ($dados['dataNascimento'] ?? ''));
            if ($nascimento !== '') {
                $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $nascimento);
                if ($dt !== false) {
                    $cliente->setDataNascimento($dt);
                }
            }

            $estadoCivil = trim((string) ($dados['estadoCivil'] ?? ''));
            if ($estadoCivil !== '') {
                $cliente->setEstadoCivil($estadoCivil);
            }

            $profissao = trim((string) ($dados['profissao'] ?? ''));
            if ($profissao !== '') {
                $cliente->setProfissao($profissao);
            }
        } elseif ($tipo === 'pj') {
            $cliente = new ClientePJ();
            $razaoSocial      = trim((string) ($dados['razaoSocial'] ?? ''));
            $cnpj             = trim((string) ($dados['cnpj'] ?? ''));
            $enderecSede      = trim((string) ($dados['enderecSede'] ?? ''));
            $representante    = trim((string) ($dados['representanteLegal'] ?? ''));
            $representanteCpf = trim((string) ($dados['representanteCpf'] ?? ''));
            $representanteRg  = trim((string) ($dados['representanteRg'] ?? ''));
            $representanteCargo = trim((string) ($dados['representanteCargo'] ?? ''));

            if ($razaoSocial === '' || $cnpj === '' || $enderecSede === '' || $representante === '' || $representanteCpf === '' || $representanteRg === '' || $representanteCargo === '') {
                if ($isXhr) {
                    return $this->json(['erro' => 'Razão social, CNPJ, endereço sede e dados do representante legal são obrigatórios.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $this->addFlash('error', 'Razão social, CNPJ, endereço sede e dados do representante legal são obrigatórios.');
                return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId(), '_fragment' => 'partes']);
            }

            $cliente->setRazaoSocial($razaoSocial);
            $cliente->setCnpj($cnpj);
            $cliente->setEnderecSede($enderecSede);
            $cliente->setRepresentanteLegal($representante);
            $cliente->setRepresentanteCpf($representanteCpf);
            $cliente->setRepresentanteRg($representanteRg);
            $cliente->setRepresentanteCargo($representanteCargo);

            $nomeFantasia = trim((string) ($dados['nomeFantasia'] ?? ''));
            if ($nomeFantasia !== '') {
                $cliente->setNomeFantasia($nomeFantasia);
            }

            $inscricaoEstadual = trim((string) ($dados['inscricaoEstadual'] ?? ''));
            if ($inscricaoEstadual !== '') {
                $cliente->setInscricaoEstadual($inscricaoEstadual);
            }

            $inscricaoMunicipal = trim((string) ($dados['inscricaoMunicipal'] ?? ''));
            if ($inscricaoMunicipal !== '') {
                $cliente->setInscricaoMunicipal($inscricaoMunicipal);
            }
        } else {
            if ($isXhr) {
                return $this->json(['erro' => 'Tipo de cliente inválido.'], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('error', 'Tipo de cliente inválido.');
            return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId(), '_fragment' => 'partes']);
        }

        // Campos comuns
        $email = trim((string) ($dados['email'] ?? ''));
        if ($email === '') {
            if ($isXhr) {
                return $this->json(['erro' => 'O e-mail é obrigatório.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $this->addFlash('error', 'O e-mail é obrigatório.');
            return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId(), '_fragment' => 'partes']);
        }
        $cliente->setEmail($email);

        $cep = trim((string) ($dados['cep'] ?? ''));
        $endereco = trim((string) ($dados['endereco'] ?? ''));
        $cidade = trim((string) ($dados['cidade'] ?? ''));
        $estado = trim((string) ($dados['estado'] ?? ''));

        if ($cep === '' || $endereco === '' || $cidade === '' || $estado === '') {
            if ($isXhr) {
                return $this->json(['erro' => 'CEP, endereço, cidade e estado são obrigatórios.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $this->addFlash('error', 'CEP, endereço, cidade e estado são obrigatórios.');
            return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId(), '_fragment' => 'partes']);
        }
        $cliente->setCep($cep);
        $cliente->setEndereco($endereco);
        $cliente->setCidade($cidade);
        $cliente->setEstado(substr($estado, 0, 2));

        $complemento = trim((string) ($dados['complemento'] ?? ''));
        if ($complemento !== '') {
            $cliente->setComplemento($complemento);
        }

        $celular = trim((string) ($dados['telefoneCelular'] ?? ''));
        if ($celular !== '') {
            $cliente->setTelefoneCelular($celular);
        }

        $fixo = trim((string) ($dados['telefoneFixo'] ?? ''));
        if ($fixo !== '') {
            $cliente->setTelefoneFixo($fixo);
        }

        // tenant setado ANTES da checagem de duplicidade e escopado explicitamente no findOneBy:
        // cpf/cnpj são únicos POR ESCRITÓRIO (C3). Sem 'tenant' no critério, a checagem dependeria
        // só do TenantFilter de sessão — vazaria existência cross-tenant e bloquearia indevidamente
        // quando o filtro estivesse desligado (super-admin/CLI).
        $tenant = $this->tenantContext->getCurrentTenant();
        $cliente->setTenant($tenant);

        if ($cliente instanceof ClientePF) {
            $cpfExistente = $this->clientePFRepository->findOneBy(['cpf' => $cliente->getCpf(), 'tenant' => $tenant]);
            if ($cpfExistente !== null) {
                $msg = sprintf('Já existe um cliente cadastrado com o CPF %s.', $cliente->getCpf());
                if ($isXhr) {
                    return $this->json(['erro' => $msg], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $this->addFlash('error', $msg);
                return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId(), '_fragment' => 'partes']);
            }
        } elseif ($cliente instanceof ClientePJ) {
            $cnpjExistente = $this->clientePJRepository->findOneBy(['cnpj' => $cliente->getCnpj(), 'tenant' => $tenant]);
            if ($cnpjExistente !== null) {
                $msg = sprintf('Já existe um cliente cadastrado com o CNPJ %s.', $cliente->getCnpj());
                if ($isXhr) {
                    return $this->json(['erro' => $msg], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $this->addFlash('error', $msg);
                return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId(), '_fragment' => 'partes']);
            }
        }

        $cliente->setCriadoPor($currentUser);

        $this->em->persist($cliente);
        $pasta->addCliente($cliente);
        $this->em->flush();

        if ($isXhr) {
            return $this->json([
                'sucesso' => true,
                'cliente' => [
                    'id' => $cliente->getId(),
                    'nome' => $cliente instanceof ClientePF ? $cliente->getNomeCompleto() : $cliente->getRazaoSocial(),
                    'documento' => $cliente instanceof ClientePF ? $cliente->getCpf() : $cliente->getCnpj(),
                    'tipo' => $cliente instanceof ClientePF ? 'PF' : 'PJ',
                    'csrfToken' => $this->csrfTokenManager->getToken('pasta_cliente_desvincular_' . $pasta->getId() . '_' . $cliente->getId())->getValue(),
                    'csrfTokenUpload' => $this->csrfTokenManager->getToken('upload_documento_cliente_' . $cliente->getId())->getValue(),
                    'csrfTokenPrincipal' => $this->csrfTokenManager->getToken('pasta_cliente_principal_' . $pasta->getId() . '_' . $cliente->getId())->getValue(),
                ],
                // Vincular pode TROCAR o principal automático: se o novo cliente for o de cadastro
                // mais antigo e ninguém tiver marcado nada, a média passa a ser dele. A tela precisa
                // saber disso na hora, senão mostra número velho até alguém dar F5.
                'principal' => $this->payloadClientePrincipal($pasta),
                // A linha pronta, pelo MESMO partial da página (ver htmlLinhaCliente).
                'html'  => $this->htmlLinhaCliente($pasta, $cliente),
                'total' => $pasta->getClientes()->count(),
            ]);
        }

        $this->addFlash('success', 'Cliente cadastrado e vinculado à pasta com sucesso.');

        return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId(), '_fragment' => 'partes']);
    }

    #[Route('/{id}/cliente/{cliente}/desvincular', name: 'pasta_cliente_desvincular', methods: ['POST'])]
    public function desvincularCliente(Pasta $pasta, Cliente $cliente, Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $pastaId = (int) $pasta->getId();
        if ($redirect = $this->denyResourceAccessUnlessGranted($this->permissionChecker, $this->tenantContext->getCurrentTenant(), AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_EDIT, 'pasta_index', $pasta->getNup() ?? '#' . $pastaId)) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid('pasta_cliente_desvincular_' . $pasta->getId() . '_' . $cliente->getId(), (string) $request->request->get('_token'))) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('error', 'Token de segurança inválido.');
            return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId(), '_fragment' => 'partes']);
        }

        if (!$pasta->getClientes()->contains($cliente)) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['erro' => 'Cliente não vinculado a esta pasta.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $this->addFlash('warning', 'Cliente não vinculado a esta pasta.');
            return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId(), '_fragment' => 'partes']);
        }

        // Desvincular o PRINCIPAL faz o servidor promover outro (Pasta::removeCliente → o de
        // cadastro mais antigo). A tela precisa saber QUAL, senão a estrela, o selo e a Média
        // por CPF ficam sem dono até alguém dar F5.
        $eraPrincipal = $pasta->getClientePrincipal()?->getId() === $cliente->getId();

        $pasta->removeCliente($cliente);
        $this->em->flush();

        if ($request->isXmlHttpRequest()) {
            $novoPrincipal = $eraPrincipal ? $pasta->getClientePrincipal() : null;

            return $this->json([
                'sucesso'         => true,
                'clienteId'       => $cliente->getId(),
                // null = o principal não mudou (o desvinculado não era ele) ou a pasta ficou sem
                // cliente. Só com id a tela tem o que mover.
                'novoPrincipalId' => $novoPrincipal?->getId(),
                // A linha do promovido re-renderizada pelo MESMO partial (selo e estrela cheia),
                // como no vínculo (htmlLinhaCliente).
                'html'            => $novoPrincipal !== null ? $this->htmlLinhaCliente($pasta, $novoPrincipal) : null,
                'principal'       => $novoPrincipal !== null ? $this->payloadClientePrincipal($pasta) : null,
            ]);
        }

        $this->addFlash('success', 'Cliente desvinculado da pasta com sucesso.');
        return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId(), '_fragment' => 'partes']);
    }

    /**
     * Marca qual cliente representa a pasta nos indicadores (a "Média por CPF" da aba Financeiro).
     *
     * A resposta XHR devolve a média já recalculada: a marcação existe PARA mudar esse número, e
     * deixá-lo defasado na tela seria esconder o efeito da própria ação. Sem JS, o form normal cai
     * no redirect com o fragmento da aba.
     */
    #[Route('/{id}/cliente/{cliente}/principal', name: 'pasta_cliente_principal', methods: ['POST'])]
    public function definirClientePrincipal(Pasta $pasta, Cliente $cliente, Request $request): Response
    {
        $pastaId = (int) $pasta->getId();
        $isXhr   = $request->isXmlHttpRequest();
        $tenant  = $this->tenantContext->getCurrentTenant();

        // Sem permissão: para XHR devolve JSON 403 (como vincularCliente e buscarClientes fazem
        // neste mesmo controller). O redirect do fluxo "pedir acesso" só serve ao POST de form —
        // devolvê-lo a uma chamada AJAX faria o JS tentar interpretar HTML como JSON.
        if ($redirect = $this->denyResourceAccessUnlessGranted($this->permissionChecker, $tenant, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_EDIT, 'pasta_index', $pasta->getNup() ?? '#' . $pastaId)) {
            return $isXhr ? $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN) : $redirect;
        }

        if (!$this->isCsrfTokenValid('pasta_cliente_principal_' . $pastaId . '_' . $cliente->getId(), (string) $request->request->get('_token'))) {
            return $this->respostaErroClientePrincipal($isXhr, 'Token de segurança inválido.', $pastaId, Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->definirClientePrincipalUseCase->executar($pasta, $cliente);
        } catch (\DomainException $e) {
            return $this->respostaErroClientePrincipal($isXhr, $e->getMessage(), $pastaId, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($isXhr) {
            return $this->json($this->payloadClientePrincipal($pasta));
        }

        $this->addFlash('success', 'Cliente principal atualizado.');
        return $this->redirectToRoute('pasta_show', ['id' => $pastaId, '_fragment' => 'dados']);
    }

    /**
     * O estado do cliente principal como a tela precisa dele — para a troca pela estrela e para o
     * vínculo de um cliente novo.
     *
     * `clienteId` é o principal EFETIVO lido da entidade, não o que veio na URL: quem responde é
     * sempre a mesma fonte, inclusive quando o vínculo acabou de gravar o primeiro principal.
     *
     * A média vai junto porque a marcação existe PARA mudar esse número; devolvê-lo defasado
     * esconderia o efeito da própria ação.
     */
    private function payloadClientePrincipal(Pasta $pasta): array
    {
        $tenant    = $this->tenantContext->getCurrentTenant();
        $principal = $pasta->getClientePrincipal();
        $mediaCpf  = $principal !== null && $tenant !== null
            ? $this->pastaRepository->mediaValorCausaPorCliente($principal, $tenant)
            : null;

        return [
            'sucesso'        => true,
            'clienteId'      => $principal?->getId(),
            'mediaFormatada' => PastaFinanceiroOutput::formatarReais($mediaCpf),
            'mediaRotulo'    => PastaFinanceiroOutput::montar($pasta, $principal, $mediaCpf)->mediaRotulo,
            'clienteNome'    => $principal?->getNomeExibicao(),
        ];
    }

    private function respostaErroClientePrincipal(bool $isXhr, string $mensagem, int $pastaId, int $status): Response
    {
        if ($isXhr) {
            return $this->json(['erro' => $mensagem], $status);
        }

        $this->addFlash('error', $mensagem);
        return $this->redirectToRoute('pasta_show', ['id' => $pastaId, '_fragment' => 'dados']);
    }

    /**
     * Busca de cliente para vincular à pasta — usada pelo modal "Adicionar Cliente" e pela busca
     * inline do cartão Clientes do trilho (desenho 1.2.3, L.1357-1372 e L.3304).
     *
     * JSON de cada item: `id`, `nome`, `tipo` (PF|PJ), `documento`, `documentoRotulo` (CPF|CNPJ|
     * Documento), `completo` (bool, PendenciasDoCadastro), `jaVinculado` (bool) e `selo`
     * (`completo` | `incompleto` | `ja_vinculado`).
     *
     * Casamento: o NOME casa por trecho; o DOCUMENTO só casa quando os dígitos do termo são o
     * documento inteiro (11 para CPF, 14 para CNPJ) e iguais a ele. Trecho de dígitos não casa com
     * documento: se casasse, buscas por pedaços ("456", "4567", …) confirmariam dígito a dígito o
     * que a máscara esconde.
     *
     * PII: `documento` sai MASCARADO (`***.456.789-**`) e só vem inteiro quando o termo digitado
     * é o próprio documento inteiro — quem já sabe o CPF não aprende nada; quem digita um nome
     * não coleta o CPF de todo mundo que se chama "Maria".
     *
     * Vinculados: o modal antigo nunca os recebe (contrato dele). A busca do trilho pede
     * `incluirVinculados=1` para mostrar o selo "Já vinculado", como o desenho.
     */
    #[Route('/{id}/clientes/buscar', name: 'pasta_clientes_buscar', methods: ['GET'])]
    public function buscarClientes(Pasta $pasta, Request $request, PendenciasDoCadastro $pendencias): JsonResponse
    {
        $pastaId = (int) $pasta->getId();
        $tenant  = $this->tenantContext->getCurrentTenant();
        if ($this->denyResourceAccessUnlessGranted($this->permissionChecker, $tenant, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_VIEW, 'pasta_index', $pasta->getNup() ?? '#' . $pastaId)) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        $termo = trim((string) $request->query->get('q', ''));
        if (mb_strlen($termo) < 2) {
            return $this->json([]);
        }

        $incluirVinculados  = $request->query->getBoolean('incluirVinculados');
        $clientesVinculados = array_values($pasta->getClientes()->map(fn($c) => $c->getId())->toArray());
        $termoMinusculo     = mb_strtolower($termo);
        $termoDigitos       = (string) preg_replace('/\D/', '', $termo);
        $documentoInteiroDigitado = in_array(strlen($termoDigitos), [11, 14], true);

        $achados = [];
        foreach ($this->clienteRepository->findAll() as $cliente) {
            // Defesa em profundidade: o TenantFilter já restringe o findAll, mas um cliente de
            // outro escritório aqui vazaria nome e documento — conferir de novo custa nada.
            if ($tenant !== null && $cliente->getTenant()?->getId() !== $tenant->getId()) {
                continue;
            }

            $jaVinculado = in_array($cliente->getId(), $clientesVinculados, true);
            if ($jaVinculado && !$incluirVinculados) {
                continue;
            }

            $nome = $cliente instanceof ClientePF
                ? $cliente->getNomeCompleto()
                : ($cliente instanceof ClientePJ ? $cliente->getRazaoSocial() : '');

            $documento = $cliente instanceof ClientePF
                ? ($cliente->getCpf() ?? '')
                : ($cliente instanceof ClientePJ ? ($cliente->getCnpj() ?? '') : '');
            $documentoDigitos = (string) preg_replace('/\D/', '', $documento);

            // Documento só casa INTEIRO (11 dígitos de CPF, 14 de CNPJ, iguais ao gravado): trecho
            // de dígitos não casa, senão buscas sucessivas por pedaços revelariam o documento
            // apesar da máscara. O nome continua casando por trecho. Documento 2, nome 1.
            $pontos = 0;
            if ($documentoInteiroDigitado && $documentoDigitos === $termoDigitos) {
                $pontos = 2;
            } elseif (str_contains(mb_strtolower($nome), $termoMinusculo)) {
                $pontos = 1;
            }

            if ($pontos > 0) {
                $achados[] = [$pontos, $cliente, $nome, $documento, $documentoDigitos, $jaVinculado];
            }
        }

        // Ordenação estável: empate mantém a ordem do repositório.
        usort($achados, static fn(array $a, array $b) => $b[0] <=> $a[0]);

        $resultado = [];
        foreach (array_slice($achados, 0, 10) as [, $cliente, $nome, $documento, $documentoDigitos, $jaVinculado]) {
            $documentoInteiro = $documentoInteiroDigitado && $documentoDigitos === $termoDigitos;
            $completo         = $pendencias->estaCompleto($cliente);

            $resultado[] = [
                'id'              => $cliente->getId(),
                'nome'            => $nome,
                'documento'       => $documentoInteiro ? $documento : self::mascararDocumento($documentoDigitos),
                'documentoRotulo' => match (strlen($documentoDigitos)) { 11 => 'CPF', 14 => 'CNPJ', default => 'Documento' },
                'tipo'            => $cliente instanceof ClientePF ? 'PF' : 'PJ',
                'completo'        => $completo,
                'jaVinculado'     => $jaVinculado,
                'selo'            => $jaVinculado ? 'ja_vinculado' : ($completo ? 'completo' : 'incompleto'),
            ];
        }

        return $this->json($resultado);
    }

    /**
     * Máscara do desenho (L.3304): CPF `***.456.789-**`. CNPJ no mesmo espírito — some o começo
     * e o fim, fica o miolo: `**.345.678/****-**`. Documento fora do formato não tem miolo
     * confiável e sai todo coberto.
     */
    private static function mascararDocumento(string $digitos): string
    {
        return match (strlen($digitos)) {
            0       => '',
            11      => '***.' . substr($digitos, 3, 3) . '.' . substr($digitos, 6, 3) . '-**',
            14      => '**.' . substr($digitos, 2, 3) . '.' . substr($digitos, 5, 3) . '/****-**',
            default => str_repeat('*', strlen($digitos)),
        };
    }

    #[Route('/{id}/cliente/vincular', name: 'pasta_cliente_vincular', methods: ['POST'])]
    public function vincularCliente(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();

        $pastaId = (int) $pasta->getId();
        if ($this->denyResourceAccessUnlessGranted($this->permissionChecker, $this->tenantContext->getCurrentTenant(), AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_EDIT, 'pasta_index', $pasta->getNup() ?? '#' . $pastaId)) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('pasta_cliente_vincular_' . $pastaId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_BAD_REQUEST);
        }

        $clienteId = (int) $request->request->get('cliente_id', 0);
        $cliente = $this->clienteRepository->find($clienteId);

        if (!$cliente) {
            return $this->json(['erro' => 'Cliente não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        if ($pasta->getClientes()->contains($cliente)) {
            return $this->json(['erro' => 'Este cliente já está vinculado à pasta.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $pasta->addCliente($cliente);
        $this->em->flush();

        return $this->json([
            'sucesso' => true,
            'cliente' => [
                'id' => $cliente->getId(),
                'nome' => $cliente instanceof ClientePF ? $cliente->getNomeCompleto() : $cliente->getRazaoSocial(),
                'documento' => $cliente instanceof ClientePF ? $cliente->getCpf() : $cliente->getCnpj(),
                'tipo' => $cliente instanceof ClientePF ? 'PF' : 'PJ',
                'csrfToken' => $this->csrfTokenManager->getToken('pasta_cliente_desvincular_' . $pastaId . '_' . $cliente->getId())->getValue(),
                'csrfTokenUpload' => $this->csrfTokenManager->getToken('upload_documento_cliente_' . $cliente->getId())->getValue(),
                'csrfTokenPrincipal' => $this->csrfTokenManager->getToken('pasta_cliente_principal_' . $pastaId . '_' . $cliente->getId())->getValue(),
            ],
            // Ver a nota em cadastrarEVincularCliente: vincular pode mover o principal automático.
            'principal' => $this->payloadClientePrincipal($pasta),
            // A linha pronta, pelo MESMO partial da página (ver htmlLinhaCliente).
            'html'  => $this->htmlLinhaCliente($pasta, $cliente),
            'total' => $pasta->getClientes()->count(),
        ]);
    }

    /**
     * A linha do cliente recém-vinculado renderizada pelo MESMO partial que a página usa
     * (`pasta/_cliente_linha.html.twig`), com o autoescape do Twig.
     *
     * Antes o JS do show montava a linha por um espelho próprio, que não tinha o ícone de
     * cadastro completo/incompleto nem o selo "Principal": a linha nova nascia com outra cara
     * até o próximo F5. Uma fonte só, e a divergência deixa de existir.
     */
    private function htmlLinhaCliente(Pasta $pasta, Cliente $cliente): string
    {
        return $this->renderView('pasta/_cliente_linha.html.twig', [
            'pasta'       => $pasta,
            'cliente'     => $cliente,
            'ehPrincipal' => $pasta->getClientePrincipal()?->getId() === $cliente->getId(),
        ]);
    }

    #[Route('/{id}/mensagem', name: 'pasta_enviar_mensagem', methods: ['POST'])]
    public function enviarMensagem(Pasta $pasta, Request $request, MencoesDoRegistro $mencoes): JsonResponse
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();

        $pastaId = (int) $pasta->getId();
        $tenant  = $this->tenantContext->getCurrentTenant();
        if ($redirect = $this->denyResourceAccessUnlessGranted($this->permissionChecker, $tenant, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_VIEW, 'pasta_index', $pasta->getNup() ?? '#' . $pastaId)) {
            return $this->json(['erro' => 'Sem permissão para acessar esta pasta.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('pasta_mensagem_' . $pasta->getId(), (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        $conteudo = trim((string) $request->request->get('conteudo', ''));

        if ($conteudo === '') {
            return $this->json(['erro' => 'A mensagem não pode ser vazia.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // "Responder": o id do registro respondido vem do navegador. Aqui só se acha a
        // mensagem; a posse (mesma pasta, mesmo escritório) é conferida no UseCase.
        $respostaA   = null;
        $respostaAId = (int) $request->request->get('resposta_a', 0);
        if ($respostaAId > 0) {
            $respostaA = $this->em->find(PastaMensagem::class, $respostaAId);
            if ($respostaA === null) {
                return $this->json(['erro' => 'O registro respondido não foi encontrado.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        try {
            $mensagem = $this->enviarMensagemUseCase->executar($pasta, $currentUser, $conteudo, $tenant, $respostaA);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'id'          => $mensagem->getId(),
            // cru volta ao editor; conteudoHtml é o que a tela exibe (sanitizado, com as
            // @menções em destaque — o mesmo filtro `registro_texto` do Twig).
            'conteudo'     => $mensagem->getConteudo(),
            'conteudoHtml' => $mencoes->exibir($mensagem->getConteudo()),
            'autorNome'   => $currentUser->getFullName(),
            'criadaEm'    => $mensagem->getCriadaEm()->format('d/m/Y H:i'),
            'criadaEmTs'  => $mensagem->getCriadaEm()->format(\DateTimeInterface::ATOM),
            'csrfEditar'  => $this->csrfTokenManager->getToken('pasta_mensagem_editar_' . $mensagem->getId())->getValue(),
            'csrfExcluir' => $this->csrfTokenManager->getToken('pasta_mensagem_excluir_' . $mensagem->getId())->getValue(),
            // A RAIZ respondida (já normalizada pelo UseCase): é sob ela que o JS pendura a resposta.
            'respostaA'     => $mensagem->getRespostaA()?->getId(),
            'respostaANome' => $mensagem->getRespostaA()?->getAutor()?->getFullName(),
        ], Response::HTTP_CREATED);
    }

    // ── Editar mensagem do chat (autor, dentro de 24h) ────────────────────────

    #[Route('/{id}/mensagem/{msgId}/editar', name: 'pasta_editar_mensagem', methods: ['POST'])]
    public function editarMensagem(Pasta $pasta, int $msgId, Request $request, MencoesDoRegistro $mencoes): JsonResponse
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();

        $pastaId = (int) $pasta->getId();
        $tenant  = $this->tenantContext->getCurrentTenant();
        if ($redirect = $this->denyResourceAccessUnlessGranted($this->permissionChecker, $tenant, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_VIEW, 'pasta_index', $pasta->getNup() ?? '#' . $pastaId)) {
            return $this->json(['erro' => 'Sem permissão para acessar esta pasta.'], Response::HTTP_FORBIDDEN);
        }

        $mensagem = $this->em->find(PastaMensagem::class, $msgId);
        if ($mensagem === null || $mensagem->getPasta()?->getId() !== $pastaId) {
            return $this->json(['erro' => 'Mensagem não encontrada.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isCsrfTokenValid('pasta_mensagem_editar_' . $msgId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        $conteudo = trim((string) $request->request->get('conteudo', ''));

        try {
            $this->editarMensagemUseCase->executar($mensagem, $currentUser, $tenant, $conteudo);
        } catch (MensagemPastaNaoEditavelException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'conteudo'     => $mensagem->getConteudo(),
            'conteudoHtml' => $mencoes->exibir($mensagem->getConteudo()),
            'editadaEm'    => $mensagem->getEditadaEm()?->format('d/m/Y H:i'),
        ]);
    }

    // ── Excluir mensagem do chat (autor, dentro de 24h) ───────────────────────

    #[Route('/{id}/mensagem/{msgId}/excluir', name: 'pasta_excluir_mensagem', methods: ['POST'])]
    public function excluirMensagem(Pasta $pasta, int $msgId, Request $request): JsonResponse
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();

        $pastaId = (int) $pasta->getId();
        $tenant  = $this->tenantContext->getCurrentTenant();
        if ($redirect = $this->denyResourceAccessUnlessGranted($this->permissionChecker, $tenant, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_VIEW, 'pasta_index', $pasta->getNup() ?? '#' . $pastaId)) {
            return $this->json(['erro' => 'Sem permissão para acessar esta pasta.'], Response::HTTP_FORBIDDEN);
        }

        $mensagem = $this->em->find(PastaMensagem::class, $msgId);
        if ($mensagem === null || $mensagem->getPasta()?->getId() !== $pastaId) {
            return $this->json(['erro' => 'Mensagem não encontrada.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isCsrfTokenValid('pasta_mensagem_excluir_' . $msgId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $this->excluirMensagemUseCase->executar($mensagem, $currentUser, $tenant);
        } catch (MensagemPastaNaoExcluivelException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        }

        return $this->json(['sucesso' => true]);
    }

    #[Route('/{id}/processo/vincular', name: 'pasta_vincular_processo', methods: ['POST'])]
    public function vincularProcesso(Pasta $pasta, Request $request, TribunalCnjResolver $tribunalResolver): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();

        $pastaId = (int) $pasta->getId();
        if ($redirect = $this->denyResourceAccessUnlessGranted($this->permissionChecker, $this->tenantContext->getCurrentTenant(), AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_EDIT, 'pasta_index', $pasta->getNup() ?? '#' . $pastaId)) {
            return $redirect;
        }

        $isXhr = $request->isXmlHttpRequest();

        if (!$this->isCsrfTokenValid('vincular_processo_' . $pastaId, (string) $request->request->get('_token'))) {
            if ($isXhr) {
                return $this->json(['erro' => 'Token CSRF inválido.'], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('pasta_show', ['id' => $pastaId]);
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        $data = $request->request->all();
        $processoId     = (int) ($data['processo_id'] ?? 0);
        $numeroProcesso = trim((string) ($data['numeroProcesso'] ?? ''));

        if ($processoId > 0) {
            // Lookup tenant-scoped: NÃO usar find() por PK, que ignora o filtro de tenant.
            $processo = $this->processoRepository->findOneByIdDoTenant($processoId, $tenant);
            if (!$processo instanceof Processo) {
                return $this->respostaErroProcesso($isXhr, 'Processo não encontrado.', $pastaId, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        } elseif ($numeroProcesso !== '') {
            $numeroNormalizado = preg_replace('/\D+/', '', $numeroProcesso) ?? '';
            $processo = $this->processoRepository->findByNumeroProcessoDoTenant($numeroNormalizado, $tenant);
            if ($processo === null) {
                $processo = new Processo();
                $processo->setTenant($tenant);
                $this->fillProcessoFromData($processo, $data, $tribunalResolver);
                $processo->setCriadoPor($currentUser);
                $this->em->persist($processo);
            }
        } else {
            return $this->respostaErroProcesso($isXhr, 'Informe o número do processo.', $pastaId, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->vincularProcessoUseCase->executar($pasta, $processo, $currentUser);

        if ($isXhr) {
            return $this->respostaProcessosVinculados($pasta);
        }

        $this->addFlash('success', 'Processo vinculado com sucesso.');
        return $this->redirectToRoute('pasta_show', ['id' => $pastaId, '_fragment' => 'processo']);
    }

    #[Route('/{id}/processo/desvincular', name: 'pasta_desvincular_processo', methods: ['POST'])]
    public function desvincularProcesso(Pasta $pasta, Request $request): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();

        $pastaId = (int) $pasta->getId();
        if ($redirect = $this->denyResourceAccessUnlessGranted($this->permissionChecker, $this->tenantContext->getCurrentTenant(), AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_EDIT, 'pasta_index', $pasta->getNup() ?? '#' . $pastaId)) {
            return $redirect;
        }

        $isXhr  = $request->isXmlHttpRequest();
        $tenant = $this->tenantContext->getCurrentTenant();

        if (!$this->isCsrfTokenValid('pasta_desvincular_processo_' . $pastaId, (string) $request->request->get('_token'))) {
            return $this->respostaErroProcesso($isXhr, 'Token de segurança inválido.', $pastaId, Response::HTTP_BAD_REQUEST);
        }

        $processoId = (int) $request->request->get('processo_id', 0);
        $processo   = $processoId > 0 ? $this->processoRepository->findOneByIdDoTenant($processoId, $tenant) : null;
        if (!$processo instanceof Processo || !$pasta->temProcesso($processo)) {
            return $this->respostaErroProcesso($isXhr, 'Processo não está vinculado a esta pasta.', $pastaId, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->desvincularProcessoUseCase->executar($pasta, $processo);

        if ($isXhr) {
            return $this->respostaProcessosVinculados($pasta);
        }

        $this->addFlash('success', 'Processo desvinculado da pasta com sucesso.');
        return $this->redirectToRoute('pasta_show', ['id' => $pastaId, '_fragment' => 'processo']);
    }

    #[Route('/{id}/processo/principal', name: 'pasta_definir_processo_principal', methods: ['POST'])]
    public function definirProcessoPrincipal(Pasta $pasta, Request $request): Response
    {
        $pastaId = (int) $pasta->getId();
        if ($redirect = $this->denyResourceAccessUnlessGranted($this->permissionChecker, $this->tenantContext->getCurrentTenant(), AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_EDIT, 'pasta_index', $pasta->getNup() ?? '#' . $pastaId)) {
            return $redirect;
        }

        $isXhr  = $request->isXmlHttpRequest();
        $tenant = $this->tenantContext->getCurrentTenant();

        if (!$this->isCsrfTokenValid('pasta_definir_principal_processo_' . $pastaId, (string) $request->request->get('_token'))) {
            return $this->respostaErroProcesso($isXhr, 'Token de segurança inválido.', $pastaId, Response::HTTP_BAD_REQUEST);
        }

        $processoId = (int) $request->request->get('processo_id', 0);
        $processo   = $processoId > 0 ? $this->processoRepository->findOneByIdDoTenant($processoId, $tenant) : null;
        if (!$processo instanceof Processo) {
            return $this->respostaErroProcesso($isXhr, 'Processo não encontrado.', $pastaId, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->definirProcessoPrincipalUseCase->executar($pasta, $processo);
        } catch (\DomainException $e) {
            return $this->respostaErroProcesso($isXhr, $e->getMessage(), $pastaId, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($isXhr) {
            return $this->respostaProcessosVinculados($pasta);
        }

        $this->addFlash('success', 'Processo principal atualizado.');
        return $this->redirectToRoute('pasta_show', ['id' => $pastaId, '_fragment' => 'processo']);
    }

    #[Route('/{id}/processo/buscar', name: 'pasta_buscar_processos', methods: ['GET'])]
    public function buscarProcessos(Pasta $pasta, Request $request): Response
    {
        $pastaId = (int) $pasta->getId();
        if ($redirect = $this->denyResourceAccessUnlessGranted($this->permissionChecker, $this->tenantContext->getCurrentTenant(), AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_VIEW, 'pasta_index', $pasta->getNup() ?? '#' . $pastaId)) {
            return $redirect;
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        $termo  = trim((string) $request->query->get('q', ''));

        $idsVinculados = array_values(array_filter(array_map(
            static fn (Processo $p): ?int => $p->getId(),
            $pasta->getProcessos(),
        )));

        $processos = $this->processoRepository->buscarPorTermoDoTenant($termo, $tenant, $idsVinculados);

        $results = array_map(fn (Processo $p): array => [
            'id'   => $p->getId(),
            'text' => $this->rotuloProcesso($p),
        ], $processos);

        return $this->json(['results' => $results]);
    }

    private function respostaProcessosVinculados(Pasta $pasta): JsonResponse
    {
        return $this->json([
            'sucesso' => true,
            'html'    => $this->renderView('pasta/_processos_vinculados.html.twig', ['pasta' => $pasta]),
        ]);
    }

    private function respostaErroProcesso(bool $isXhr, string $mensagem, int $pastaId, int $status): Response
    {
        if ($isXhr) {
            return $this->json(['erro' => $mensagem], $status);
        }

        $this->addFlash('danger', $mensagem);

        return $this->redirectToRoute('pasta_show', ['id' => $pastaId, '_fragment' => 'processo']);
    }

    private function rotuloProcesso(Processo $processo): string
    {
        $partes = array_filter([
            $processo->getNumeroProcesso(),
            $processo->getClasseProcessual() ?: $processo->getAssuntoProcessual(),
            $processo->getSiglaTribunal(),
        ]);

        return implode(' · ', $partes);
    }

    /**
     * Lê um campo de texto opcional do POST distinguindo AUSENTE de VAZIO: ausente devolve o valor
     * atual (a tela não ofereceu o campo, então não houve decisão do usuário sobre ele); presente e
     * vazio devolve `null` (o usuário apagou de propósito). Ver o comentário em `editar()`.
     */
    private function textoOuMantemAtual(Request $request, string $chave, ?string $atual): ?string
    {
        if (!$request->request->has($chave)) {
            return $atual;
        }

        $valor = trim((string) $request->request->get($chave, ''));

        return $valor !== '' ? $valor : null;
    }

    #[Route('/{id}/editar', name: 'pasta_edit', methods: ['POST'])]
    public function editar(Pasta $pasta, Request $request): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();

        $pastaId = (int) $pasta->getId();
        if ($redirect = $this->denyResourceAccessUnlessGranted($this->permissionChecker, $this->tenantContext->getCurrentTenant(), AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_EDIT, 'pasta_show', $pasta->getNup() ?? '#' . $pastaId)) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid('edit_pasta_'.$pasta->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        // R3: o nome de ANTES precisa ser capturado aqui, enquanto a entidade ainda tem os valores
        // antigos — depois do UseCase já é tarde. É ele que decide se vale um write no Drive.
        $nomeAntes = ReconciliadorDePasta::nomeEsperado($pasta->getNup(), $pasta->getNomeCliente(), $pasta->getNomeAcao());

        // 🔴 Campo AUSENTE no POST significa "não mexe"; campo PRESENTE e vazio significa "limpa".
        // Sem essa distinção, uma tela que deixa de renderizar o campo APAGA o dado no primeiro
        // save — e foi exatamente o que aconteceu: o formulário escondia `nome_cliente` quando a
        // pasta tinha cliente cadastrado, e 3 pastas de produção perderam o nome sem ninguém tocar
        // nele (a primeira em 19/06, percebida só em 01/09). O template voltou a renderizar o campo,
        // mas a guarda fica aqui também: é a camada que impede QUALQUER tela futura de destruir por
        // omissão.
        $dto = new EditarPastaDTO(
            nup: (string) $request->request->get('nup', ''),
            nomeCliente: $this->textoOuMantemAtual($request, 'nome_cliente', $pasta->getNomeCliente()),
            nomeAcao: $this->textoOuMantemAtual($request, 'nome_acao', $pasta->getNomeAcao()),
            situacao: (string) $request->request->get('situacao', Pasta::SITUACAO_ATIVA),
        );
        try {
            $this->editarPastaUseCase->executar($dto, $pasta);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId()]);
        }

        // 6º ponto de gatilho do sync (R3): renomear no sistema passa a renomear no Drive. Só
        // enfileira se o nome realmente mudou — nome igual não vira write.
        $tenantAtual = $this->tenantContext->getCurrentTenant();
        if ($tenantAtual !== null) {
            $this->syncDispatcher->despacharSeNomeMudou($pasta, $currentUser, $tenantAtual, $nomeAntes);
        }

        $this->addFlash('success', 'Pasta atualizada com sucesso.');
        return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId()]);
    }

    #[Route('/{id}/responsavel', name: 'pasta_atualizar_responsavel', methods: ['PATCH'])]
    public function atualizarResponsavel(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();

        $pastaId = (int) $pasta->getId();
        if ($this->denyResourceAccessUnlessGranted($this->permissionChecker, $this->tenantContext->getCurrentTenant(), AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_EDIT, 'pasta_index', $pasta->getNup() ?? '#' . $pastaId)) {
            return $this->json(['erro' => 'Sem permissão para editar esta pasta.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('pasta_responsavel_' . $pastaId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_BAD_REQUEST);
        }

        $responsavelId = $request->request->get('responsavel_id');

        if ($responsavelId === '' || $responsavelId === null) {
            $pasta->setResponsavel(null);
        } else {
            $responsavel = $this->userRepository->find((int) $responsavelId);
            $tenant = $this->tenantContext->getCurrentTenant() ?? throw new \LogicException('Tenant ausente.');
            if ($responsavel === null || !$this->userTenantRepo->existeVinculoAtivo($responsavel, $tenant)) {
                return $this->json(['erro' => 'Usuário não encontrado.'], Response::HTTP_NOT_FOUND);
            }
            $pasta->setResponsavel($responsavel);
        }

        $this->em->flush();

        $responsavel = $pasta->getResponsavel();

        return $this->json([
            'sucesso'          => true,
            'responsavelId'    => $responsavel?->getId(),
            'responsavelNome'  => $responsavel?->getFullName() ?? '—',
        ]);
    }

    #[Route('/{id}/alternar-situacao', name: 'pasta_alternar_situacao', methods: ['POST'])]
    public function alternarSituacao(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();

        $pastaId = (int) $pasta->getId();
        if ($redirect = $this->denyResourceAccessUnlessGranted($this->permissionChecker, $this->tenantContext->getCurrentTenant(), AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_EDIT, 'pasta_index', $pasta->getNup() ?? '#' . $pastaId)) {
            return $this->json(['erro' => 'Sem permissão para editar esta pasta.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('pasta_alternar_situacao_' . $pastaId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_BAD_REQUEST);
        }

        $novaSituacao = $pasta->getSituacao() === Pasta::SITUACAO_ATIVA
            ? Pasta::SITUACAO_ARQUIVADA
            : Pasta::SITUACAO_ATIVA;

        $pasta->setSituacao($novaSituacao);
        $this->em->flush();

        return $this->json([
            'sucesso'      => true,
            'novaSituacao' => $novaSituacao,
        ]);
    }

    #[Route('/{id}/prioridade', name: 'pasta_alterar_prioridade', methods: ['POST'])]
    public function alterarPrioridade(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();

        $pastaId = (int) $pasta->getId();
        if ($redirect = $this->denyResourceAccessUnlessGranted($this->permissionChecker, $this->tenantContext->getCurrentTenant(), AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_EDIT, 'pasta_index', $pasta->getNup() ?? '#' . $pastaId)) {
            return $this->json(['erro' => 'Sem permissão para editar esta pasta.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('pasta_prioridade_' . $pastaId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $prioridade = PrioridadePasta::from((string) $request->request->get('prioridade', ''));
        } catch (\ValueError) {
            return $this->json(['erro' => 'Prioridade inválida.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->alterarPrioridadeUseCase->executar($pasta, $prioridade);

        return $this->json([
            'badgeClass' => $pasta->getPrioridadeBadgeClass(),
            'label'      => $pasta->getPrioridadeLabel(),
        ]);
    }

    #[Route('/{id}/deletar', name: 'pasta_delete', methods: ['POST'])]
    public function delete(Pasta $pasta, Request $request): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();

        $pastaId = (int) $pasta->getId();
        if ($redirect = $this->denyResourceAccessUnlessGranted($this->permissionChecker, $this->tenantContext->getCurrentTenant(), AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_DELETE, 'pasta_index', $pasta->getNup() ?? '#' . $pastaId)) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid('delete_pasta_'.$pasta->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        // Confirmação digitada (desenho 1.2.3): a pessoa escreve o número da pasta. Conferida AQUI
        // também, e não só no prompt do navegador — senão a confirmação seria cosmética e qualquer
        // POST com o token passaria. A comparação segue a normalização do próprio número
        // (`Pasta::setNup` grava aparado e em maiúsculas), para "1240a" valer como "1240A".
        $numeroDigitado = mb_strtoupper(trim((string) $request->request->get('confirmar_nup', '')));
        if ($numeroDigitado === '' || $numeroDigitado !== (string) $pasta->getNup()) {
            $this->addFlash('danger', 'Número não confere. A pasta não foi excluída.');

            return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId()]);
        }

        $resultado = $this->excluirPastaUseCase->executar($pasta, $currentUser, $this->tenantContext->getCurrentTenant());

        // Os dois desfechos precisam de mensagens diferentes: a lápide continua existindo e pode
        // ser aberta (e restaurada), a removida não. Dizer "removida" nos dois casos faria a
        // pessoa procurar na lixeira uma pasta que está bem ali na lista, riscada.
        if ($resultado === ResultadoExclusaoPasta::Lapide) {
            $this->addFlash(
                'success',
                sprintf(
                    'Pasta %s excluída. Ela continua na lista, riscada, porque o número dela não pode '
                    . 'ser reaproveitado — já existem pastas posteriores.',
                    $pasta->getNup(),
                ),
            );

            return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId()]);
        }

        $this->addFlash('success', 'Pasta removida com sucesso.');

        return $this->redirectToRoute('expediente_index');
    }

    #[Route('/{id}/restaurar', name: 'pasta_restaurar', methods: ['POST'])]
    public function restaurar(Pasta $pasta, Request $request): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();

        $pastaId = (int) $pasta->getId();
        // Mesma permissão do excluir: quem pode pôr a lápide pode tirar (decisão do dono).
        if ($redirect = $this->denyResourceAccessUnlessGranted($this->permissionChecker, $this->tenantContext->getCurrentTenant(), AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_DELETE, 'pasta_index', $pasta->getNup() ?? '#' . $pastaId)) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid('restaurar_pasta_'.$pasta->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        try {
            $this->restaurarPastaUseCase->executar($pasta, $this->tenantContext->getCurrentTenant());
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('warning', $e->getMessage());

            return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId()]);
        }

        $this->addFlash('success', sprintf('Pasta %s restaurada.', $pasta->getNup()));

        return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId()]);
    }

    // -------------------------------------------------------------------------
    // Documentos
    // -------------------------------------------------------------------------

    /** @var array<string, string> */
    /**
     * Teto de publicações carregadas na aba Push Processual. A maior pasta de produção tem 6; o
     * teto existe para que uma pasta com processo antigo e muito movimentado não engorde a
     * `pasta_show`, que já é a página mais pesada do sistema. Ao bater no teto a tela avisa, em
     * vez de deixar o usuário achar que aquilo é tudo.
     */
    private const PUSH_LIMITE = 100;

    private const DOCUMENT_TYPES = [
        PastaDocumento::CATEGORIA_PECA                  => 'Peça',
        PastaDocumento::CATEGORIA_PROCURACAO            => 'Procuração',
        PastaDocumento::CATEGORIA_IDENTIFICACAO         => 'Identificação',
        PastaDocumento::CATEGORIA_COMPROVANTE_RESIDENCIA => 'Comprovante de residência',
        PastaDocumento::CATEGORIA_GRATUIDADE_JUSTICA    => 'Gratuidade de justiça',
        PastaDocumento::CATEGORIA_DEMAIS                => 'Demais documentos',
    ];

    private const MIME_LIMITS_CONTRATO = [
        'application/pdf'                                                                    => 10 * 1024 * 1024,
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'           => 10 * 1024 * 1024,
        'application/msword'                                                                 => 10 * 1024 * 1024,
        'image/jpeg'                                                                         => 10 * 1024 * 1024,
        'image/png'                                                                          => 10 * 1024 * 1024,
    ];

    private const MIME_LIMITS = [
        // Imagens
        'image/png'                          => 3 * 1024 * 1024,
        'image/jpeg'                         => 3 * 1024 * 1024,
        // Documentos
        'application/pdf'                    => 10 * 1024 * 1024,
        // Documentos Word
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 10 * 1024 * 1024, // .docx
        'application/msword'                                                      => 10 * 1024 * 1024, // .doc
        // KML — mimetypes alternativos que diferentes SOs podem detectar
        'application/vnd.google-earth.kml+xml' => 5 * 1024 * 1024,
        'application/xml'                    => 5 * 1024 * 1024,
        'text/xml'                           => 5 * 1024 * 1024,
        // Planilhas
        'application/vnd.ms-excel'                                          => 10 * 1024 * 1024, // .xls
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 10 * 1024 * 1024, // .xlsx
        // Áudio
        'audio/x-opus+ogg'                   => 10 * 1024 * 1024,
        'audio/vorbis'                        => 10 * 1024 * 1024,
        'audio/opus'                          => 10 * 1024 * 1024,
        'audio/mpeg'                          => 10 * 1024 * 1024,
        'audio/ogg'                           => 10 * 1024 * 1024,
        'audio/mp3'                           => 10 * 1024 * 1024,
        'audio/wav'                           => 50 * 1024 * 1024,
        'audio/x-wav'                         => 50 * 1024 * 1024,
        'audio/mp4'                           => 10 * 1024 * 1024,
        // Vídeo
        'video/x-ms-wmv'                      => 50 * 1024 * 1024,
        'video/mpeg'                          => 50 * 1024 * 1024,
        'video/ogg'                           => 50 * 1024 * 1024,
        'video/quicktime'                     => 50 * 1024 * 1024,
        'video/mp4'                           => 50 * 1024 * 1024,
    ];

    #[Route('/{id}/documento/upload', name: 'pasta_documento_upload', methods: ['POST'])]
    public function uploadDocumento(Pasta $pasta, Request $request): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$this->permissionChecker->canAccessResource($currentUser, $tenant, 'pasta', (int) $pasta->getId(), 'edit')) {
            throw $this->createAccessDeniedException('Você não tem permissão para enviar documentos nesta pasta.');
        }

        if (!$this->isCsrfTokenValid('upload_documento_pasta_'.$pasta->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        /** @var \Symfony\Component\HttpFoundation\File\UploadedFile[] $arquivos */
        $arquivos = $request->files->get('arquivos', []);

        if (empty($arquivos)) {
            $this->addFlash('error', 'Nenhum arquivo enviado.');

            return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId()]);
        }

        $categorias  = $request->request->all('categorias');
        $descricoes  = $request->request->all('descricoes');
        $numeros     = $request->request->all('numeros');
        $reduzirTamanho = $request->request->getBoolean('reduzir_tamanho');

        $secaoIdRaw = $request->request->get('secao_id');
        $secaoId    = null;
        if ($secaoIdRaw !== null && $secaoIdRaw !== '') {
            $secaoEncontrada = $this->em->find(PastaSecao::class, (int) $secaoIdRaw);
            if ($secaoEncontrada !== null && $secaoEncontrada->getPasta()?->getId() === $pasta->getId()) {
                $secaoId = $secaoEncontrada;
            }
        }

        $erros                = [];
        $salvos               = 0;
        $bytesEconomizados    = 0;
        $assinadosComprimidos = 0;

        foreach ($arquivos as $i => $file) {
            if ($file === null) {
                continue;
            }

            $mimeType = $file->getMimeType() ?? '';

            if (!array_key_exists($mimeType, self::MIME_LIMITS)) {
                $erros[] = sprintf('"%s": tipo não permitido (%s).', $file->getClientOriginalName(), $mimeType);
                continue;
            }

            $tamanho = $file->getSize();
            $limite  = self::MIME_LIMITS[$mimeType];

            if ($tamanho > $limite) {
                $erros[] = sprintf(
                    '"%s": excede o limite de %d MB.',
                    $file->getClientOriginalName(),
                    intdiv($limite, 1024 * 1024)
                );
                continue;
            }

            $categoriaRaw = isset($categorias[$i]) ? strtoupper(trim((string) $categorias[$i])) : PastaDocumento::CATEGORIA_DEMAIS;
            $categoria    = array_key_exists($categoriaRaw, self::DOCUMENT_TYPES) ? $categoriaRaw : PastaDocumento::CATEGORIA_DEMAIS;
            $descricao    = isset($descricoes[$i]) ? trim((string) $descricoes[$i]) : '';
            $numero       = isset($numeros[$i]) ? trim((string) $numeros[$i]) : '';

            // O escopo sai do próprio documento (R1), só persistido depois da gravação. A
            // compressão é pela CHAVE (E2.6B), sobre cópia gravável fora do volume.
            $doc = new PastaDocumento();
            $doc->setTenant($tenant);

            $upload = FonteDeUploadHttp::de($file);

            // Hash do upload ANTES de mover (depois do gravarEm o caminho não existe mais). Sem
            // compressão, são estes os bytes que o storage guarda (INV-7).
            $sha256 = Sha256DeArquivo::deArquivoLocal($file->getPathname());

            $armazenado = $upload->gravarEm($this->armazenamento, ChavesDePasta::novoDocumento($doc, $upload->extensao));
            $nomeUnico  = $armazenado->chave->nome;

            // D30: quem responde o tamanho é o storage (ver ClienteController).
            $tamanhoFinal = $armazenado->tamanhoBytes;
            if ($reduzirTamanho) {
                $compressao   = $this->compressao->comprimir($armazenado->chave, $mimeType);
                $tamanhoFinal = $compressao->tamanhoFinal;
                $bytesEconomizados += $compressao->tamanhoOriginal - $compressao->tamanhoFinal;
                if ($compressao->comprimido && $compressao->eraAssinado) {
                    ++$assinadosComprimidos;
                }
                if ($compressao->comprimido) {
                    // A chave foi regravada com outro binário: o hash é do que FICOU no storage.
                    // Opcional: se a leitura falhar fica null e o upload segue.
                    $sha256 = Sha256DeArquivo::deChaveOuNulo($this->armazenamento, $armazenado->chave, $this->logger);
                }
            }

            $doc->setPasta($pasta);
            $doc->setTitulo($file->getClientOriginalName());
            $doc->setCategoria($categoria);
            $doc->setDescricao($descricao !== '' ? $descricao : null);
            $doc->setNumero($numero !== '' ? $numero : null);
            $doc->setCaminhoArquivo($nomeUnico);
            $doc->setNomeOriginal($file->getClientOriginalName());
            $doc->setMimeType($mimeType);
            $doc->setTamanhoBytes($tamanhoFinal);
            $doc->setSha256($sha256);

            if ($secaoId !== null) {
                $doc->setSecao($secaoId);
            }

            $this->em->persist($doc);
            ++$salvos;
        }

        $this->em->flush();

        if ($salvos > 0 && $tenant !== null) {
            $this->syncDispatcher->despachar($pasta, $currentUser, $tenant);
        }

        if ($erros) {
            foreach ($erros as $erro) {
                $this->addFlash('error', $erro);
            }
        }

        if ($salvos > 0) {
            $mensagem = sprintf(
                '%d documento%s enviado%s com sucesso.',
                $salvos,
                $salvos > 1 ? 's' : '',
                $salvos > 1 ? 's' : ''
            );

            if ($reduzirTamanho && $bytesEconomizados > 0) {
                $mensagem .= sprintf(' Economia de %s.', $this->formatarBytes($bytesEconomizados));
            }

            $this->addFlash('success', $mensagem);

            if ($reduzirTamanho && $bytesEconomizados <= 0) {
                $this->addFlash('info', 'Não foi possível reduzir os arquivos; mantidos no tamanho original.');
            }
        }

        if ($assinadosComprimidos > 0) {
            $this->addFlash('warning', sprintf(
                '%d PDF assinado%s foi comprimido — a assinatura pode ter sido invalidada.',
                $assinadosComprimidos,
                $assinadosComprimidos > 1 ? 's' : ''
            ));
        }

        return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId()]);
    }

    private function formatarBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $unidades = ['B', 'KB', 'MB', 'GB'];
        $i        = (int) floor(log($bytes) / log(1024));
        $i        = min($i, count($unidades) - 1);

        return sprintf('%.1f %s', $bytes / (1024 ** $i), $unidades[$i]);
    }

    #[Route('/documento/{id}/visualizar', name: 'pasta_documento_view', methods: ['GET'])]
    public function viewDocumento(PastaDocumento $doc): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $pastaId = (int) $doc->getPasta()?->getId();
        if (!$this->permissionChecker->canAccessResource($currentUser, $this->tenantContext->getCurrentTenant(), 'pasta', $pastaId, 'view')) {
            throw $this->createAccessDeniedException('Você não tem permissão para acessar documentos desta pasta.');
        }

        $chave = ChavesDePasta::documento($doc);

        if (!$this->armazenamento->existe($chave)) {
            throw $this->createNotFoundException('Arquivo não encontrado no servidor.');
        }

        return $this->entrega->resposta($chave, $doc->getNomeOriginal(), inline: true);
    }

    #[Route('/documento/{id}/download', name: 'pasta_documento_download', methods: ['GET'])]
    public function downloadDocumento(PastaDocumento $doc): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $pastaId = (int) $doc->getPasta()?->getId();
        if (!$this->permissionChecker->canAccessResource($currentUser, $this->tenantContext->getCurrentTenant(), 'pasta', $pastaId, 'view')) {
            throw $this->createAccessDeniedException('Você não tem permissão para acessar documentos desta pasta.');
        }

        $chave = ChavesDePasta::documento($doc);

        if (!$this->armazenamento->existe($chave)) {
            $this->addFlash('error', 'Arquivo não encontrado no servidor.');

            return $this->redirectToRoute('pasta_show', ['id' => $doc->getPasta()?->getId()]);
        }

        return $this->entrega->resposta($chave, $doc->getNomeOriginal(), inline: false);
    }

    // `pasta_documento_edit` (POST /pasta/documento/{id}/editar) mora em
    // App\Pasta\Controller\PastaDocumentoController::editar desde a aba Documentos (D3).

    /**
     * Desde o L7 (D7) "excluir" é LIXEIRA: o documento recebe a lápide (`excluido_em`/`por`) e
     * some da pasta, mas a linha e o arquivo físico ficam até `app:documentos:purgar-lixeira`.
     * Nada sai do disco aqui. Mesma rota, token, flash e redirect de sempre; a marcação é a mesma
     * do excluir-lote (`ExcluirItensDaPastaUseCase`), uma regra num lugar só. Documento já na
     * lixeira não chega aqui: o `LixeiraFilter` o esconde do resolver (404).
     */
    #[Route('/documento/{id}/deletar', name: 'pasta_documento_delete', methods: ['POST'])]
    public function deleteDocumento(PastaDocumento $doc, Request $request, ExcluirItensDaPastaUseCase $excluirItens): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $tenant      = $this->tenantContext->getCurrentTenant();
        $pasta       = $doc->getPasta();
        if ($pasta !== null && !$this->permissionChecker->canAccessResource($currentUser, $tenant, 'pasta', (int) $pasta->getId(), 'edit')) {
            throw $this->createAccessDeniedException('Você não tem permissão para remover documentos desta pasta.');
        }

        $pastaId = $pasta?->getId();

        if (!$this->isCsrfTokenValid('delete_documento_'.$doc->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        if ($pasta === null || $tenant === null) {
            throw $this->createNotFoundException('Documento não encontrado.');
        }

        $excluirItens->executar($pasta, [$doc], [], $currentUser, $tenant);

        $this->addFlash('success', 'Documento removido com sucesso.');

        return $this->redirectToRoute('pasta_show', ['id' => $pastaId, '_fragment' => 'documentos']);
    }

    // ── Financeiro: Situação do Contrato ─────────────────────────────────────

    #[Route('/{id}/situacao-contrato', name: 'pasta_alternar_situacao_contrato', methods: ['POST'])]
    public function alternarSituacaoContrato(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $pastaId = (int) $pasta->getId();

        if (!$this->permissionChecker->canAccessResource($currentUser, $this->tenantContext->getCurrentTenant(), 'pasta', $pastaId, 'edit')) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('pasta_situacao_contrato_' . $pastaId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        $situacao = strtoupper(trim((string) $request->request->get('situacao', '')));

        try {
            $this->alterarSituacaoContratoUseCase->executar($pasta, $situacao);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['situacao' => $pasta->getSituacaoContrato()]);
    }

    // ── Financeiro: Pró-Bono ─────────────────────────────────────────────────

    #[Route('/{id}/pro-bono', name: 'pasta_atualizar_pro_bono', methods: ['POST'])]
    public function atualizarProBono(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $pastaId = (int) $pasta->getId();

        if (!$this->permissionChecker->canAccessResource($currentUser, $this->tenantContext->getCurrentTenant(), 'pasta', $pastaId, 'edit')) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('pasta_pro_bono_' . $pastaId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        $proBono = filter_var($request->request->get('pro_bono'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($proBono === null) {
            return $this->json(['erro' => 'Valor inválido para pró-bono.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $pasta->setProBono($proBono);
        $this->em->flush();

        return $this->json(['proBono' => $pasta->isProBono()]);
    }

    // ── Financeiro: Valor da Causa ───────────────────────────────────────────

    #[Route('/{id}/valor-causa', name: 'pasta_atualizar_valor_causa', methods: ['POST'])]
    public function atualizarValorCausa(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $pastaId     = (int) $pasta->getId();
        $tenant      = $this->tenantContext->getCurrentTenant();

        if (!$this->permissionChecker->canAccessResource($currentUser, $tenant, 'pasta', $pastaId, 'edit')) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('pasta_valor_causa_' . $pastaId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        // Campo em BRANCO limpa o valor — foi o combinado. Campo AUSENTE não: uma
        // requisição malformada não pode apagar dado gravado e ainda responder 200.
        if (!$request->request->has('valor_causa')) {
            return $this->json(['erro' => 'Requisição incompleta.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->atualizarValorCausaUseCase->executar($pasta, (string) $request->request->get('valor_causa'));
        } catch (\InvalidArgumentException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // A média por CPF é do cliente, não da pasta: mudar o valor aqui muda o
        // número exibido, então ele volta recalculado para a tela não ficar defasada.
        $primeiroCliente = $pasta->getClientePrincipal();
        $mediaCpf        = $primeiroCliente !== null && $tenant !== null
            ? $this->pastaRepository->mediaValorCausaPorCliente($primeiroCliente, $tenant)
            : null;

        return $this->json([
            'valor'          => $pasta->getValorCausa(),
            'valorFormatado' => PastaFinanceiroOutput::formatarReais($pasta->getValorCausa()),
            'mediaFormatada' => PastaFinanceiroOutput::formatarReais($mediaCpf),
        ]);
    }

    // ── Financeiro: Upload de documento de contrato ──────────────────────────

    #[Route('/{id}/financeiro/upload', name: 'pasta_financeiro_upload', methods: ['POST'])]
    public function financeiroUpload(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $pastaId = (int) $pasta->getId();
        $tenant = $this->tenantContext->getCurrentTenant();

        if (!$this->permissionChecker->canAccessResource($currentUser, $tenant, 'pasta', $pastaId, 'edit')) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('pasta_financeiro_upload_' . $pastaId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        $file = $request->files->get('arquivo');
        if ($file === null) {
            return $this->json(['erro' => 'Nenhum arquivo enviado.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$file->isValid()) {
            return $this->json(['erro' => 'Arquivo inválido ou excede o limite de upload do servidor (máx. 15 MB).'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $mimeType = $file->getMimeType() ?? '';
        if (!array_key_exists($mimeType, self::MIME_LIMITS_CONTRATO)) {
            return $this->json(['erro' => 'Tipo de arquivo não permitido. Use PDF, DOCX ou imagem JPEG/PNG.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $tamanho = $file->getSize();
        if ($tamanho === false || $tamanho > self::MIME_LIMITS_CONTRATO[$mimeType]) {
            return $this->json(['erro' => 'Arquivo excede o limite de 10 MB.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $reduzirTamanho = $request->request->getBoolean('reduzir_tamanho');

        // O escopo sai do próprio documento (R1), só persistido depois da gravação.
        $doc = new PastaDocumento();
        $doc->setTenant($tenant);

        $upload = FonteDeUploadHttp::de($file);

        // Hash do upload ANTES de mover; recalculado pela chave só se a compressão trocar o binário.
        $sha256 = Sha256DeArquivo::deArquivoLocal($file->getPathname());

        $armazenado = $upload->gravarEm($this->armazenamento, ChavesDePasta::novoDocumento($doc, $upload->extensao));
        $nomeUnico  = $armazenado->chave->nome;

        $tamanhoFinal = $armazenado->tamanhoBytes; // D30: quem responde o tamanho é o storage
        $compressao   = null;
        if ($reduzirTamanho) {
            $compressao   = $this->compressao->comprimir($armazenado->chave, $mimeType);
            $tamanhoFinal = $compressao->tamanhoFinal;
            if ($compressao->comprimido) {
                // Opcional: se a leitura falhar fica null e o upload segue.
                $sha256 = Sha256DeArquivo::deChaveOuNulo($this->armazenamento, $armazenado->chave, $this->logger);
            }
        }

        $doc->setPasta($pasta);
        $doc->setTitulo($file->getClientOriginalName());
        $doc->setCategoria(PastaDocumento::CATEGORIA_CONTRATO);
        $doc->setCaminhoArquivo($nomeUnico);
        $doc->setNomeOriginal($file->getClientOriginalName());
        $doc->setMimeType($mimeType);
        $doc->setTamanhoBytes($tamanhoFinal);
        $doc->setSha256($sha256);

        $this->em->persist($doc);
        $this->em->flush();

        if ($tenant !== null) {
            $this->syncDispatcher->despachar($pasta, $currentUser, $tenant);
        }

        return $this->json([
            'id'           => $doc->getId(),
            'titulo'       => $doc->getTitulo(),
            'mimeType'     => $mimeType,
            // Linha de apoio da lista, montada aqui pelo mesmo critério do Twig
            // (tamanho · data). Sem ela a linha recém-enviada nasceria com a
            // segunda linha vazia até a próxima recarga.
            'meta'         => $this->arquivoIcone->formatarBytes($tamanhoFinal) . ' · ' . $doc->getCarregadoEm()->format('d/m/Y'),
            'urlVisualizar' => $this->generateUrl('pasta_financeiro_documento_view', ['id' => $pastaId, 'docId' => $doc->getId()]),
            'urlDownload'   => $this->generateUrl('pasta_financeiro_documento_download', ['id' => $pastaId, 'docId' => $doc->getId()]),
            'csrfRenomear' => $this->csrfTokenManager->getToken('pasta_financeiro_renomear_' . $doc->getId())->getValue(),
            'csrfExcluir'  => $this->csrfTokenManager->getToken('pasta_financeiro_excluir_' . $doc->getId())->getValue(),
            'compressao'   => $compressao !== null ? [
                'comprimido'      => $compressao->comprimido,
                'tamanhoOriginal' => $compressao->tamanhoOriginal,
                'tamanhoFinal'    => $compressao->tamanhoFinal,
                'eraAssinado'     => $compressao->eraAssinado,
            ] : null,
        ], Response::HTTP_CREATED);
    }

    // ── Financeiro: Visualizar documento de contrato ─────────────────────────

    #[Route('/{id}/financeiro/documento/{docId}/visualizar', name: 'pasta_financeiro_documento_view', methods: ['GET'])]
    public function financeiroViewDocumento(Pasta $pasta, int $docId): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $pastaId = (int) $pasta->getId();

        if (!$this->permissionChecker->canAccessResource($currentUser, $this->tenantContext->getCurrentTenant(), 'pasta', $pastaId, 'view')) {
            throw $this->createAccessDeniedException('Sem permissão.');
        }

        $doc = $this->pastaDocumentoRepository->find($docId);
        if ($doc === null || $doc->getPasta()?->getId() !== $pastaId || $doc->getCategoria() !== PastaDocumento::CATEGORIA_CONTRATO) {
            throw $this->createNotFoundException('Documento não encontrado.');
        }

        $chave = ChavesDePasta::documento($doc);

        if (!$this->armazenamento->existe($chave)) {
            throw $this->createNotFoundException('Arquivo não encontrado no servidor.');
        }

        return $this->entrega->resposta($chave, $doc->getNomeOriginal(), inline: true);
    }

    // ── Financeiro: Download documento de contrato ────────────────────────────

    #[Route('/{id}/financeiro/documento/{docId}/download', name: 'pasta_financeiro_documento_download', methods: ['GET'])]
    public function financeiroDownloadDocumento(Pasta $pasta, int $docId): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $pastaId = (int) $pasta->getId();

        if (!$this->permissionChecker->canAccessResource($currentUser, $this->tenantContext->getCurrentTenant(), 'pasta', $pastaId, 'view')) {
            throw $this->createAccessDeniedException('Sem permissão.');
        }

        $doc = $this->pastaDocumentoRepository->find($docId);
        if ($doc === null || $doc->getPasta()?->getId() !== $pastaId || $doc->getCategoria() !== PastaDocumento::CATEGORIA_CONTRATO) {
            throw $this->createNotFoundException('Documento não encontrado.');
        }

        $chave = ChavesDePasta::documento($doc);

        if (!$this->armazenamento->existe($chave)) {
            throw $this->createNotFoundException('Arquivo não encontrado no servidor.');
        }

        return $this->entrega->resposta($chave, $doc->getNomeOriginal(), inline: false);
    }

    // ── Financeiro: Renomear documento de contrato ───────────────────────────

    #[Route('/{id}/financeiro/documento/{docId}/renomear', name: 'pasta_financeiro_documento_renomear', methods: ['POST'])]
    public function financeiroRenomearDocumento(Pasta $pasta, int $docId, Request $request): JsonResponse
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $pastaId = (int) $pasta->getId();

        if (!$this->permissionChecker->canAccessResource($currentUser, $this->tenantContext->getCurrentTenant(), 'pasta', $pastaId, 'edit')) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        $doc = $this->pastaDocumentoRepository->find($docId);
        if ($doc === null || $doc->getPasta()?->getId() !== $pastaId || $doc->getCategoria() !== PastaDocumento::CATEGORIA_CONTRATO) {
            return $this->json(['erro' => 'Documento não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isCsrfTokenValid('pasta_financeiro_renomear_' . $docId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        $novoTitulo = trim((string) $request->request->get('titulo', ''));
        if ($novoTitulo === '') {
            return $this->json(['erro' => 'O nome não pode ser vazio.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (mb_strlen($novoTitulo) > 255) {
            return $this->json(['erro' => 'Nome muito longo (máx. 255 caracteres).'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $doc->setTitulo($novoTitulo);
        $this->em->flush();

        return $this->json(['titulo' => $doc->getTitulo()]);
    }

    // ── Financeiro: Excluir documento de contrato ────────────────────────────

    #[Route('/{id}/financeiro/documento/{docId}/excluir', name: 'pasta_financeiro_documento_excluir', methods: ['POST'])]
    /**
     * Desde o L7 (D7) o contrato excluído pela aba Financeiro também vai para a LIXEIRA (lápide
     * pelo `ExcluirItensDaPastaUseCase`, como a aba Documentos): linha e arquivo ficam até a purga.
     * Mesma rota, token e resposta (`sucesso: true`, mais `lixeira: true`). Documento já na lixeira
     * não é achado pelo `find()` (LixeiraFilter) → 404, como qualquer id inexistente.
     */
    public function financeiroExcluirDocumento(Pasta $pasta, int $docId, Request $request, ExcluirItensDaPastaUseCase $excluirItens): JsonResponse
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $tenant      = $this->tenantContext->getCurrentTenant();
        $pastaId     = (int) $pasta->getId();

        if ($tenant === null || !$this->permissionChecker->canAccessResource($currentUser, $tenant, 'pasta', $pastaId, 'edit')) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        $doc = $this->pastaDocumentoRepository->find($docId);
        if ($doc === null || $doc->getPasta()?->getId() !== $pastaId || $doc->getCategoria() !== PastaDocumento::CATEGORIA_CONTRATO) {
            return $this->json(['erro' => 'Documento não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isCsrfTokenValid('pasta_financeiro_excluir_' . $docId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        $excluirItens->executar($pasta, [$doc], [], $currentUser, $tenant);

        return $this->json(['sucesso' => true, 'lixeira' => true]);
    }

    // ── Financeiro: Enviar observação ─────────────────────────────────────────

    #[Route('/{id}/financeiro/observacao', name: 'pasta_financeiro_observacao_enviar', methods: ['POST'])]
    public function financeiroEnviarObservacao(Pasta $pasta, Request $request, SanitizadorTextoRico $sanitizador): JsonResponse
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $pastaId = (int) $pasta->getId();
        $tenant  = $this->tenantContext->getCurrentTenant();

        if (!$this->permissionChecker->canAccessResource($currentUser, $tenant, 'pasta', $pastaId, 'edit')) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('pasta_financeiro_obs_' . $pastaId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        $conteudo = trim((string) $request->request->get('conteudo', ''));
        if ($conteudo === '') {
            return $this->json(['erro' => 'A observação não pode ser vazia.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $obs = $this->enviarObservacaoFinanceiraUseCase->executar($pasta, $currentUser, $conteudo, $tenant);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'id'         => $obs->getId(),
            'conteudo'   => $obs->getConteudo(),
            'conteudoHtml' => $sanitizador->paraExibicao($obs->getConteudo()),
            'autorNome'  => $currentUser->getFullName(),
            'criadaEm'   => $obs->getCriadaEm()->format('d/m/Y H:i'),
            'criadaEmTs' => $obs->getCriadaEm()->format(\DateTimeInterface::ATOM),
            'csrfEditar'  => $this->csrfTokenManager->getToken('pasta_financeiro_obs_editar_' . $obs->getId())->getValue(),
            'csrfExcluir' => $this->csrfTokenManager->getToken('pasta_financeiro_obs_excluir_' . $obs->getId())->getValue(),
        ], Response::HTTP_CREATED);
    }

    // ── Financeiro: Editar observação ─────────────────────────────────────────

    #[Route('/{id}/financeiro/observacao/{obsId}/editar', name: 'pasta_financeiro_observacao_editar', methods: ['POST'])]
    public function financeiroEditarObservacao(Pasta $pasta, int $obsId, Request $request, SanitizadorTextoRico $sanitizador): JsonResponse
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $pastaId = (int) $pasta->getId();
        $tenant  = $this->tenantContext->getCurrentTenant();

        if (!$this->permissionChecker->canAccessResource($currentUser, $tenant, 'pasta', $pastaId, 'edit')) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        $obs = $this->em->find(PastaObservacaoFinanceira::class, $obsId);
        if ($obs === null || $obs->getPasta()?->getId() !== $pastaId) {
            return $this->json(['erro' => 'Observação não encontrada.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isCsrfTokenValid('pasta_financeiro_obs_editar_' . $obsId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        $conteudo = trim((string) $request->request->get('conteudo', ''));

        try {
            $this->editarObservacaoFinanceiraUseCase->executar($obs, $currentUser, $tenant, $conteudo);
        } catch (ObservacaoFinanceiraNaoEditavelException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'conteudo'     => $obs->getConteudo(),
            'conteudoHtml' => $sanitizador->paraExibicao($obs->getConteudo()),
            'editadaEm'    => $obs->getEditadaEm()?->format('d/m/Y H:i'),
        ]);
    }

    // ── Financeiro: Excluir observação ────────────────────────────────────────

    #[Route('/{id}/financeiro/observacao/{obsId}/excluir', name: 'pasta_financeiro_observacao_excluir', methods: ['POST'])]
    public function financeiroExcluirObservacao(Pasta $pasta, int $obsId, Request $request): JsonResponse
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $pastaId = (int) $pasta->getId();
        $tenant  = $this->tenantContext->getCurrentTenant();

        if (!$this->permissionChecker->canAccessResource($currentUser, $tenant, 'pasta', $pastaId, 'edit')) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        $obs = $this->em->find(PastaObservacaoFinanceira::class, $obsId);
        if ($obs === null || $obs->getPasta()?->getId() !== $pastaId) {
            return $this->json(['erro' => 'Observação não encontrada.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isCsrfTokenValid('pasta_financeiro_obs_excluir_' . $obsId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $this->excluirObservacaoFinanceiraUseCase->executar($obs, $currentUser, $tenant);
        } catch (ObservacaoFinanceiraNaoExcluivelException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        }

        return $this->json(['sucesso' => true]);
    }

    // ── Detalhes: Enviar observação ───────────────────────────────────────────

    #[Route('/{id}/detalhes/observacao', name: 'pasta_detalhes_observacao_enviar', methods: ['POST'])]
    public function detalhesEnviarObservacao(Pasta $pasta, Request $request, SanitizadorTextoRico $sanitizador): JsonResponse
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $pastaId = (int) $pasta->getId();
        $tenant  = $this->tenantContext->getCurrentTenant();

        if (!$this->permissionChecker->canAccessResource($currentUser, $tenant, 'pasta', $pastaId, 'edit')) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('pasta_detalhes_obs_' . $pastaId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        $conteudo = trim((string) $request->request->get('conteudo', ''));
        if ($conteudo === '') {
            return $this->json(['erro' => 'A observação não pode ser vazia.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $obs = $this->enviarObservacaoDetalhesUseCase->executar($pasta, $currentUser, $conteudo, $tenant);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'id'         => $obs->getId(),
            // `conteudo` é o valor CRU (volta para dentro do editor ao editar); `conteudoHtml` é o
            // que a tela exibe — sanitizado aqui para o JS poder inserir como HTML com segurança.
            'conteudo'     => $obs->getConteudo(),
            'conteudoHtml' => $sanitizador->paraExibicao($obs->getConteudo()),
            'autorNome'  => $currentUser->getFullName(),
            'criadaEm'   => $obs->getCriadaEm()->format('d/m/Y H:i'),
            'csrfEditar'  => $this->csrfTokenManager->getToken('pasta_detalhes_obs_editar_' . $obs->getId())->getValue(),
            'csrfExcluir' => $this->csrfTokenManager->getToken('pasta_detalhes_obs_excluir_' . $obs->getId())->getValue(),
        ], Response::HTTP_CREATED);
    }

    // ── Detalhes: Editar observação ───────────────────────────────────────────

    #[Route('/{id}/detalhes/observacao/{obsId}/editar', name: 'pasta_detalhes_observacao_editar', methods: ['POST'])]
    public function detalhesEditarObservacao(Pasta $pasta, int $obsId, Request $request, SanitizadorTextoRico $sanitizador): JsonResponse
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $pastaId = (int) $pasta->getId();
        $tenant  = $this->tenantContext->getCurrentTenant();

        if (!$this->permissionChecker->canAccessResource($currentUser, $tenant, 'pasta', $pastaId, 'edit')) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        $obs = $this->em->find(PastaObservacaoDetalhes::class, $obsId);
        if ($obs === null || $obs->getPasta()?->getId() !== $pastaId) {
            return $this->json(['erro' => 'Observação não encontrada.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isCsrfTokenValid('pasta_detalhes_obs_editar_' . $obsId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        $conteudo = trim((string) $request->request->get('conteudo', ''));

        try {
            $this->editarObservacaoDetalhesUseCase->executar($obs, $currentUser, $tenant, $conteudo);
        } catch (ObservacaoDetalhesNaoEditavelException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'conteudo'     => $obs->getConteudo(),
            'conteudoHtml' => $sanitizador->paraExibicao($obs->getConteudo()),
            'editadaEm'    => $obs->getEditadaEm()?->format('d/m/Y H:i'),
        ]);
    }

    // ── Detalhes: Excluir observação ──────────────────────────────────────────

    #[Route('/{id}/detalhes/observacao/{obsId}/excluir', name: 'pasta_detalhes_observacao_excluir', methods: ['POST'])]
    public function detalhesExcluirObservacao(Pasta $pasta, int $obsId, Request $request): JsonResponse
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $pastaId = (int) $pasta->getId();
        $tenant  = $this->tenantContext->getCurrentTenant();

        if (!$this->permissionChecker->canAccessResource($currentUser, $tenant, 'pasta', $pastaId, 'edit')) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        $obs = $this->em->find(PastaObservacaoDetalhes::class, $obsId);
        if ($obs === null || $obs->getPasta()?->getId() !== $pastaId) {
            return $this->json(['erro' => 'Observação não encontrada.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isCsrfTokenValid('pasta_detalhes_obs_excluir_' . $obsId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $this->excluirObservacaoDetalhesUseCase->executar($obs, $currentUser, $tenant);
        } catch (ObservacaoDetalhesNaoExcluivelException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        }

        return $this->json(['sucesso' => true]);
    }

    // ── Checklist dinâmico ────────────────────────────────────────────────────

    #[Route('/{id}/checklist', name: 'pasta_checklist_adicionar', methods: ['POST'])]
    public function checklistAdicionar(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $pastaId = (int) $pasta->getId();
        $tenant  = $this->tenantContext->getCurrentTenant();

        if (!$this->permissionChecker->canAccessResource($currentUser, $tenant, 'pasta', $pastaId, 'edit')) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('checklist_pasta_' . $pastaId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        $titulo = trim((string) $request->request->get('titulo', ''));

        try {
            $item = $this->adicionarChecklistItemUseCase->executar($pasta, $currentUser, $titulo, $tenant);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'ok'       => true,
            'id'       => $item->getId(),
            'titulo'   => $item->getTitulo(),
            'concluido' => $item->isConcluido(),
            'csrfItem' => $this->csrfTokenManager->getToken('checklist_item_' . $item->getId())->getValue(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}/checklist/{itemId}/toggle', name: 'pasta_checklist_toggle', methods: ['POST'])]
    public function checklistToggle(Pasta $pasta, int $itemId, Request $request): JsonResponse
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $pastaId = (int) $pasta->getId();

        if (!$this->permissionChecker->canAccessResource($currentUser, $this->tenantContext->getCurrentTenant(), 'pasta', $pastaId, 'edit')) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        $item = $this->em->find(PastaChecklistItem::class, $itemId);
        if ($item === null || $item->getPasta()?->getId() !== $pastaId) {
            return $this->json(['erro' => 'Item não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isCsrfTokenValid('checklist_item_' . $itemId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        $this->toggleChecklistItemUseCase->executar($item);

        return $this->json(['ok' => true, 'concluido' => $item->isConcluido()]);
    }

    #[Route('/{id}/checklist/{itemId}/editar', name: 'pasta_checklist_editar', methods: ['POST'])]
    public function checklistEditar(Pasta $pasta, int $itemId, Request $request): JsonResponse
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $pastaId = (int) $pasta->getId();

        if (!$this->permissionChecker->canAccessResource($currentUser, $this->tenantContext->getCurrentTenant(), 'pasta', $pastaId, 'edit')) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        $item = $this->em->find(PastaChecklistItem::class, $itemId);
        if ($item === null || $item->getPasta()?->getId() !== $pastaId) {
            return $this->json(['erro' => 'Item não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isCsrfTokenValid('checklist_item_' . $itemId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        $titulo = trim((string) $request->request->get('titulo', ''));

        try {
            $this->editarChecklistItemUseCase->executar($item, $titulo);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['ok' => true, 'titulo' => $item->getTitulo()]);
    }

    #[Route('/{id}/checklist/{itemId}/excluir', name: 'pasta_checklist_excluir', methods: ['POST'])]
    public function checklistExcluir(Pasta $pasta, int $itemId, Request $request): JsonResponse
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $pastaId = (int) $pasta->getId();

        if (!$this->permissionChecker->canAccessResource($currentUser, $this->tenantContext->getCurrentTenant(), 'pasta', $pastaId, 'edit')) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        $item = $this->em->find(PastaChecklistItem::class, $itemId);
        if ($item === null || $item->getPasta()?->getId() !== $pastaId) {
            return $this->json(['erro' => 'Item não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isCsrfTokenValid('checklist_item_' . $itemId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        $this->excluirChecklistItemUseCase->executar($item);

        return $this->json(['ok' => true]);
    }

    #[Route('/{id}/checklist/reordenar', name: 'pasta_checklist_reordenar', methods: ['POST'])]
    public function checklistReordenar(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $pastaId = (int) $pasta->getId();

        if (!$this->permissionChecker->canAccessResource($currentUser, $this->tenantContext->getCurrentTenant(), 'pasta', $pastaId, 'edit')) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        $data  = json_decode((string) $request->getContent(), true);
        $token = is_array($data) ? (string) ($data['_token'] ?? '') : '';
        $ids   = is_array($data['ids'] ?? null) ? $data['ids'] : [];

        if (!$this->isCsrfTokenValid('checklist_pasta_' . $pastaId, $token)) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            return $this->json(['erro' => 'Usuário sem tenant.'], Response::HTTP_FORBIDDEN);
        }

        $this->reordenarChecklistItensUseCase->executar($pasta, $tenant, $ids);

        return $this->json(['ok' => true]);
    }

    /**
     * @return array<string, PastaDocumento[]>
     */
    private function groupDocumentsByType(Pasta $pasta): array
    {
        $grouped = [];
        foreach (array_keys(self::DOCUMENT_TYPES) as $tipo) {
            $grouped[$tipo] = [];
        }
        foreach ($pasta->getDocumentos() as $doc) {
            if ($doc->getSecao() !== null) {
                continue;
            }
            $cat = $doc->getCategoria();
            if (array_key_exists($cat, $grouped)) {
                $grouped[$cat][] = $doc;
            }
        }
        return $grouped;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function fillProcessoFromData(Processo $processo, array $data, TribunalCnjResolver $tribunalResolver): void
    {
        $numeroNormalizado = preg_replace('/\D+/', '', (string) ($data['numeroProcesso'] ?? ''));
        $processo->setNumeroProcesso($numeroNormalizado ?? '');
        $processo->setOrgaoJulgador((string) ($data['orgaoJulgador'] ?? ''));

        // Sigla derivada do número (padrão CNJ), não mais informada no form. Número
        // legado/não-CNJ: preserva a sigla atual em vez de sobrescrever com vazio.
        try {
            $processo->setSiglaTribunal($tribunalResolver->resolverSigla($numeroNormalizado ?? ''));
        } catch (TribunalNaoIdentificadoException $e) {
            // Mantém a sigla atual do processo.
        }
        $processo->setClasseProcessual((string) ($data['classeProcessual'] ?? ''));
        $processo->setAssuntoProcessual((string) ($data['assuntoProcessual'] ?? ''));
        $processo->setSituacaoProcesso((string) ($data['situacaoProcesso'] ?? 'EM_ANDAMENTO'));
        $processo->setInstancia((string) ($data['instancia'] ?? 'G1'));
        $processo->setDataDistribuicao($this->parseDateOrNull($data['dataDistribuicao'] ?? null));
        $processo->setDataBaixa($this->parseDateOrNull($data['dataBaixa'] ?? null));

        foreach (is_array($data['partes'] ?? null) ? $data['partes'] : [] as $parteData) {
            $nome = trim((string) ($parteData['nome'] ?? ''));
            if ($nome === '') {
                continue;
            }
            $parte = new ParteProcesso();
            $parte->setTipo((string) ($parteData['tipo'] ?? 'PARTE'));
            $parte->setNome($nome);
            $parte->setDocumento(($parteData['documento'] ?? '') !== '' ? (string) $parteData['documento'] : null);
            $parte->setPapel(($parteData['papel'] ?? '') !== '' ? (string) $parteData['papel'] : null);
            $parte->setTenant($processo->getTenant());
            $processo->addParte($parte);
        }

        foreach (is_array($data['movimentacoes'] ?? null) ? $data['movimentacoes'] : [] as $movData) {
            $descricao = trim((string) ($movData['descricao'] ?? ''));
            if ($descricao === '') {
                continue;
            }
            $mov = new MovimentacaoProcesso();
            $mov->setDescricao($descricao);
            $mov->setTipo(($movData['tipo'] ?? '') !== '' ? (string) $movData['tipo'] : null);
            $mov->setOrgao(($movData['orgao'] ?? '') !== '' ? (string) $movData['orgao'] : null);
            $mov->setDataMovimentacao($this->parseDateOrNull($movData['dataMovimentacao'] ?? null));
            $mov->setTenant($processo->getTenant());
            $processo->addMovimentacao($mov);
        }
    }

    private function parseDateOrNull(mixed $value): ?\DateTimeInterface
    {
        $dateValue = is_string($value) ? trim($value) : '';
        if ($dateValue === '') {
            return null;
        }

        return \DateTime::createFromFormat('!Y-m-d', $dateValue) ?: null;
    }

    /**
     * @param list<int|string> $clienteIds
     */
    private function syncClientes(Pasta $pasta, array $clienteIds): void
    {
        foreach ($pasta->getClientes()->toArray() as $clienteExistente) {
            $pasta->removeCliente($clienteExistente);
        }

        foreach ($clienteIds as $clienteId) {
            $cliente = $this->clienteRepository->find((int) $clienteId);
            if ($cliente instanceof Cliente) {
                $pasta->addCliente($cliente);
            }
        }
    }

}
