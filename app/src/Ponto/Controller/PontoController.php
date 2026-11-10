<?php

declare(strict_types=1);

namespace App\Ponto\Controller;

use App\Entity\Auth\User;
use App\Ponto\Armazenamento\ChavesDePonto;
use App\Ponto\Exception\TrocaDeAnexoRecusadaException;
use App\Ponto\Exception\ExclusaoDeJustificativaRecusadaException;
use App\Ponto\Exception\CompetenciaDaFolhaInvalidaException;
use App\Ponto\UseCase\ExcluirJustificativaEmAnaliseUseCase;
use App\Ponto\Service\AnaliseDaPropriaJustificativa;
use App\Ponto\Service\BancoDeHorasPorLotes;
use App\Ponto\UseCase\SubstituirAnexoDoLoteUseCase;
use App\Ponto\Entity\ComprovanteDoPonto;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\JornadaTenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Enum\TipoJustificativa;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Model\MarcacaoDoPonto;
use App\Ponto\Service\HomeOfficeResolver;
use App\Ponto\Service\NomeDoArquivoDaFolha;
use App\Ponto\Service\FuncoesDoPonto;
use App\Ponto\Service\HorarioFuturoDaBatida;
use App\Ponto\Service\EntradaAbertaEmOutroEscritorio;
use App\Ponto\Service\EsquecimentosRegistrados;
use App\Ponto\Service\SedesDaCercaDoPonto;
use App\Ponto\Service\AbaDosComprovantes;
use App\Ponto\Service\CercaDoPonto;
use App\Ponto\Service\PosicaoNaCerca;
use App\Ponto\Service\TrabalhoExternoDaBatida;
use App\Ponto\UseCase\RegistrarBatidaDaPessoaUseCase;
use App\Ponto\UseCase\ObterEstadoDoPontoDoDiaUseCase;
use App\Ponto\UseCase\ListarPendenciasDeRegistroUseCase;
use App\Ponto\Service\InicioContagemResolver;
use App\Ponto\Service\JornadaResolver;
use App\Ponto\Form\JustificativaPontoType;
use App\Ponto\Repository\FeriadoRepository;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Repository\PontoAjusteRepository;
use App\Ponto\Exception\JustificativaJaAnalisadaException;
use App\Ponto\UseCase\ConfirmarEdicaoDeJustificativaUseCase;
use App\Ponto\Service\CalculadoraJornada;
use App\Ponto\Repository\JornadaColaboradorRepository;
use App\Ponto\Service\ChaveDoPontoEletronico;
use App\Ponto\Service\TurnoAbertoNoEscritorio;
use App\Ponto\Service\AlertasAntesDaBatida;
use App\Ponto\Service\IntervaloDaBatida;
use App\Ponto\Service\BatidaJaRegistrada;
use App\Ponto\Service\PublicoDosAvisosDePonto;
use App\Ponto\Service\CartaoDePendenciaDoPonto;
use App\Ponto\Service\ChoqueDeJornadaEntreEscritorios;
use App\Ponto\Service\EnvioDaBatida;
use App\Ponto\Service\EnvioLidoDaBatida;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Enum\AcaoDaTrilhaDoPonto;
use App\Ponto\Service\DetalheDaTrilhaDoPonto;
use App\Ponto\Service\GestaoDoPonto;
use App\Ponto\Service\JustificativasAAnalisarDaEquipe;
use App\Ponto\Service\TrilhaDoPonto;
use App\Repository\SedeRepository;
use App\Repository\UserRepository;
use App\Entity\Tenant\Tenant;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Shared\Http\EntregaDeArquivo;
use App\Shared\Http\FonteDeUploadHttp;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Ponto\Service\FeriadosDaPessoa;
use App\Ponto\Service\FolhaPontoBuilder;
use App\Ponto\Service\DadosDaFolhaExportada;
use App\Ponto\DTO\PdfDaFolhaOutput;
use App\Ponto\UseCase\GerarPdfDaFolhaUseCase;
use App\Ponto\Service\VerificadorAlertaPonto;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use App\Ponto\Service\FolhaPontoXlsxExporter;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;
use App\Shared\Trait\ValidaCsrfAjaxTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/ponto')]
final class PontoController extends AbstractController
{
    use ValidaCsrfAjaxTrait;

    /** Abas do cartão da direita, na ordem do desenho (L758); a primeira abre ao entrar (P37). As das etapas seguintes vêm depois. */
    private const ABAS_DO_CARTAO = ['pendencias', 'justificativas', 'comprovantes', 'banco', 'equipe', 'auditoria', 'configuracoes'];

    /** O limite `ponto_folha_pdf` estourado (429). */
    public const MUITOS_PEDIDOS_DE_FOLHA = 'Muitos pedidos de folha em sequência. Aguarde um minuto e tente de novo.';

    /** Recusa da exclusão (P41) para a resposta HTTP: outro escritório não existe para quem pede. */
    private const RESPOSTA_DA_RECUSA = [
        ExclusaoDeJustificativaRecusadaException::NAO_ENCONTRADA    => Response::HTTP_NOT_FOUND,
        ExclusaoDeJustificativaRecusadaException::DE_OUTRA_PESSOA   => Response::HTTP_FORBIDDEN,
        ExclusaoDeJustificativaRecusadaException::JA_ANALISADA      => Response::HTTP_UNPROCESSABLE_ENTITY,
        ExclusaoDeJustificativaRecusadaException::DA_BATIDA_EXTERNA => Response::HTTP_UNPROCESSABLE_ENTITY,
        ExclusaoDeJustificativaRecusadaException::MEXEU_NA_FOLHA    => Response::HTTP_UNPROCESSABLE_ENTITY,
    ];

    /**
     * Flash de tipo próprio do aviso flutuante da /ponto (E8, P80; desenho L266 e `avisar` L468), no tom verde do
     * desenho. O `index` o lê antes de montar a página, e por isso o laço de flashes do topo do layout (base.html.twig)
     * não o encontra mais: o texto sai uma vez só, no aviso flutuante. Só vale para quem volta à /ponto: fora dela não há
     * aviso flutuante, e o flash de sempre continua.
     */
    public const FLASH_AVISO_FLUTUANTE = 'ponto_aviso_flutuante';

    /** O mesmo aviso, no tom vermelho do desenho (#a3232b). */
    public const FLASH_AVISO_FLUTUANTE_ERRO = 'ponto_aviso_flutuante_erro';

    /** O tom de cada flash do aviso flutuante (js/ponto-aviso-flutuante.js). */
    private const TOM_DO_AVISO_FLUTUANTE = [
        self::FLASH_AVISO_FLUTUANTE      => 'ok',
        self::FLASH_AVISO_FLUTUANTE_ERRO => 'erro',
    ];

    /** O aviso flutuante do envio da justificativa (desenho L768, `addJ`). */
    public const AVISO_ENVIADO_AO_GESTOR = 'Enviado para aprovação do gestor.';

    /** Os avisos flutuantes da análise de uma justificativa (desenho L601, `analisar`): verde e vermelho. */
    public const AVISO_JUSTIFICATIVA_ABONADA = 'Justificativa abonada.';
    public const AVISO_JUSTIFICATIVA_RECUSADA = 'Justificativa recusada.';

    public function __construct(
        private readonly VerificadorAlertaPonto $verificadorAlerta,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly TransacaoComArquivoNovo $transacao,
        private readonly EntregaDeArquivo $entrega,
        private readonly JornadaResolver $jornadaResolver,
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly UserTenantRepository $userTenantRepository,
        private readonly InicioContagemResolver $inicioContagemResolver,
        private readonly SubstituirAnexoDoLoteUseCase $substituirAnexoDoLote,
        private readonly ConfirmarEdicaoDeJustificativaUseCase $confirmarEdicao,
        private readonly JornadaColaboradorRepository $jornadas,
        private readonly ChaveDoPontoEletronico $chaveDoPonto,
        private readonly TurnoAbertoNoEscritorio $turnoAberto,
        private readonly HorarioFuturoDaBatida $horarioFuturo,
        private readonly AlertasAntesDaBatida $alertasAntesDaBatida,
        private readonly BatidaJaRegistrada $batidaJaRegistrada,
        private readonly PublicoDosAvisosDePonto $publico,
        private readonly EnvioDaBatida $envioDaBatida,
        private readonly JustificativaPontoRepository $justificativas,
        private readonly CercaDoPonto $cerca,
        private readonly TrabalhoExternoDaBatida $trabalhoExterno,
    ) {}

    #[Route('/', name: 'ponto_index')]
    public function index(
        Request $request,
        RegistroPontoRepository $repository,
        FeriadoRepository $feriadoRepository,
        JustificativaPontoRepository $justificativaRepository,
        FolhaPontoBuilder $folhaPontoBuilder,
        BancoDeHorasPorLotes $bancoDeHorasPorLotes,
        HomeOfficeResolver $homeOfficeResolver,
        SedesDaCercaDoPonto $sedesDaCerca,
        AbaDosComprovantes $abaDosComprovantes,
        CalculadoraJornada $calculadoraJornada,
        ListarPendenciasDeRegistroUseCase $pendencias,
        AnaliseDaPropriaJustificativa $analiseDaPropria,
        FuncoesDoPonto $funcoes,
        GestaoDoPonto $gestao,
        JustificativasAAnalisarDaEquipe $aAnalisarDaEquipe,
        PontoAjusteRepository $ajustes,
        FeriadosDaPessoa $feriadosDaPessoa,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $tenant = $this->assertAccess($user);
        $jornadaTenant = $tenant->getJornadaTenant();

        $agora = new \DateTimeImmutable();
        $mes = (int) $agora->format('m');
        $ano = (int) $agora->format('Y');
        $competenciaAtual = $agora->format('Y-m');

        // P23 e D7 (spec ponto-desenho-0811 §5 E2): todos os meses desde o início da contagem neste escritório.
        $inicioContagem = $this->inicioContagemResolver->resolver($user, $tenant);
        $competenciasPonto = $this->competenciasDaFolha($agora, $inicioContagem);
        $competenciasDisponiveis = array_column($competenciasPonto, 'valor');

        $competenciaSelecionada = (string) $request->query->get('competencia', $competenciaAtual);
        if (!in_array($competenciaSelecionada, $competenciasDisponiveis, true)) {
            $competenciaSelecionada = $competenciaAtual;
        }

        [$anoSelecionado, $mesSelecionado] = array_map('intval', explode('-', $competenciaSelecionada));

        $inicioMes = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $anoSelecionado, $mesSelecionado));
        $fimMes = $inicioMes->modify('last day of this month')->setTime(23, 59, 59);

        /** @var list<MarcacaoDoPonto> $batidas as efetivas (E10) */
        $batidas = $repository->findByUserAndCompetencia($user, $tenant, $anoSelecionado, $mesSelecionado);
        $jornadaDoEscritorio = $this->jornadas->findByUserETenant($user, $tenant);
        // A folha usa a versão da jornada de cada dia (spec ponto-jornada-vigencia §3.2); a meta de hoje, a de hoje.
        // Sem jornada pessoal no dia, a jornada vazia (a padrão do escritório), como antes de a pessoa ter uma (§10.4).
        // Montada no builder, a mesma do banco de horas (E17): a soma do "Saldo mês" da folha é o banco.
        $jornada = $folhaPontoBuilder->jornadaDaFolha($user, $tenant);
        $feriados = $feriadoRepository->findByTenant($tenant);

        $justificativasDoMes = $justificativaRepository->findByUserAndCompetenciaIndexed($user, $tenant, $anoSelecionado, $mesSelecionado);
        $folhaRows = $folhaPontoBuilder->buildRows($inicioMes, $fimMes, $batidas, false, false, $jornada, $feriados, $justificativasDoMes, $jornadaTenant, $inicioContagem);

        $hojeStr = $agora->format('Y-m-d');
        $batidasParaHoje = ($competenciaSelecionada === $competenciaAtual)
            ? $batidas
            : $repository->findByUserAndCompetencia($user, $tenant, $ano, $mes);
        $batidasHoje = array_filter(
            $batidasParaHoje,
            fn($b) => $b->getDataHora()->format('Y-m-d') === $hojeStr
        );

        // `$pontoHoje` guarda uma batida por tipo: a da escolha ÚNICA, a mesma que a folha logo abaixo
        // conta (`docs/specs/ponto-folha-uma-batida-por-tipo.md` §9.9). Até 21/09/2026 era a última de
        // cada tipo, e o quadro podia dizer uma hora e a folha outra.
        //
        // Quantas batidas de cada tipo existem HOJE, também. Sem a contagem, duas entradas no mesmo
        // dia apareceriam como uma — justamente a duplicata que o aviso de envio sem confirmação pode
        // provocar, escondida no lugar que existe para revelá-la.
        $escolhaDeHoje = $calculadoraJornada->escolherBatidas(array_values($batidasHoje), $agora);
        $pontoHoje = ['entrada' => null, 'repouso' => null, 'retorno' => null, 'saida' => null];
        $quantasHoje = ['entrada' => 0, 'repouso' => 0, 'retorno' => 0, 'saida' => 0];
        foreach (array_keys($pontoHoje) as $tipo) {
            $pontoHoje[$tipo] = $escolhaDeHoje->doTipo($tipo)?->getDataHora()->format('H:i:s');
        }
        foreach ($batidasHoje as $batida) {
            $tipo = $batida->getTipo();
            if (array_key_exists($tipo, $quantasHoje)) {
                $quantasHoje[$tipo]++;
            }
        }

        $bancoDeHoras = $bancoDeHorasPorLotes->calcular($user, $tenant, $agora);   // E17: lotes, não zera em janeiro (P51 a)
        $saldoMes = $bancoDeHoras->saldoAtualMinutos;
        $horasPagasMinutos = $folhaPontoBuilder->somarHorasPagasDaCompetencia($user, $tenant, $anoSelecionado, $mesSelecionado);

        $jornadaInfo = $this->resolverJornadaInfo($jornadaDoEscritorio, $jornadaTenant);
        $duracaoJornadaDiariaMinutos = $this->resolverDuracaoJornadaDiaria($jornadaDoEscritorio, $agora, $jornadaTenant);
        // Contador de horas excedentes (05/11/2026): a meta do dia é a do alerta de "hora de ir embora",
        // já sem o almoço. Feriado zera, como na folha (`CalculadoraJornada::calcularSaldoDiaDaEscolha`): só o que
        // vale para a pessoa hoje (E6, `FeriadosDaPessoa`), o do escritório todo ou o da lotação dela no dia.
        // Meta 0 (feriado ou dia fora da escala): a tela não mostra contador.
        $metaDiaMinutos = $calculadoraJornada->getFeriadoDoDia($agora, $feriadosDaPessoa->filtrar($feriados, $user, $agora)) !== null
            ? 0
            : $this->jornadaResolver->resolverMetaDia($jornadaDoEscritorio, $agora, $jornadaTenant);

        $justificativaForm = $this->createForm(JustificativaPontoType::class);

        $homeOfficeHoje = $homeOfficeResolver->estaLiberado($user, $tenant, $agora);

        // Sedes do escritório ATIVO, para a tela conseguir dizer "você está fora da área" ANTES de
        // a pessoa apertar. Hoje o botão só trava por falta de posição: quem está fora do raio
        // aperta e leva um erro depois, o que o dono pediu para mudar.
        //
        // 🔑 Isto NÃO é a regra. A regra continua em `batida()`, no servidor, que é quem decide.
        // A tela é cortesia, e por isso é deliberadamente mais permissiva (ver `avaliarPosicao` no
        // template): uma verificação só no navegador é contornável em segundos.
        //
        // Espelha as duas exclusões do servidor: sede sem coordenada e raio não positivo não
        // delimitam nada e ficam de fora nos dois lados. Mesmo serviço do atalho do cabeçalho.
        // Geolocalização desligada (E7, Master ou escritório): a tela não pede a posição nem avalia a cerca.
        $geoLigado = $funcoes->vale(FuncoesDoPonto::GEO, $tenant);
        $sedesDaTela = $geoLigado ? $sedesDaCerca->doEscritorio($tenant) : [];

        $justificativasDaCompetencia = $justificativaRepository->findByUserAndCompetencia($user, $tenant, $anoSelecionado, $mesSelecionado);

        // Atalho "+" da folha: célula que já tem esquecimento pendente ou abonado não oferece o
        // botão de novo (condição 8). Reaproveita a lista acima; nenhuma query nova. Mesma regra do
        // "Regularizar ponto de ontem" do atalho do cabeçalho (`EsquecimentosRegistrados`).
        $esquecimentosRegistrados = EsquecimentosRegistrados::dasJustificativas($justificativasDaCompetencia);

        return $this->render('ponto/index.html.twig', [
            'homeOfficeHoje' => $homeOfficeHoje,
            'geoLigado'      => $geoLigado,
            'sedesDaTela'    => $sedesDaTela,
            // E12 (P18, P74): a cerca vale hoje para a pessoa (sem home office e com a geolocalização) e o escritório
            // permite o trabalho externo. Como a cerca da tela, é cortesia: quem decide é a `batida()`.
            'trabalho_externo_permitido' => !$homeOfficeHoje && $geoLigado && $this->trabalhoExterno->permitido($tenant),
            'folhaRows' => $folhaRows,
            'mesAtual' => $mesSelecionado,
            'anoAtual' => $anoSelecionado,
            'competenciasPonto' => $competenciasPonto,
            'competenciaSelecionada' => $competenciaSelecionada,
            'pontoHoje' => $pontoHoje,
            'quantasHoje' => $quantasHoje,
            'saldoMes' => $saldoMes,
            'horasPagasMinutos' => $horasPagasMinutos,
            // Bloco de totais do rodapé da folha (spec §7): a conta do banco para a competência, sem as horas pagas (que
            // somam à parte). A última linha não serve: a /ponto esconde os dias sem batida e eles contam (E17).
            'saldoMesMinutos' => ($bancoDeHoras->saldosPorCompetencia[$competenciaSelecionada] ?? 0) - $horasPagasMinutos,
            'jornadaInfo' => $jornadaInfo,
            'minimoMinutosRepouso'           => $jornadaTenant?->getMinimoMinutosRepouso() ?? 60,
            'validacaoRepousoHabilitada'     => $jornadaTenant?->isValidacaoRepousoHabilitada() ?? true,
            // E11: com `ponto-alertas` desligada (Master ou escritório) o aviso do intervalo do cartão some (spec §7).
            'alertasLigados'                 => $funcoes->vale(FuncoesDoPonto::ALERTAS, $tenant),
            'duracaoJornadaDiariaMinutos'    => $duracaoJornadaDiariaMinutos,
            'metaDiaMinutos'                 => $metaDiaMinutos,
            'justificativas'     => $justificativasDaCompetencia,
            'justificativaForm'  => $justificativaForm->createView(),
            // P45 (decisão do Coordenador, 09/11): os 45 tipos nos 6 grupos (`<optgroup>`), nas janelas de justificar e de editar.
            'tiposJustificativa' => TipoJustificativa::asGroupedChoices(),
            'esquecimentosRegistrados' => $esquecimentosRegistrados,
            'esquecimentosEmAnalise'   => EsquecimentosRegistrados::diasEmAnalise($justificativasDaCompetencia),
            'dataHoje'                 => $hojeStr,
            // Hora oficial do cartão (desenho, D9): o instante do servidor; a tela só soma o tempo decorrido.
            'agoraServidor'            => $agora,
            // Virada do dia na tela: a próxima meia-noite no fuso do servidor, em ms (a tela soma o tempo passado).
            'proximaViradaMs'          => (int) $agora->modify('tomorrow')->format('Uv'),
            'pendencias'               => $this->publico->recebe($user, $tenant) ? $pendencias->executar($user, $tenant) : [],   // R5 e R7
            // Cartão de abas (E3): Pendências abre ao entrar (P37); a volta da análise reabre a das justificativas (a da exclusão
            // também, mas só no plano B do js/ponto-abas.js, quando a linha não está na tela).
            'abaAberta'                => $this->abaAberta($request),
            'podeAnalisarAPropria'     => $analiseDaPropria->permite($user, $user, $tenant),   // P43 a: o botão segue o servidor
            // E10: a em análise que já mexeu na folha não mostra o "Excluir" (o servidor recusa do mesmo jeito).
            'justificativasComAjuste'  => $ajustes->idsDasJustificativasComAjuste($tenant, self::idsEmAnalise($justificativasDaCompetencia)),
            // Aba "Comprovantes" (E15): só com o comprovante ligado; a lista é da pessoa, neste escritório.
            'comprovantesVisiveis'     => $abaDosComprovantes->visivel($tenant, $user),
            'comprovantes'             => $abaDosComprovantes->linhas($user, $tenant),
            // Aba "Banco de Horas" (E17): nulo esconde a aba.
            'bancoDeHoras'             => $bancoDeHoras,
        ] + $this->daTelaCheiaEDosAvisos($funcoes, $tenant, $request) + $this->abasDeGestao($user, $tenant, $gestao, $aAnalisarDaEquipe));
    }

    /**
     * E8 (P79 a P83, P96): o valor efetivo (Global E Escritório) das funções da tela, como o `geoLigado` do `index`. O
     * aviso flutuante que um envio deixou (`FLASH_AVISO_FLUTUANTE`) é lido aqui, antes do layout, para não sair também no
     * topo da página.
     *
     * @return array{animacaoLigada: bool, sonsLigados: bool, lembreteLigado: bool, avisoFlutuante: array{texto: string, tom: string}|null}
     */
    private function daTelaCheiaEDosAvisos(FuncoesDoPonto $funcoes, Tenant $tenant, Request $request): array
    {
        return [
            'animacaoLigada' => $funcoes->vale(FuncoesDoPonto::ANIMACAO, $tenant),
            'sonsLigados'    => $funcoes->vale(FuncoesDoPonto::SONS, $tenant),
            'lembreteLigado' => $funcoes->vale(FuncoesDoPonto::LEMBRETE, $tenant),
            'avisoFlutuante' => $this->avisoFlutuanteDaSessao($request),
        ];
    }

    /**
     * Abas de gestão do cartão (E5, E6 e E9; P58 e P64): cada uma só para quem a vê (`GestaoDoPonto`). Sem ela, o
     * `_abas.html.twig` não a mostra e o endereço dela cai em Pendências. O número da Equipe (as justificativas a
     * analisar) só é contado para quem vê a Equipe: para os outros, 0 sem consulta.
     *
     * @return array{veEquipe: bool, veConfiguracoes: bool, veAuditoria: bool, equipeAAnalisar: int}
     */
    private function abasDeGestao(User $user, Tenant $tenant, GestaoDoPonto $gestao, JustificativasAAnalisarDaEquipe $aAnalisarDaEquipe): array
    {
        $veEquipe = $gestao->veEquipe($user, $tenant);

        return [
            'veEquipe'        => $veEquipe,
            'veConfiguracoes' => $gestao->veConfiguracoes($user, $tenant),
            'veAuditoria'     => $gestao->veAuditoria($user, $tenant),
            'equipeAAnalisar' => $veEquipe ? $aAnalisarDaEquipe->contarAAnalisar($user, $tenant) : 0,
        ];
    }

    #[Route('/justificativa/nova', name: 'ponto_justificativa_nova', methods: ['GET', 'POST'])]
    public function novaJustificativa(
        Request $request,
        EntityManagerInterface $entityManager,
        JustificativaPontoRepository $justificativaRepository,
        NotificacaoService $notificacaoService,
        TrilhaDoPonto $trilha,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $tenant = $this->assertAccess($user);

        $voltaParaFolha = $this->voltaDaJanela($request);

        $form = $this->createForm(JustificativaPontoType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $datasRaw = trim((string) $form->get('datas')->getData());
            $tipo = $form->get('tipo')->getData();
            $abonoParcial = (bool) $form->get('abonoParcial')->getData();
            $horaInicio = $form->get('horaInicioAbono')->getData();
            $horaFim = $form->get('horaFimAbono')->getData();

            $tipoRegEsquecido = null;
            $horaRegEsquecida = null;
            if ($tipo === 'esquecimento_registro') {
                $tipoRegEsquecido = $form->get('tipoRegistroEsquecido')->getData();
                $horaRegEsquecida = $form->get('horaRegistroEsquecido')->getData();
                if (!in_array($tipoRegEsquecido, RegistroPonto::TIPOS_VALIDOS, true) || $horaRegEsquecida === null) {
                    $this->addFlash('warning', 'Informe o tipo e o horário do registro esquecido.');
                    return $this->redirectToRoute('ponto_index', $voltaParaFolha);
                }
            }

            $datasArray = array_filter(array_map('trim', explode(',', $datasRaw)));

            if (empty($datasArray)) {
                $this->addFlash('warning', 'Selecione ao menos uma data para justificar.');
                return $this->redirectToRoute('ponto_index', $voltaParaFolha);
            }

            if ($abonoParcial && count($datasArray) > 1) {
                $this->addFlash('warning', 'Abono parcial só pode ser aplicado a uma única data.');
                return $this->redirectToRoute('ponto_index', $voltaParaFolha);
            }

            if ($tipo === 'esquecimento_registro' && count($datasArray) > 1) {
                $this->addFlash('warning', 'Esquecimento de Registro só pode ser aplicado a uma única data.');
                return $this->redirectToRoute('ponto_index', $voltaParaFolha);
            }

            if ($abonoParcial && ($horaInicio === null || $horaFim === null || $horaInicio >= $horaFim)) {
                $this->addFlash('warning', 'Informe os horários de saída e retorno corretamente para o abono parcial.');
                return $this->redirectToRoute('ponto_index', $voltaParaFolha);
            }

            $hoje = new \DateTimeImmutable('today');
            $datasValidas = [];

            foreach ($datasArray as $dataStr) {
                $dataObj = \DateTime::createFromFormat('Y-m-d', $dataStr);
                if ($dataObj === false) {
                    continue;
                }
                // Não permitir datas futuras
                if ($dataObj->format('Y-m-d') > $hoje->format('Y-m-d')) {
                    $this->addFlash('warning', sprintf('A data %s é futura e foi ignorada.', $dataObj->format('d/m/Y')));
                    continue;
                }
                $datasValidas[] = $dataObj;
            }

            if (empty($datasValidas)) {
                return $this->redirectToRoute('ponto_index', $voltaParaFolha);
            }
            if ($tipo === 'esquecimento_registro' && ($recusa = $this->recusaDoEsquecimento($user, $tenant, (string) $tipoRegEsquecido, $datasValidas[0], $horaRegEsquecida)) !== null) {
                $this->addFlash('warning', $recusa);
                return $this->redirectToRoute('ponto_index', $voltaParaFolha);
            }

            // Upload do atestado
            $anexoPath  = null;
            $chaveAnexo = null;
            $anexoFile  = $form->get('anexo')->getData();
            if ($anexoFile !== null) {
                // O arquivo nasce ANTES das justificativas do lote (ordem da E1); o escopo é o
                // mesmo `$tenant` que cada uma recebe abaixo em setTenant().
                $upload     = FonteDeUploadHttp::de($anexoFile);
                $chaveAnexo = $upload->gravarEm(
                    $this->armazenamento,
                    ChavesDePonto::novoAnexoDeLote($tenant, $upload->extensao),
                )->chave;
                $anexoPath  = $chaveAnexo->nome;
            }

            $batchId = bin2hex(random_bytes(16));
            $isFaltaNaoJustificada = $tipo === 'falta_nao_justificada';
            $statusInicial = $isFaltaNaoJustificada ? 'abonado' : 'pendente';
            $justificativasCriadas = [];

            foreach ($datasValidas as $dataObj) {
                $justificativa = new JustificativaPonto();
                $justificativa->setUser($user);
                $justificativa->setTenant($tenant);
                $justificativa->setData($dataObj);
                $justificativa->setTipo($tipo);
                $justificativa->setAnexoPath($anexoPath);
                $justificativa->setStatus($statusInicial);
                $justificativa->setBatchId($batchId);
                $justificativa->setAbonoParcial($abonoParcial);
                $justificativa->setMotivo(trim((string) $form->get('motivo')->getData()));
                if ($abonoParcial) {
                    $justificativa->setHoraInicioAbono($horaInicio);
                    $justificativa->setHoraFimAbono($horaFim);
                }
                if ($tipo === 'esquecimento_registro') {
                    $justificativa->setTipoRegistroEsquecido($tipoRegEsquecido);
                    $justificativa->setHoraRegistroEsquecido($horaRegEsquecida);
                }

                $entityManager->persist($justificativa);
                $justificativasCriadas[] = $justificativa;
            }

            $this->confirmarEnvio($entityManager, $trilha, $tenant, $user, $justificativasCriadas, $chaveAnexo);

            if (!$isFaltaNaoJustificada) {
                // Leva o gestor direto à aba de justificativas do colaborador (aprovar/recusar)
                $urlGestor = $this->generateUrl('app_tenant_user_edit_role', [
                    'tenantId' => $tenant->getId(),
                    'id' => $user->getId(),
                    'tab' => 'justificativas',
                ]);
                foreach ($justificativasCriadas as $j) {
                    $notificacaoService->notificarJustificativaEnviada($j, $urlGestor, $tenant);
                }
            }

            if ($isFaltaNaoJustificada) {
                $this->addFlash('success', sprintf(
                    'Falta registrada como não justificada para %d dia(s).',
                    count($datasValidas)
                ));
            } else {
                // E8 (P80, decisão do Coordenador de 09/11/2026): o aviso flutuante do desenho no lugar do flash do topo.
                $this->addFlash(self::FLASH_AVISO_FLUTUANTE, self::AVISO_ENVIADO_AO_GESTOR);
            }
        } elseif ($form->isSubmitted() && !$form->isValid()) {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('danger', $error->getMessage());
            }
        }

        return $this->redirectToRoute('ponto_index', $voltaParaFolha);
    }

    /**
     * Confirma o lote enviado pelo colaborador com o evento da trilha do Ponto (E9) no mesmo COMMIT. O arquivo foi
     * gravado antes da transação. Se ela falhar, ele só sai quando estiver PROVADO que nada foi confirmado; num COMMIT de
     * resultado incerto as justificativas podem existir e apontar para ele, e o arquivo fica (E2.5, INV-6). A exceção
     * original continua subindo.
     *
     * @param list<JustificativaPonto> $justificativas
     */
    private function confirmarEnvio(EntityManagerInterface $entityManager, TrilhaDoPonto $trilha, Tenant $tenant, User $user, array $justificativas, ?ChaveDeArquivo $chaveAnexo): void
    {
        $this->transacao->executar(
            static function () use ($entityManager, $trilha, $tenant, $user, $justificativas): void {
                $entityManager->flush();
                $trilha->registrar($tenant, $user, AcaoDaTrilhaDoPonto::JustificativaEnviada->value, DetalheDaTrilhaDoPonto::doLote($justificativas));
            },
            static fn (): array => $chaveAnexo === null ? [] : [$chaveAnexo],
            'PontoController::novaJustificativa: atestado do lote',
        );
    }

    /**
     * O esquecimento é só da batida que falta e nunca de um horário que ainda não chegou.
     */
    private function recusaDoEsquecimento(User $user, Tenant $tenant, string $tipo, \DateTimeInterface $dia, ?\DateTimeInterface $hora): ?string
    {
        if (($jaBatida = $this->batidaJaRegistrada->mensagem($user, $tenant, $tipo, $dia)) !== null) {
            return $jaBatida;
        }

        return $hora !== null && $this->horarioFuturo->estaNoFuturo($dia, $hora) ? HorarioFuturoDaBatida::MENSAGEM : null;
    }

    #[Route('/justificativa/{id}/editar', name: 'ponto_justificativa_editar', methods: ['GET', 'POST'])]
    public function editarJustificativa(
        JustificativaPonto $justificativa,
        Request $request,
    ): Response {
        /** @var User $user */
        $user   = $this->getUser();
        $tenant = $this->assertAccess($user);

        if ($justificativa->getUser()->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('Acesso negado a esta justificativa.');
        }

        if (!$this->isCsrfTokenValid('editar_justificativa_' . $justificativa->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de segurança inválido.');
            return $this->voltaDaEdicao($justificativa);
        }

        // C2-01 (docs/specs/ponto-edicao-justificativa-analisada.md): o colaborador só edita o que o
        // gestor ainda não analisou — tipo e horário de uma abonada mudam horas já aprovadas. Esta é a
        // recusa rápida, pelo estado carregado no começo da requisição; sem atestado, a que vale contra
        // uma análise simultânea é a do ConfirmarEdicaoDeJustificativaUseCase, com a linha travada.
        if ($justificativa->getStatus() !== 'pendente') {
            $this->addFlash('warning', JustificativaJaAnalisadaException::MENSAGEM);

            return $this->voltaDaEdicao($justificativa);
        }
        // E12: a da batida de trabalho externo é a análise daquela batida; o colaborador não a muda.
        if ($justificativa->analisaUmaBatida()) {
            $this->addFlash('warning', TrabalhoExternoDaBatida::MENSAGEM_EDICAO_RECUSADA);

            return $this->voltaDaEdicao($justificativa);
        }

        $tipo = $request->request->get('tipo');
        $abonoParcial = (bool) $request->request->get('abonoParcial', false);
        $horaInicioRaw = $request->request->get('horaInicioAbono');
        $horaFimRaw = $request->request->get('horaFimAbono');

        if ($tipo !== null && !in_array($tipo, TipoJustificativa::valores(), true)) {
            $tipo = null;
        }

        $horaInicio = null;
        $horaFim = null;

        if ($abonoParcial) {
            $horaInicio = $horaInicioRaw !== null ? \DateTime::createFromFormat('H:i', $horaInicioRaw) ?: null : null;
            $horaFim = $horaFimRaw !== null ? \DateTime::createFromFormat('H:i', $horaFimRaw) ?: null : null;

            if ($horaInicio === null || $horaFim === null || $horaInicio >= $horaFim) {
                $this->addFlash('warning', 'Informe os horários de saída e retorno corretamente para o abono parcial.');
                return $this->voltaDaEdicao($justificativa);
            }
        }

        $justificativa->setTipo($tipo ?: null);
        $justificativa->setAbonoParcial($abonoParcial);
        $justificativa->setHoraInicioAbono($abonoParcial ? $horaInicio : null);
        $justificativa->setHoraFimAbono($abonoParcial ? $horaFim : null);

        $recusa = $this->aplicarMotivoDaEdicao($request, $justificativa, $user, $tenant)
            ?? $this->aplicarEsquecimentoDaEdicao($request, $justificativa, $tipo === 'esquecimento_registro', $user, $tenant);
        if ($recusa !== null) {
            $this->addFlash('warning', $recusa);

            return $this->voltaDaEdicao($justificativa);
        }

        // A troca do anexo vem ANTES do flush de propósito. Se o arquivo for recusado, a edição
        // inteira tem de ser descartada: validar depois de gravar deixaria o tipo/abono já salvos
        // na folha com uma mensagem de erro na tela — o usuário concluiria que nada foi salvo.
        // A troca atinge o LOTE inteiro e tem ordem própria entre banco e disco
        // (ver SubstituirAnexoDoLoteUseCase); o flush dela cobre também os campos acima.
        $anexoFile = $request->files->get('anexo');

        if ($anexoFile instanceof UploadedFile) {
            try {
                $atingidos = $this->substituirAnexoDoLote->executar($justificativa, $anexoFile, $tenant);
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('warning', $e->getMessage());

                return $this->voltaDaEdicao($justificativa);
            } catch (TrocaDeAnexoRecusadaException $e) {
                // Lote já analisado: a recusa descarta a edição inteira, como o anexo inválido
                // acima. A transação que recusou já fechou o EntityManager — só resta avisar.
                $this->addFlash('warning', $e->getMessage());

                return $this->voltaDaEdicao($justificativa);
            }

            if ($atingidos > 1) {
                $this->addFlash('info', sprintf(
                    'O anexo foi trocado nos %d dias deste abono.',
                    $atingidos,
                ));
            }
        } else {
            try {
                $this->confirmarEdicao->executar($justificativa, $tenant);
            } catch (JustificativaJaAnalisadaException $e) {
                // Analisada entre o carregamento e a gravação: nada foi gravado.
                $this->addFlash('warning', $e->getMessage());

                return $this->voltaDaEdicao($justificativa);
            }
        }

        $this->addFlash('success', 'Justificativa atualizada com sucesso.');
        return $this->voltaDaEdicao($justificativa);
    }

    /**
     * Volta da janela "Editar justificativa" (E4, achado da revisão da E3): a aba "Minhas Justificativas" no mês da
     * justificativa, o mesmo endereço da volta da exclusão e da análise. Antes voltava em Pendências, no mês corrente.
     */
    private function voltaDaEdicao(JustificativaPonto $justificativa): Response
    {
        $dia = $justificativa->getData();

        return $this->redirectToRoute('ponto_index', ($dia !== null ? ['competencia' => $dia->format('Y-m')] : []) + ['aba' => 'justificativas']);
    }

    /**
     * E4: a janela "Editar justificativa" sempre manda o motivo, e em branco não sai (o desenho o pede ao criar). Sem o
     * campo no pedido, o motivo gravado fica como está. O motivo é do lote (nasce o mesmo em cada dia e a ficha do gestor
     * mostra o do primeiro dia): vai para os dias em análise do lote (`diasEmAnaliseDoLote`). Devolve a recusa, ou null.
     */
    private function aplicarMotivoDaEdicao(Request $request, JustificativaPonto $justificativa, User $user, Tenant $tenant): ?string
    {
        if (!$request->request->has('motivo')) {
            return null;
        }
        $motivo = trim((string) $request->request->get('motivo'));
        if ($motivo === '') {
            return 'Informe o motivo.';
        }
        if (mb_strlen($motivo) > JustificativaPontoType::MOTIVO_MAXIMO) {
            return sprintf('O motivo pode ter até %d caracteres.', JustificativaPontoType::MOTIVO_MAXIMO);
        }
        foreach ($this->diasEmAnaliseDoLote($justificativa, $user, $tenant) as $dia) {
            $dia->setMotivo($motivo);
        }

        return null;
    }

    /**
     * O dia editado (já conferido: é da pessoa e está em análise) e os outros dias do mesmo lote (`batchId`) no escritório
     * da sessão, da mesma pessoa e ainda em análise. Dia já analisado fica como está. Sem `batchId`, só o dia editado.
     * A gravação é a do flush da edição (ConfirmarEdicaoDeJustificativaUseCase ou SubstituirAnexoDoLoteUseCase): uma
     * recusa antes dele não grava o motivo em dia nenhum.
     *
     * @return list<JustificativaPonto>
     */
    private function diasEmAnaliseDoLote(JustificativaPonto $justificativa, User $user, Tenant $tenant): array
    {
        $batchId = $justificativa->getBatchId();
        if ($batchId === null || $batchId === '') {
            return [$justificativa];
        }
        $outros = array_filter(
            $this->justificativas->findLotePorBatchId($batchId, $tenant),
            static fn (JustificativaPonto $dia): bool => $dia !== $justificativa
                && $dia->getUser()?->getId() === $user->getId()
                && $dia->getStatus() === 'pendente',
        );

        return [$justificativa, ...array_values($outros)];
    }

    /**
     * Tipo e horário da batida esquecida na edição, ou os dois limpos em outro tipo. Movido de editarJustificativa sem
     * mudar a regra (E4: o método estava no teto da catraca de métodos longos). Devolve a recusa, ou null.
     *
     * A edição é em minutos (o campo lê H:i). Com o mesmo tipo de batida e o mesmo H:i do gravado, o horário gravado
     * fica intacto, com os segundos do envio (P46): editar só o motivo não troca 14:32:10 por 14:32:00. Só um H:i novo
     * é regravado (com :00).
     */
    private function aplicarEsquecimentoDaEdicao(Request $request, JustificativaPonto $justificativa, bool $ehEsquecimento, User $user, Tenant $tenant): ?string
    {
        if ($ehEsquecimento) {
            $tipoRegEsquecido = $request->request->get('tipoRegistroEsquecido');
            $horaRegRaw = $request->request->get('horaRegistroEsquecido');
            $horaReg = ($horaRegRaw !== null && $horaRegRaw !== '') ? \DateTime::createFromFormat('H:i', $horaRegRaw) ?: null : null;
            if (!in_array($tipoRegEsquecido, RegistroPonto::TIPOS_VALIDOS, true) || $horaReg === null) {
                return 'Informe o tipo e o horário do registro esquecido.';
            }
            if (($dia = $justificativa->getData()) !== null && ($jaBatida = $this->batidaJaRegistrada->mensagem($user, $tenant, $tipoRegEsquecido, $dia)) !== null) {
                return $jaBatida;   // a edição não troca para uma batida que existe
            }
            $gravada = $justificativa->getHoraRegistroEsquecido();
            $mesmoHorario = $gravada !== null
                && $justificativa->getTipoRegistroEsquecido() === $tipoRegEsquecido
                && $gravada->format('H:i') === $horaReg->format('H:i');
            $justificativa->setTipoRegistroEsquecido($tipoRegEsquecido);
            if (!$mesmoHorario) {
                $justificativa->setHoraRegistroEsquecido($horaReg);
            }
        } else {
            $justificativa->setTipoRegistroEsquecido(null);
            $justificativa->setHoraRegistroEsquecido(null);
        }

        return null;
    }

    /**
     * P41 (desenho do Ponto, E3): o colaborador exclui a própria justificativa ainda em análise, depois de
     * confirmar na janela. JSON para o `fetch` da aba, sem flash e sem redirecionar: a tela tira a linha na hora
     * (um flash aqui sobraria para a próxima página). As recusas do UseCase vêm com a frase em `mensagem`: 404 de
     * outro escritório, 403 de outra pessoa e 422 já analisada. Sem token, o 403 do `validarCsrfAjax`, sem `mensagem`.
     *
     * Ponto único da exclusão: só a aba nova chama esta rota. Com o Ponto antigo de volta, tirar o atributo da rota
     * (a linha abaixo) desliga a exclusão inteira no servidor.
     */
    #[Route('/justificativa/{id}/excluir', name: 'ponto_justificativa_excluir', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function excluirJustificativa(
        int $id,
        Request $request,
        ExcluirJustificativaEmAnaliseUseCase $excluir,
    ): JsonResponse {
        /** @var User $user */
        $user   = $this->getUser();
        $tenant = $this->assertAccess($user);

        // CSRF depois da autorização, como na batida: a falha de acesso tem prioridade.
        $this->validarCsrfAjax($request);

        try {
            $excluir->executar($id, $user, $tenant);
        } catch (ExclusaoDeJustificativaRecusadaException $e) {
            return $this->json(['ok' => false, 'mensagem' => $e->getMessage()], self::RESPOSTA_DA_RECUSA[$e->motivo]);
        }

        return $this->json(['ok' => true]);
    }

    #[Route('/justificativa/{id}/anexo', name: 'ponto_justificativa_anexo', methods: ['GET'])]
    public function downloadAnexo(
        JustificativaPonto $justificativa,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $this->assertAccess($user);

        if ($justificativa->getUser()->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('Acesso negado a este atestado.');
        }

        if ($justificativa->getAnexoPath() === null) {
            throw $this->createNotFoundException('Esta justificativa não possui atestado.');
        }

        $chave = ChavesDePonto::anexoDeJustificativa($justificativa);

        if (!$this->armazenamento->existe($chave)) {
            throw $this->createNotFoundException('Arquivo não encontrado.');
        }

        return $this->entrega->resposta($chave, $justificativa->getAnexoPath(), inline: true);
    }

    #[Route('/alerta-horario', name: 'ponto_alerta_horario', methods: ['GET'])]
    public function alertaHorario(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user) {
            return $this->json(['alertar' => false], 401);
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$this->permissionChecker->canAccessModule($user, $tenant, 'ponto')) {
            return $this->json(['alertar' => false]);
        }

        $jornadaTenant = $tenant?->getJornadaTenant();

        $jornada = $tenant === null ? null : $this->jornadas->findByUserETenant($user, $tenant);
        if ($tenant === null || !$this->publico->recebe($user, $tenant)) {
            return $this->json(['alertar' => false]);   // fora do público dos avisos de ponto
        }

        return $this->json($this->verificadorAlerta->verificar($user, $tenant, new \DateTimeImmutable(), $jornadaTenant, $jornada));
    }

    /**
     * O cartão diário de pendência de registro da própria pessoa (spec `ponto-esquecimento-nao-trava.md` R9): só leitura,
     * pedido pelo parcial global no máximo uma vez por dia (o JS guarda o "fechar" e o "adiar"). `cartao` null fora do
     * público dos avisos de ponto ou sem pendência.
     */
    #[Route('/pendencia-do-dia', name: 'ponto_pendencia_do_dia', methods: ['GET'])]
    public function pendenciaDoDia(CartaoDePendenciaDoPonto $cartao): JsonResponse
    {
        $user = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$user instanceof User || $tenant === null || !$this->permissionChecker->canAccessModule($user, $tenant, 'ponto')) {
            return $this->json(['cartao' => null]);
        }

        return $this->json(['cartao' => $cartao->de($user, $tenant)]);
    }

    /**
     * O ponto de hoje da própria pessoa, para o atalho do cabeçalho (spec `presenca-e-ponto-no-cabecalho.md` §3). Só
     * leitura, com as mesmas guardas da batida: quem não pode bater não lê o estado.
     */
    #[Route('/estado-do-dia', name: 'ponto_estado_do_dia', methods: ['GET'])]
    public function estadoDoDia(ObterEstadoDoPontoDoDiaUseCase $estadoDoDia): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'message' => 'Usuário não autenticado.'], 401);
        }
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$this->permissionChecker->canAccessModule($user, $tenant, 'ponto')) {
            return $this->json(['success' => false, 'message' => 'Sem permissão para registrar ponto.'], 403);
        }
        if ($tenant === null) {
            return $this->json(['success' => false, 'message' => 'Usuário sem tenant configurado.'], 403);
        }

        return $this->json($estadoDoDia->executar($user, $tenant));
    }

    #[Route('/batida', name: 'ponto_batida', methods: ['POST'])]
    public function batida(
        Request $request,
        SedeRepository $sedeRepository,
        RegistroPontoRepository $registroRepository,
        HomeOfficeResolver $homeOfficeResolver,
        EntradaAbertaEmOutroEscritorio $entradaAberta,
        ChoqueDeJornadaEntreEscritorios $choque,
        FuncoesDoPonto $funcoes,
        TrilhaDoPonto $trilha,
        RegistrarBatidaDaPessoaUseCase $registrarBatida,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user) {
            return $this->json(['success' => false, 'message' => 'Usuário não autenticado.'], 401);
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if (($deOutraConta = $this->filaDeOutraConta($request, $user, $tenant)) !== null) {
            return $deOutraConta;   // E14: antes da permissão, do escritório e do CSRF, para a fila não apagar a batida
        }
        if (!$this->permissionChecker->canAccessModule($user, $tenant, 'ponto')) {
            return $this->json(['success' => false, 'message' => 'Sem permissão para registrar ponto.'], 403);
        }

        if ($tenant === null) {
            return $this->json(['success' => false, 'message' => 'Usuário sem tenant configurado.'], 403);
        }

        // CSRF após os guards de autorização (a falha de autz tem prioridade e fica testável).
        $this->validarCsrfAjax($request);

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json([
                'success' => false,
                'message' => 'Payload invalido.',
            ], 400);
        }

        $envio = $this->envioDaBatida->ler($data, (int) $user->getId(), (int) $tenant->getId(), new \DateTimeImmutable());   // um instante na chegada
        if (($resposta = $this->respostaAntesDasRegras($envio, $user, $tenant, $registroRepository)) !== null) {
            return $resposta;
        }

        $latitude    = $data['latitude'] ?? null;
        $longitude   = $data['longitude'] ?? null;
        $precisaoGps = $data['precisaoGps'] ?? null;
        $tipo        = isset($data['tipo']) ? strtolower(trim((string) $data['tipo'])) : null;

        if ($tipo === null || $tipo === '') {
            return $this->json([
                'success' => false,
                'message' => 'Tipo de registro e obrigatorio (Entrada, Repouso, Retorno ou Saida).',
            ], 422);
        }

        if (!in_array($tipo, RegistroPonto::TIPOS_VALIDOS, true)) {
            return $this->json([
                'success' => false,
                'message' => 'Tipo de registro invalido. Selecione Entrada, Repouso, Retorno ou Saida.',
            ], 422);
        }

        $hoje = $envio->momento;   // todas as regras abaixo valem no momento da batida
        if (($recusa = $this->recusaEntreEscritorios($user, $tenant, $tipo, $hoje, $choque, $registroRepository)) !== null) {
            return $this->json(['success' => false, 'message' => $recusa], 422);   // chave ou choque de horário
        }

        // Home office: se o colaborador está liberado hoje (config do tenant atual), pula o geofencing —
        // a batida entra sem GPS nem sede. O resolver reautoriza sempre no servidor.
        $homeOfficeHoje = $homeOfficeResolver->estaLiberado($user, $tenant, $hoje);
        // Geolocalização desligada (E7: o Master ou o escritório): sem cerca e sem sede, e a batida NÃO vira home
        // office. A posição que vier é descartada, como no home office: o escritório escolheu não usar localização.
        $cercaVale = !$homeOfficeHoje && $funcoes->vale(FuncoesDoPonto::GEO, $tenant);

        $posicao = null;
        $motivoExterno = null;   // E12: preenchido só na batida de trabalho externo
        $latitudeFloat = null;
        $longitudeFloat = null;
        $precisaoGpsFloat = null;

        if ($cercaVale) {
            if ($latitude === null || $longitude === null) {
                return $this->json([
                    'success' => false,
                    'message' => 'Geolocalização é obrigatória para registrar o ponto.',
                ], 422);
            }

            if (!is_numeric($latitude) || !is_numeric($longitude)) {
                return $this->json([
                    'success' => false,
                    'message' => 'Coordenadas inválidas para registro do ponto.',
                ], 422);
            }

            $latitudeFloat = (float) $latitude;
            $longitudeFloat = (float) $longitude;

            if (!is_finite($latitudeFloat) || !is_finite($longitudeFloat) || $latitudeFloat < -90 || $latitudeFloat > 90 || $longitudeFloat < -180 || $longitudeFloat > 180) {
                return $this->json([
                    'success' => false,
                    'message' => 'Coordenadas fora da faixa válida.',
                ], 422);
            }

            if (!is_numeric($precisaoGps)) {
                return $this->json([
                    'success' => false,
                    'message' => 'Precisão do GPS inválida.',
                ], 422);
            }

            $precisaoGpsFloat = (float) $precisaoGps;

            if (!is_finite($precisaoGpsFloat) || $precisaoGpsFloat <= 0) {
                return $this->json([
                    'success' => false,
                    'message' => 'Precisão do GPS inválida.',
                ], 422);
            }

            // E12: a cerca com a precisão (CercaDoPonto); o escritório sem sede válida fica fora do raio. E10: só as sedes
            // ativas (a encerrada, removida com batida, não vale para batida nova).
            $posicao = $this->cerca->avaliar($sedeRepository->ativasDoEscritorio($tenant), $latitudeFloat, $longitudeFloat, $precisaoGpsFloat);
            if (!$posicao->estaDentro()) {
                $permitido = $this->trabalhoExterno->permitido($tenant);
                if (!$permitido || !TrabalhoExternoDaBatida::foiPedido($data)) {
                    $recusa = $posicao->recusa($permitido);

                    return $this->json(['success' => false, 'message' => $recusa['titulo'] . '. ' . $recusa['detalhe'], 'foraDoRaio' => $recusa], $posicao->statusDaRecusa());
                }
                $motivoExterno = TrabalhoExternoDaBatida::motivoDo($data);
                if (($recusaDoMotivo = TrabalhoExternoDaBatida::recusaDoMotivo($motivoExterno)) !== null) {
                    return $this->json(['success' => false, 'message' => $recusaDoMotivo], 422);
                }
            }
        }

        // P21 (spec ponto-desenho-0811 E11): o intervalo curto, a interjornada e o 7º dia seguido avisam antes de gravar.
        $intervalo = $this->alertasAntesDaBatida->avaliar($user, $tenant, $tipo, $hoje);
        if (($resposta = $this->respostaDoIntervalo($intervalo, $envio, $data)) !== null) {
            return $resposta;
        }

        $semEntrada = $this->faltouAEntrada($user, $tenant, $tipo, $hoje, $registroRepository);   // antes de gravar
        $registro = new RegistroPonto();
        $registro->setUser($user);
        $registro->setTenant($tenant);
        $registro->setTipo($tipo);
        $envio->gravarEm($registro);
        $registro->setHomeOffice($homeOfficeHoje);

        $justificativaExterna = null;
        if ($posicao !== null) {
            if ($motivoExterno !== null) {
                TrabalhoExternoDaBatida::marcar($registro, $motivoExterno);   // E12: sem sede, com o local e o motivo
                $justificativaExterna = $this->trabalhoExterno->justificativaDa($registro);
            } else {
                $registro->setSede($posicao->sede);
                $registro->setSedeNomeSnapshot($posicao->sede?->getNome());
            }
            $registro->setLatitude((string) $latitudeFloat);
            $registro->setLongitude((string) $longitudeFloat);
            $registro->setPrecisaoGps((string) $precisaoGpsFloat);
        }

        // E15: NSR (D2), comprovante e evento da trilha (E9) na mesma transação do INSERT; o mesmo envio repetido (E13)
        // desfaz tudo e devolve o NSR. Na de trabalho externo (E12), a justificativa ligada entra no mesmo COMMIT.
        $comprovante = null;
        if (!$this->gravouSemRepetirOEnvio($envio, function () use ($entityManager, $registrarBatida, $trilha, $registro, $tenant, $justificativaExterna, &$comprovante): void {
            $comprovante = $this->gravarBatida($entityManager, $registrarBatida, $trilha, $registro, $tenant, $justificativaExterna);
        })) {
            return $this->respostaJaRegistrada($tipo, null);
        }
        if ($justificativaExterna !== null) {
            $this->trabalhoExterno->avisarGestores($justificativaExterna, $tenant);   // depois do COMMIT; a falha não desfaz a batida
        }

        return $this->json([
            'success' => true,
            'message' => match (true) {
                $justificativaExterna !== null => TrabalhoExternoDaBatida::MENSAGEM_REGISTRADA,
                $homeOfficeHoje                => 'Ponto (home office) registrado com sucesso!',
                default                        => 'Ponto registrado com sucesso!',
            } . ($comprovante !== null ? ' Comprovante na aba Comprovantes.' : ''),
            'data'    => $this->dadosDaBatida($registro, $posicao, $justificativaExterna !== null, $comprovante),
            'avisos'  => $this->avisosDaBatida($user, $tenant, $tipo, $hoje, $entradaAberta, $intervalo->avisoDepois, $semEntrada),
        ]);
    }

    /**
     * A batida guardada sem internet em outra conta ou em outro escritório (spec ponto-desenho-0811 E14) volta 409 logo
     * depois da autenticação: com a sessão já em outro escritório, sem o módulo de ponto, o 403 faria a fila apagar a
     * batida. A resposta só repete o que o pedido trouxe e nada muda.
     */
    private function filaDeOutraConta(Request $request, User $user, ?Tenant $tenant): ?JsonResponse
    {
        $dados = json_decode($request->getContent(), true);
        $recusa = is_array($dados) ? $this->envioDaBatida->deOutraConta($dados, (int) $user->getId(), $tenant?->getId()) : null;

        return $recusa === null ? null : $this->json(['success' => false, 'message' => $recusa->mensagem], $recusa->status);
    }

    /**
     * O envio antes de qualquer regra da batida (spec ponto-desenho-0811 §5 E13 e E14): o pedido malformado recusa; a nova
     * tentativa de um envio que já entrou neste escritório responde "já registrada" sem gravar de novo; só então a batida
     * guardada sem internet pode ser recusada (outra conta ou escritório, além do teto de idade, relógio adiantado).
     */
    private function respostaAntesDasRegras(EnvioLidoDaBatida $envio, User $user, Tenant $tenant, RegistroPontoRepository $registros): ?JsonResponse
    {
        if ($envio->recusaDoPedido !== null) {
            return $this->json(['success' => false, 'message' => $envio->recusaDoPedido->mensagem], $envio->recusaDoPedido->status);
        }
        $jaRegistrada = $envio->idEnvio !== null ? $registros->doEnvio($user, $tenant, $envio->idEnvio) : null;
        if ($jaRegistrada !== null) {
            return $this->respostaJaRegistrada((string) $jaRegistrada->getTipo(), $jaRegistrada->getDataHora());
        }
        if ($envio->recusaDaFila !== null) {
            return $this->json(['success' => false, 'message' => $envio->recusaDaFila->mensagem], $envio->recusaDaFila->status);
        }

        return null;
    }

    /**
     * Grava a batida (com NSR, comprovante e o evento da trilha, E9 e E15, na mesma transação). Dois pedidos do mesmo
     * envio ao mesmo tempo (E13): o índice único barra o segundo, a transação desfaz batida, NSR e evento, e ele volta
     * false e responde "já registrada" sem consultar de novo (o EntityManager fecha depois da violação).
     */
    private function gravouSemRepetirOEnvio(EnvioLidoDaBatida $envio, \Closure $gravar): bool
    {
        try {
            $gravar();
        } catch (UniqueConstraintViolationException $violacao) {
            if ($envio->idEnvio === null || !str_contains($violacao->getMessage(), RegistroPonto::INDICE_DO_ENVIO)) {
                throw $violacao;
            }

            return false;
        }

        return true;
    }

    /**
     * Os dados da tela de resultado da batida que entrou: hora e tipo; a marca do trabalho externo (E12) ou, quando a
     * cerca valeu, a sede e a distância; e, com o comprovante emitido, o código e o "Baixar comprovante" (E15; E8,
     * desenho L365 e L368).
     *
     * @return array<string, mixed>
     */
    private function dadosDaBatida(RegistroPonto $registro, ?PosicaoNaCerca $posicao, bool $trabalhoExterno, ?ComprovanteDoPonto $comprovante): array
    {
        $dados = ['hora' => $registro->getDataHora()->format('H:i:s'), 'tipo' => $registro->getTipo()];
        if ($trabalhoExterno) {
            $dados['trabalhoExterno'] = true;
        } elseif ($posicao !== null) {
            $dados['sede'] = $posicao->sede?->getNome();
            $dados['distancia'] = $posicao->distancia !== null ? round($posicao->distancia, 2) : null;
        }
        if ($comprovante !== null) {
            $dados['comprovante'] = ['codigo' => $comprovante->getCodigo(), 'url' => $this->generateUrl('ponto_comprovante_baixar', ['id' => $comprovante->getId()])];
        }

        return $dados;
    }

    /**
     * O aviso flutuante que um envio deixou para a /ponto (`FLASH_AVISO_FLUTUANTE` e `FLASH_AVISO_FLUTUANTE_ERRO`), lido
     * e consumido antes do layout. Um por vez, como no desenho: o último.
     *
     * @return array{texto: string, tom: string}|null
     */
    private function avisoFlutuanteDaSessao(Request $request): ?array
    {
        $sessao = $request->getSession();
        if (!$sessao instanceof FlashBagAwareSessionInterface) {
            return null;
        }
        $aviso = null;
        foreach (self::TOM_DO_AVISO_FLUTUANTE as $tipo => $tom) {
            foreach ($sessao->getFlashBag()->get($tipo) as $texto) {
                $aviso = ['texto' => (string) $texto, 'tom' => $tom];
            }
        }

        return $aviso;
    }

    /** A resposta da batida que já tinha entrado com o mesmo envio; sem a hora quando quem barrou foi o índice único. */
    private function respostaJaRegistrada(string $tipo, ?\DateTimeInterface $hora): JsonResponse
    {
        $dados = $hora !== null ? ['hora' => $hora->format('H:i:s')] : [];

        return $this->json([
            'success' => true,
            'message' => EnvioDaBatida::MENSAGEM_JA_REGISTRADA,
            'data'    => $dados + ['tipo' => $tipo, 'jaRegistrada' => true],
            'avisos'  => [],
        ]);
    }

    /**
     * Grava a batida e o evento dela na trilha de auditoria (E9) no mesmo COMMIT: se a batida falha, a trilha não
     * ganha linha; se a trilha falha, a batida não entra. A batida entra pelo UseCase da E15, que dá o NSR e emite o
     * comprovante dentro desta mesma transação. Na de trabalho externo (E12), a justificativa ligada e o envio dela na
     * trilha entram no mesmo COMMIT (persistida antes, sai no flush do UseCase), com a trava da trilha por último.
     * Devolve o comprovante emitido, ou null.
     */
    private function gravarBatida(EntityManagerInterface $entityManager, RegistrarBatidaDaPessoaUseCase $registrarBatida, TrilhaDoPonto $trilha, RegistroPonto $registro, Tenant $tenant, ?JustificativaPonto $justificativaExterna = null): ?ComprovanteDoPonto
    {
        return $trilha->emTransacao(static function () use ($entityManager, $registrarBatida, $trilha, $registro, $tenant, $justificativaExterna): ?ComprovanteDoPonto {
            if ($justificativaExterna !== null) {
                $entityManager->persist($justificativaExterna);
            }
            $comprovante = $registrarBatida->executar($registro);
            $trilha->registrar($tenant, $registro->getUser(), AcaoDaTrilhaDoPonto::BatidaRegistrada->value, DetalheDaTrilhaDoPonto::daBatida($registro) + ['registro_id' => $registro->getId()]);
            if ($justificativaExterna !== null) {
                $trilha->registrar($tenant, $registro->getUser(), AcaoDaTrilhaDoPonto::JustificativaEnviada->value, DetalheDaTrilhaDoPonto::doLote([$justificativaExterna]));
            }

            return $comprovante;
        });
    }

    /**
     * Os avisos da batida que entrou (spec `ponto-esquecimento-nao-trava.md` R6): o que ficou pendente e não impede o
     * registro. Cada um é `{texto, link, rotulo}`, com `link` e `rotulo` nulos quando não há para onde ir. Só a própria
     * pessoa recebe esta resposta, então o nome do outro escritório pode aparecer (R2).
     *
     * @return list<array{texto: string, link: ?string, rotulo: ?string}>
     */
    private function avisosDaBatida(User $user, Tenant $tenant, string $tipo, \DateTimeImmutable $agora, EntradaAbertaEmOutroEscritorio $entradaAberta, ?string $interjornada, bool $semEntrada): array
    {
        $avisos = [];
        if (!$this->publico->recebe($user, $tenant)) {
            return [];   // R7: fora do público dos avisos de ponto
        }
        if ($tipo === RegistroPonto::TIPO_ENTRADA && ($aberto = $entradaAberta->aviso($user, $tenant, $agora)) !== null) {
            $avisos[] = ['texto' => $aberto, 'link' => $this->generateUrl('tenant_selecionar'), 'rotulo' => EntradaAbertaEmOutroEscritorio::ROTULO_TROCAR];
        }
        if ($interjornada !== null) {
            $avisos[] = ['texto' => $interjornada, 'link' => null, 'rotulo' => null];
        }
        if ($semEntrada) {
            $link = $this->generateUrl('ponto_index', ['competencia' => $agora->format('Y-m'), 'esquecimento' => $agora->format('Y-m-d') . ':' . RegistroPonto::TIPO_ENTRADA]);
            $avisos[] = ['texto' => TurnoAbertoNoEscritorio::AVISO_SEM_ENTRADA, 'link' => $link, 'rotulo' => TurnoAbertoNoEscritorio::ROTULO_ENVIAR];
        }

        return $avisos;
    }

    /**
     * Saída ou ida ao repouso solta (spec `ponto-esquecimento-nao-trava.md` R1): sem turno aberto neste escritório e sem
     * entrada no dia aqui. A batida passa (a folha marca o dia incompleto e conta 0 minutos) e a resposta avisa.
     */
    private function faltouAEntrada(User $user, Tenant $tenant, string $tipo, \DateTimeImmutable $agora, RegistroPontoRepository $registros): bool
    {
        if (!in_array($tipo, [RegistroPonto::TIPO_SAIDA, RegistroPonto::TIPO_REPOUSO], true) || $this->turnoAberto->estaAberto($user, $tenant, $agora)) {
            return false;
        }

        return !$this->temEntradaNoDia($user, $tenant, $agora, $registros);
    }

    private function temEntradaNoDia(User $user, Tenant $tenant, \DateTimeImmutable $agora, RegistroPontoRepository $registros): bool
    {
        foreach ($registros->batidasDoDiaNoEscritorio($user, $tenant, $agora) as $batida) {
            if ($batida->getTipo() === RegistroPonto::TIPO_ENTRADA && $batida->getDataHora() <= $agora) {   // até o momento (E14)
                return true;
            }
        }

        return false;
    }

    /**
     * A resposta das regras de intervalo antes de gravar (spec ponto-desenho-0811 P21 e E11). Nada recusa: o alerta volta
     * sem gravar (200, `success: false`, `alerta`) para a tela abrir a janela "Antes de registrar", e o "Registrar assim
     * mesmo" manda de novo com `confirmarAlerta`. Com a chave `ponto-alertas` desligada não há alerta, e a batida grava
     * direto. A batida da fila sem internet grava sem a janela, que não há como mostrar no toque, e nunca fica presa.
     *
     * @param array<mixed> $dados o JSON da batida
     */
    private function respostaDoIntervalo(IntervaloDaBatida $intervalo, EnvioLidoDaBatida $envio, array $dados): ?JsonResponse
    {
        if ($intervalo->alerta === null || $envio->semInternet || AlertasAntesDaBatida::confirmou($dados)) {
            return null;
        }

        return $this->json(['success' => false, 'alerta' => $intervalo->alerta, 'message' => $intervalo->alerta]);
    }

    /**
     * A recusa da batida que vem de outro escritório, antes do home office e da cerca (spec seguranca-b-jornada §3.3):
     * a chave "Registra ponto eletrônico" desligada aqui e o horário de trabalho de hoje em outro escritório onde a
     * pessoa registra ponto. Null quando segue. A saída e a ida ao repouso ficam livres das duas só em dois casos
     * (`ponto-esquecimento-nao-trava.md` R1): quando fecham um turno aberto aqui, ou quando são soltas de verdade, sem
     * entrada no dia neste escritório (a solta leva o aviso da entrada que faltou). Com entrada no dia e o turno daqui
     * fechado, valem as regras de sempre. O ponto aberto em outro escritório não recusa: vira aviso (R2).
     */
    private function recusaEntreEscritorios(
        User $user,
        Tenant $tenant,
        string $tipo,
        \DateTimeImmutable $agora,
        ChoqueDeJornadaEntreEscritorios $choque,
        RegistroPontoRepository $registros,
    ): ?string {
        if (in_array($tipo, [RegistroPonto::TIPO_SAIDA, RegistroPonto::TIPO_REPOUSO], true)
            && ($this->turnoAberto->estaAberto($user, $tenant, $agora) || !$this->temEntradaNoDia($user, $tenant, $agora, $registros))) {
            return null;
        }
        if ($this->chaveDoPonto->estaDesligada($user, $tenant)) {
            return ChaveDoPontoEletronico::MENSAGEM_DESLIGADA;
        }

        return $choque->naBatida($user, $tenant, $agora);
    }

    /**
     * Os ids das justificativas ainda em análise: só nelas a aba "Minhas Justificativas" mostra o "Excluir".
     *
     * @param iterable<JustificativaPonto> $justificativas
     *
     * @return list<int>
     */
    private static function idsEmAnalise(iterable $justificativas): array
    {
        $ids = [];
        foreach ($justificativas as $justificativa) {
            if ($justificativa->getStatus() === 'pendente' && $justificativa->getId() !== null) {
                $ids[] = $justificativa->getId();
            }
        }

        return $ids;
    }

    /**
     * Para onde a janela "Registro de esquecimento" ou "Justificar falta" volta: o mês e a aba que a pessoa via (fora do
     * form). Sem o mês, justificar um dia do mês anterior joga de volta no mês corrente; sem a aba, volta sempre para
     * Pendências, e o desenho (L768, `addJ`) só fecha a janela. Aba que não existe fica fora (cai no padrão).
     *
     * @return array{competencia?: string, aba?: string}
     */
    private function voltaDaJanela(Request $request): array
    {
        $volta = [];
        $competencia = (string) $request->request->get('competencia', '');
        if (preg_match('/^\d{4}-\d{2}$/D', $competencia) === 1) {
            $volta['competencia'] = $competencia;
        }
        $aba = (string) $request->request->get('aba', '');
        if ($aba !== self::ABAS_DO_CARTAO[0] && in_array($aba, self::ABAS_DO_CARTAO, true)) {
            $volta['aba'] = $aba;
        }

        return $volta;
    }

    /**
     * A aba do cartão que abre ao entrar (`?aba=`). Pendências é o padrão (P37); aba que não existe ou que a pessoa não
     * vê cai no padrão. A aba escondida (Comprovantes com o comprovante desligado, Banco antes da E17) cai no padrão
     * no template (`_abas.html.twig`), que sabe quais desenhou.
     */
    private function abaAberta(Request $request): string
    {
        $aba = (string) $request->query->get('aba', '');

        return in_array($aba, self::ABAS_DO_CARTAO, true) ? $aba : self::ABAS_DO_CARTAO[0];
    }

    /**
     * Meses da lista da Folha na /ponto (P23 e D7, spec ponto-desenho-0811 §5 E2): do mês do início da contagem
     * NESTE escritório (a primeira batida daqui, ou o abono que a antecede) até o mês corrente do servidor, o mais
     * recente primeiro; o mês sem batida no meio também entra. Sem contagem, só o mês corrente. Outro escritório não
     * entra porque o início da contagem é por escritório.
     *
     * @return list<array{valor: string, label: string, ano: int, mes: int}>
     */
    private function competenciasDaFolha(\DateTimeImmutable $agora, ?\DateTimeImmutable $inicioContagem): array
    {
        $mes = $agora->modify('first day of this month')->setTime(0, 0);
        $primeiro = $inicioContagem?->modify('first day of this month')->setTime(0, 0) ?? $mes;

        $competencias = [];
        do {
            $competencias[] = [
                'valor' => $mes->format('Y-m'),
                'label' => $mes->format('m/Y'),
                'ano'   => (int) $mes->format('Y'),
                'mes'   => (int) $mes->format('n'),
            ];
            $mes = $mes->modify('-1 month');
        } while ($mes >= $primeiro);

        return $competencias;
    }

    /**
     * @return array{cargaHorariaSemanal: int, fonte: string}|null
     */
    private function resolverJornadaInfo(?JornadaColaborador $jornada, ?JornadaTenant $jornadaTenant): ?array
    {
        if ($jornada !== null && !$jornada->getBlocos()->isEmpty()) {
            $carga = $jornada->getCargaHorariaSemanal()
                ?? $jornadaTenant?->getCargaHorariaSemanal()
                ?? 0;
            return ['cargaHorariaSemanal' => $carga, 'fonte' => 'personalizada'];
        }

        if ($jornadaTenant !== null && !$jornadaTenant->getBlocos()->isEmpty()) {
            return ['cargaHorariaSemanal' => $jornadaTenant->getCargaHorariaSemanal(), 'fonte' => 'escritorio'];
        }

        return null;
    }

    private function resolverDuracaoJornadaDiaria(
        ?JornadaColaborador $jornada,
        \DateTimeImmutable $data,
        ?JornadaTenant $jornadaTenant,
    ): ?int {
        $batidas = $this->jornadaResolver->resolverBatidasEsperadasHoje($jornada, $data, $jornadaTenant);

        $entradaHorario = null;
        $saidaHorario   = null;
        foreach ($batidas as $batida) {
            if ($batida['tipo'] === 'entrada') {
                $entradaHorario = $batida['horario'];
            }
            if ($batida['tipo'] === 'saida') {
                $saidaHorario = $batida['horario'];
            }
        }

        if ($entradaHorario === null || $saidaHorario === null) {
            return null;
        }

        [$hEnt, $mEnt] = array_map('intval', explode(':', $entradaHorario));
        [$hSai, $mSai] = array_map('intval', explode(':', $saidaHorario));

        return ($hSai * 60 + $mSai) - ($hEnt * 60 + $mEnt);
    }

    /**
     * A folha do mês em PDF (E16): o "Ver PDF" (`inline=1`, P89) e o "Baixar PDF". Cada PDF é uma emissão com código BJF
     * próprio (decisão 2), devolvido no cabeçalho `X-Codigo-Folha` para o aviso da tela; sem o segredo do HMAC, sai sem
     * código e sem o cabeçalho. Quem pode: {@see self::pessoaDaFolhaExportada()}. O desenho custa CPU e memória: 10 PDFs
     * por minuto por usuário (`ponto_folha_pdf`), contados só depois da permissão. Só a competência fora do calendário
     * responde 400 (decisão 25); a falha do desenho ou do PDF, com a folha já gravada, é erro do servidor.
     */
    #[Route('/exportar-folha-pdf', name: 'ponto_exportar_pdf')]
    public function exportarFolhaPdf(
        Request $request,
        GerarPdfDaFolhaUseCase $gerarPdf,
        UserRepository $userRepository,
        RateLimiterFactoryInterface $pontoFolhaPdfLimiter,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $tenant = $this->assertAccess($user);
        $pessoa = $this->pessoaDaFolhaExportada($request, $user, $tenant, $userRepository);
        if (!$pontoFolhaPdfLimiter->create((string) $user->getId())->consume()->isAccepted()) {
            return new Response(self::MUITOS_PEDIDOS_DE_FOLHA, Response::HTTP_TOO_MANY_REQUESTS, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $mes = max(1, min(12, (int) $request->query->get('mes', (new \DateTimeImmutable())->format('m'))));
        $ano = max(1970, (int) $request->query->get('ano', (new \DateTimeImmutable())->format('Y')));
        try {
            $folha = $gerarPdf->executar($tenant, $pessoa, $ano, $mes, $user);
        } catch (CompetenciaDaFolhaInvalidaException) {
            throw new BadRequestHttpException('Competência inválida.');
        }

        return new Response($folha->pdf, Response::HTTP_OK, $this->cabecalhosDoPdf($folha, $request->query->getBoolean('inline', false)));
    }

    /**
     * A pessoa da folha exportada em PDF ou XLSX: quem pede, ou o `userId` de outro colaborador (P89). O Master exporta
     * a de qualquer um, como antes. Para os outros, quem não tem vínculo ativo NESTE escritório responde 404, igual ao
     * `userId` que não existe (recurso de outro escritório é 404, nunca 403, para não revelar que existe; decisão 25), e
     * a pessoa daqui só sai para quem tem `admin.users.manage` (sem a permissão, 403). Nada é gasto antes: nem cota, nem
     * emissão, nem desenho.
     */
    private function pessoaDaFolhaExportada(Request $request, User $user, Tenant $tenant, UserRepository $userRepository): User
    {
        $targetUserId = (int) $request->query->get('userId', 0);
        if ($targetUserId <= 0 || $targetUserId === (int) $user->getId()) {
            return $user;
        }
        $targetUser = $userRepository->find($targetUserId);
        if ($targetUser === null) {
            throw $this->createNotFoundException('Usuário para exportação não encontrado.');
        }
        if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return $targetUser;
        }
        if (!$this->userTenantRepository->existeVinculoAtivo($targetUser, $tenant)) {
            throw $this->createNotFoundException('Usuário para exportação não encontrado.');
        }
        if (!$this->permissionChecker->canAdminister($user, $tenant, 'admin.users.manage')) {
            throw $this->createAccessDeniedException('Sem permissão para exportar folha deste usuário.');
        }

        return $targetUser;
    }

    /** @return array<string, string> */
    private function cabecalhosDoPdf(PdfDaFolhaOutput $folha, bool $inline): array
    {
        $cabecalhos = [
            'Content-Type'           => 'application/pdf',
            'Content-Disposition'    => sprintf('%s; filename="%s"', $inline ? 'inline' : 'attachment', $folha->nomeDoArquivo),
            'Cache-Control'          => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ];
        if ($folha->codigo !== null) {
            $cabecalhos['X-Codigo-Folha'] = $folha->codigo;
        }

        return $cabecalhos;
    }

    #[Route('/exportar-folha-xlsx', name: 'ponto_exportar_xlsx')]
    public function exportarFolhaXlsx(
        Request $request,
        RegistroPontoRepository $repository,
        FeriadoRepository $feriadoRepository,
        JustificativaPontoRepository $justificativaRepository,
        FolhaPontoBuilder $folhaPontoBuilder,
        FolhaPontoXlsxExporter $xlsxExporter,
        DadosDaFolhaExportada $dadosDaFolha,
        UserRepository $userRepository
    ): StreamedResponse {
        /** @var User $user */
        $user = $this->getUser();
        $tenant = $this->assertAccess($user);
        $targetUser = $this->pessoaDaFolhaExportada($request, $user, $tenant, $userRepository);

        $mes = max(1, min(12, (int) $request->query->get('mes', (new \DateTimeImmutable())->format('m'))));
        $ano = max(1970, (int) $request->query->get('ano', (new \DateTimeImmutable())->format('Y')));

        /** @var list<MarcacaoDoPonto> $batidas as efetivas (E10) */
        $batidas = $repository->findByUserAndCompetencia($targetUser, $tenant, $ano, $mes);
        $feriados = $feriadoRepository->findByTenant($tenant);
        $justificativasDoMes = $justificativaRepository->findByUserAndCompetenciaIndexed($targetUser, $tenant, $ano, $mes);
        $jornadaTenantXlsx = $tenant->getJornadaTenant();

        $inicioMes = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $ano, $mes));
        $fimMes = $inicioMes->modify('last day of this month')->setTime(23, 59, 59);
        // Cada dia usa a versão da jornada que valia nele; o cabeçalho, a do último dia do mês (spec ponto-jornada-vigencia §3.2).
        // As linhas usam a jornada da folha da /ponto e do banco (E17): sem versão no dia, a do escritório.
        $jornada   = $this->jornadas->historicoDe($targetUser, $tenant)?->vigenteEm($fimMes);
        $inicioContagemXlsx = $this->inicioContagemResolver->resolver($targetUser, $tenant);
        $folhaRows = $folhaPontoBuilder->buildRows($inicioMes, $fimMes, $batidas, true, false, $folhaPontoBuilder->jornadaDaFolha($targetUser, $tenant), $feriados, $justificativasDoMes, $jornadaTenantXlsx, $inicioContagemXlsx);

        $nomeUsuario = trim((string) $targetUser->getFullName());
        if ($nomeUsuario === '') {
            $nomeUsuario = (string) $targetUser->getUserIdentifier();
        }

        $nomeArquivo = sprintf('folha_ponto_%s-%02d-%04d.xlsx', NomeDoArquivoDaFolha::doXlsx($nomeUsuario), $mes, $ano);

        $dados = $dadosDaFolha->montar($targetUser, $tenant, $ano, $mes, $folhaRows, $feriados, $jornada, $jornadaTenantXlsx, $inicioContagemXlsx);

        return $xlsxExporter->exportar($dados['folhaRows'], $nomeArquivo, $dados);
    }

    private function assertAccess(User $user): Tenant
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createAccessDeniedException('Sem tenant selecionado.');
        }
        if (!$this->permissionChecker->canAccessModule($user, $tenant, 'ponto')) {
            throw $this->createAccessDeniedException('Sem acesso ao módulo Ponto Eletrônico.');
        }

        return $tenant;
    }
}
