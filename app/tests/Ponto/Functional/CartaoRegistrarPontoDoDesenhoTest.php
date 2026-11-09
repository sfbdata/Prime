<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\BlocoJornadaColaborador;
use App\Ponto\Entity\Feriado;
use App\Ponto\Entity\HomeOfficeConfig;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\JornadaTenant;
use App\Ponto\Entity\RegistroPonto;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Etapa E1 do desenho novo do Ponto Eletrônico (desenho 16, `docs/specs/ponto-desenho-0811.md`; decisões do dono de
 * 09/11/2026): a /ponto em duas colunas, com o cartão "Registrar Ponto" à esquerda, nos componentes do desenho e sobre
 * o motor de hoje.
 *
 * O arranjo é conferido por filho direto (`>`): "existe em algum lugar da página" não prova o desenho. Também ficam
 * travados o que saiu (contando zero), os textos literais do desenho, o P7 (tipo batido travado e dia completo
 * cinza), a jornada vinda da pessoa (D1), a hora oficial do servidor (D9) e a faixa da última pendência (P14), que só
 * mostra a deste escritório.
 *
 * PHPUnit não roda o JavaScript: o que o JS redesenha (relógio, aviso do dia, caixa da localização) é smoke do dono.
 */
#[Group('ponto')]
#[CoversClass(PontoController::class)]
final class CartaoRegistrarPontoDoDesenhoTest extends JusPrimeWebTestCase
{
    private const CARTAO = '.pe-page > .pe-grade > .pe-esq > section.pe-registrar[data-screen-label="Registrar Ponto"]';
    private const CORPO = self::CARTAO . ' > .pe-corpo';

    private KernelBrowser $client;
    private Tenant $x;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->x = $this->tenant('Escritório X');
        $this->user = $this->user();
        $this->vincular($this->x);
    }

    #[TestDox('A página tem o título e a grade de duas colunas: o cartão à esquerda; folha, pendências e justificativas à direita')]
    public function testGradeDeDuasColunas(): void
    {
        $tela = $this->tela();

        self::assertSame('Ponto Eletrônico', trim($tela->filter('.pe-page > h1.pe-titulo')->text()));
        $colunas = $tela->filter('.pe-page > .pe-grade > div');
        self::assertCount(2, $colunas, 'duas colunas, nada além');
        self::assertStringContainsString('pe-esq', (string) $colunas->eq(0)->attr('class'));
        self::assertStringContainsString('pe-dir', (string) $colunas->eq(1)->attr('class'));
        self::assertCount(1, $tela->filter(self::CARTAO), 'o cartão é filho direto da coluna da esquerda');
        self::assertCount(1, $tela->filter('.pe-grade > .pe-dir > #ponto-regiao-folha'));
        self::assertCount(1, $tela->filter('.pe-grade > .pe-dir > section.pe-abas > #ponto-regiao-pendencias'), 'as pendências saíram da coluna do botão (E3: na aba)');
        self::assertCount(1, $tela->filter('.pe-grade > .pe-dir > #ponto-regiao-folha + section.pe-abas'));
    }

    #[TestDox('O cartão segue o desenho: barra com a digital e o banco de horas; corpo na ordem do desenho')]
    public function testArranjoDoCartao(): void
    {
        $tela = $this->tela();

        $barra = self::CARTAO . ' > .pe-registrar-barra';
        self::assertCount(1, $tela->filter($barra . ' > i.bi-fingerprint:first-child'));
        self::assertSame('Registrar Ponto', trim($tela->filter($barra . ' > .pe-registrar-titulo')->text()));
        self::assertSame('Banco de horas', trim($tela->filter($barra . ' > #ponto-banco-horas:last-child > span.pe-banco-rotulo')->text()));
        self::assertSame('0h', trim($tela->filter($barra . ' > #ponto-banco-horas > span + b.pe-banco-valor')->text()), 'P2: o zero do desenho');

        self::assertSame(
            ['pe-relogio', 'excedente-hoje', 'ponto-tipo', 'gps-status', 'ponto-aviso-dia', 'btn-bater-ponto', 'batida-aviso', 'btn-atualizar-pagina', 'pe-rodape'],
            $tela->filter(self::CORPO . ' > *')->each(static fn (Crawler $no): string => (string) ($no->attr('id') ?: explode(' ', (string) $no->attr('class'))[0])),
            'o corpo do cartão na ordem do desenho'
        );

        $relogio = $tela->filter(self::CORPO . ' > .pe-relogio > div');
        self::assertSame(['clock-label', 'clock', 'ponto-hora-oficial'], $relogio->each(static fn (Crawler $no): ?string => $no->attr('id')));
        self::assertSame('Aguardando entrada', trim($relogio->eq(0)->text()));

        self::assertCount(1, $tela->filter(self::CORPO . ' > #ponto-tipo > .pe-tipo-rotulo > span.pe-obrigatorio'));
        self::assertCount(1, $tela->filter(self::CORPO . ' > #ponto-tipo > #ponto-tipos[role="radiogroup"]'));
        self::assertCount(4, $tela->filter(self::CORPO . ' > #ponto-tipo > #ponto-tipos > button[role="radio"]'));
        self::assertCount(1, $tela->filter(self::CORPO . ' > #ponto-tipo > input[type="hidden"]#tipo-registro'));
        self::assertCount(1, $tela->filter(self::CORPO . ' > #gps-status > button#btn-gps-atualizar[aria-label="Atualizar localização"]'));
        self::assertCount(1, $tela->filter(self::CORPO . ' > button#btn-bater-ponto > svg:first-child'), 'a digital do desenho no botão');
        self::assertCount(1, $tela->filter(self::CORPO . ' > .pe-rodape:last-child > button#btn-justificar-falta:first-child > i.bi-calendar-check'));
    }

    #[TestDox('Os quatro tipos lado a lado, com a hora de cada um no próprio botão e o próximo já marcado (P6 e P8)')]
    public function testTiposComAHora(): void
    {
        $tela = $this->tela();

        $tipos = $tela->filter(self::CORPO . ' > #ponto-tipo > #ponto-tipos > button[role="radio"]');
        self::assertSame(['entrada', 'repouso', 'retorno', 'saida'], $tipos->each(static fn (Crawler $no): ?string => $no->attr('data-tipo')));
        self::assertSame(['Entrada', 'Repouso', 'Retorno', 'Saída'], $tipos->each(static fn (Crawler $no): string => trim($no->filter('span')->eq(0)->text())));
        self::assertSame(
            ['--:--:--', '--:--:--', '--:--:--', '--:--:--'],
            $tela->filter('#ponto-tipos > button > span + span.pe-tipo-hora')->each(static fn (Crawler $no): string => trim($no->text()))
        );
        self::assertSame(['true', 'false', 'false', 'false'], $tipos->each(static fn (Crawler $no): ?string => $no->attr('aria-checked')));
        self::assertSame(['0', '-1', '-1', '-1'], $tipos->each(static fn (Crawler $no): ?string => $no->attr('tabindex')), 'tabindex móvel: só o marcado no Tab');
        self::assertCount(0, $tela->filter('#ponto-tipos > button[disabled]'), 'sem batida, nenhum tipo travado');
        self::assertSame('entrada', $tela->filter('#tipo-registro')->attr('value'));
        self::assertSame('Bater Ponto: Entrada', trim($tela->filter('#btn-bater-ponto')->text()));
    }

    #[TestDox('P7: o tipo já batido fica verde e sem clique; o próximo livre vem marcado')]
    public function testTipoBatidoFicaTravado(): void
    {
        $this->batida('entrada', new \DateTimeImmutable('today 00:00:01'));
        $tela = $this->tela();

        $entrada = $tela->filter('#ponto-tipos > button[data-tipo="entrada"]');
        self::assertNotNull($entrada->attr('disabled'), 'tipo batido não aceita clique');
        self::assertStringContainsString('pe-tipo-btn--feito', (string) $entrada->attr('class'));
        self::assertSame('false', $entrada->attr('aria-checked'));
        self::assertSame('00:00:01', trim($entrada->filter('.pe-tipo-hora')->text()));
        self::assertCount(3, $tela->filter('#ponto-tipos > button:not([disabled])'), 'os outros seguem escolhíveis');
        self::assertSame('true', $tela->filter('#ponto-tipos > button[data-tipo="repouso"]')->attr('aria-checked'));
        self::assertSame('0', $tela->filter('#ponto-tipos > button[data-tipo="repouso"]')->attr('tabindex'), 'o Tab cai no tipo marcado');

        $botao = $tela->filter('#btn-bater-ponto');
        self::assertSame('Bater Ponto: Repouso', trim($botao->text()));
        self::assertStringNotContainsString('pe-bater--concluida', (string) $botao->attr('class'));
        self::assertSame('Em jornada', trim($tela->filter('#clock-label')->text()));
    }

    #[TestDox('P7: com os quatro tipos batidos, o botão vira "Jornada de hoje concluída", cinza e sem clique')]
    public function testDiaCompletoDeixaOBotaoCinza(): void
    {
        foreach (['entrada' => '00:00:01', 'repouso' => '00:00:02', 'retorno' => '00:00:03', 'saida' => '00:00:04'] as $tipo => $hora) {
            $this->batida($tipo, new \DateTimeImmutable('today ' . $hora));
        }
        $tela = $this->tela();

        self::assertCount(4, $tela->filter('#ponto-tipos > button[disabled].pe-tipo-btn--feito'));
        self::assertCount(0, $tela->filter('#ponto-tipos > button[aria-checked="true"]'));
        self::assertSame('', $tela->filter('#tipo-registro')->attr('value'));

        $botao = $tela->filter(self::CORPO . ' > button#btn-bater-ponto');
        self::assertSame('Jornada de hoje concluída', trim($botao->text()));
        self::assertStringContainsString('pe-bater--concluida', (string) $botao->attr('class'));
        self::assertNotNull($botao->attr('disabled'));
        self::assertSame('Jornada encerrada', trim($tela->filter('#clock-label')->text()));
    }

    #[TestDox('P12: o mínimo do aviso do intervalo segue a Jornada do escritório; com a validação do repouso desligada, vai 0 e o aviso mostra só o tempo do intervalo')]
    public function testAvisoDoIntervaloSegueAChaveDoEscritorio(): void
    {
        $aviso = self::CORPO . ' > #ponto-aviso-dia';
        self::assertSame('60', $this->tela()->filter($aviso)->attr('data-intervalo-minimo-minutos'), 'sem Jornada do escritório, o padrão de hoje');

        $jornada = (new JornadaTenant())->setTenant($this->x)->setValidacaoRepousoHabilitada(true)->setMinimoMinutosRepouso(45);
        $this->x->setJornadaTenant($jornada);
        $this->em()->persist($jornada);
        $this->em()->flush();
        self::assertSame('45', $this->tela()->filter($aviso)->attr('data-intervalo-minimo-minutos'), 'chave ligada: o mínimo do escritório');

        // Recarregada: a requisição anterior limpa o EntityManager e a instância acima fica solta.
        $this->em()->find(JornadaTenant::class, $jornada->getId())?->setValidacaoRepousoHabilitada(false);
        $this->em()->flush();
        self::assertSame('0', $this->tela()->filter($aviso)->attr('data-intervalo-minimo-minutos'), 'chave desligada: o aviso sem o mínimo');
    }

    #[TestDox('Em repouso, o cartão já traz o que o intervalo ao vivo e a saída prevista usam: a hora da batida de repouso, a meta, a escala e o aviso do dia')]
    public function testRepousoTrazOInicioDoIntervaloNoCartao(): void
    {
        $jornada = (new JornadaColaborador())->setUser($this->user)->setTenant($this->x);
        $jornada->addBloco((new BlocoJornadaColaborador())->setDiasSemana([1, 2, 3, 4, 5, 6, 7])->setEntrada('08:00')
            ->setRepouso('12:00')->setRetorno('14:00')->setSaida('18:00')->setMinutosBloco(480));
        $this->em()->persist($jornada);
        $this->em()->flush();

        $this->batida('entrada', new \DateTimeImmutable('today 00:00:01'));
        $this->batida('repouso', new \DateTimeImmutable('today 00:00:02'));
        $tela = $this->tela();

        self::assertSame('Em repouso', trim($tela->filter('#clock-label')->text()));
        // O JS conta o intervalo a partir daqui, sem busca nova (`CartaoDoPontoNoJsTest::testIntervaloAoVivoNosTresTextos`).
        $estado = json_decode((string) $tela->filter('#clock')->attr('data-ponto-do-dia'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($estado);
        self::assertSame('00:00:02', $estado['repouso'] ?? null, 'o início do intervalo é a hora da batida de repouso');
        self::assertSame('00:00:01', $estado['entrada'] ?? null);

        // A saída prevista no repouso (`saidaPrevista`) usa a meta do dia e a escala; o aviso é desenhado pelo JS.
        self::assertSame('480', $tela->filter(self::CORPO . ' > #excedente-hoje')->attr('data-meta-dia-minutos'));
        self::assertStringContainsString('const duracaoMinutos = 600;', (string) $this->client->getResponse()->getContent());
        self::assertCount(1, $tela->filter(self::CORPO . ' > #ponto-aviso-dia'), 'o aviso do dia, filho direto do corpo do cartão');
    }

    #[TestDox('O que saiu com o desenho conta zero: card do AdminLTE, select, lista de batidas, previsão, contador do repouso, botão de som')]
    public function testOQueSaiuContaZero(): void
    {
        $tela = $this->tela();
        $cartao = $tela->filter(self::CARTAO);
        $texto = $cartao->text();

        foreach (['.pe-esq .card', '.pe-esq .small-box', 'select#tipo-registro', '#batidas-de-hoje', '#contador-repouso', '#previsao-saida', '#gps-status.alert', '#batida-aviso.alert', '.pe-esq .bi-calendar-week', '.pe-esq .bi-volume-up', '.pe-esq .bi-volume-mute', '.pe-esq [class*="btn-outline"]'] as $seletor) {
            self::assertCount(0, $tela->filter($seletor), 'voltou: ' . $seletor);
        }
        foreach (['Suas batidas de hoje', 'ainda não registrada', 'Previsão de saída', 'Tempo trabalhado', 'Jornada semanal:', 'Justificar Falta', 'Retorno disponível'] as $antigo) {
            self::assertStringNotContainsString($antigo, $texto, 'voltou: ' . $antigo);
        }
        self::assertStringNotContainsString('—', $texto, 'sem travessão no cartão');
        self::assertStringNotContainsString('–', $texto, 'sem travessão no cartão');
    }

    #[TestDox('Textos literais do desenho no cartão')]
    public function testTextosDoDesenho(): void
    {
        $tela = $this->tela();
        $texto = $tela->filter(self::CARTAO)->text();

        foreach (['Registrar Ponto', 'Banco de horas', 'Aguardando entrada', 'Hora oficial', 'Tipo de registro', 'Obtendo a sua localização...', 'Justificar falta'] as $literal) {
            self::assertStringContainsString($literal, $texto);
        }
        self::assertSame('Atualizar e conferir minhas batidas', trim($tela->filter('#btn-atualizar-pagina')->text()));
    }

    #[TestDox('D9: a hora oficial é a do servidor, entregue em milissegundos com o fuso, nunca a do aparelho')]
    public function testHoraOficialDoServidor(): void
    {
        $antes = (int) floor(microtime(true) * 1000);
        $tela = $this->tela();
        $depois = (int) ceil(microtime(true) * 1000);

        $hora = $tela->filter(self::CORPO . ' > .pe-relogio > #ponto-hora-oficial');
        $agoraMs = (int) $hora->attr('data-agora-ms');
        self::assertGreaterThanOrEqual($antes - 1000, $agoraMs);
        self::assertLessThanOrEqual($depois + 1000, $agoraMs);
        self::assertNotSame('', (string) $hora->attr('data-fuso'));
        // Virada do dia (decisão de 09/11/2026): a próxima meia-noite no fuso do servidor, não a do aparelho.
        self::assertSame((new \DateTimeImmutable('tomorrow'))->format('Uv'), $hora->attr('data-proxima-virada-ms'));
        self::assertGreaterThan($agoraMs, (int) $hora->attr('data-proxima-virada-ms'));
        self::assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', trim($hora->filter('b#ponto-hora-oficial-hora')->text()));
        self::assertMatchesRegularExpression('/^[a-zçá-]+, \d{2}\/\d{2}\/\d{4}$/u', trim($hora->filter('#ponto-hora-oficial-data')->text()));
    }

    #[TestDox('D1: a jornada semanal do rodapé é a da pessoa, com o selo da fonte; sem jornada, o trecho não aparece')]
    public function testJornadaDaPessoa(): void
    {
        $semJornada = $this->tela();
        self::assertCount(0, $semJornada->filter('.pe-rodape > .pe-jornada'), 'sem jornada, nada de carga inventada');
        self::assertStringNotContainsString('44h00m', $semJornada->filter(self::CARTAO)->text());

        $jornada = (new JornadaColaborador())->setUser($this->user)->setTenant($this->x)->setCargaHorariaSemanal(1800);
        $jornada->addBloco((new BlocoJornadaColaborador())->setDiasSemana([1, 2, 3, 4, 5])->setEntrada('08:00')
            ->setRepouso('12:00')->setRetorno('13:00')->setSaida('15:00')->setMinutosBloco(360));
        $this->em()->persist($jornada);
        $this->em()->flush();

        $tela = $this->tela();
        $rodape = self::CORPO . ' > .pe-rodape > span.pe-jornada:last-child';
        self::assertSame('30h00m', trim($tela->filter($rodape . ' > b')->text()));
        self::assertSame('Personalizada', trim($tela->filter($rodape . ' > b + span.pe-selo--personalizada')->text()));
        self::assertStringNotContainsString('44h00m', $tela->filter(self::CARTAO)->text());
    }

    #[TestDox('Saída prevista: a tela recebe a duração da escala de hoje com o almoço (08:00 a 12:00 e 14:00 a 18:00 dá 600 min, entrada 08:00 prevê 18:00)')]
    public function testSaidaPrevistaUsaADuracaoDaEscala(): void
    {
        $jornada = (new JornadaColaborador())->setUser($this->user)->setTenant($this->x);
        $jornada->addBloco((new BlocoJornadaColaborador())->setDiasSemana([1, 2, 3, 4, 5, 6, 7])->setEntrada('08:00')
            ->setRepouso('12:00')->setRetorno('14:00')->setSaida('18:00')->setMinutosBloco(480));
        $this->em()->persist($jornada);
        $this->em()->flush();

        $this->batida('entrada', new \DateTimeImmutable('today 08:00:00'));
        $this->tela();

        // A conta (entrada + duração, antes do repouso batido) é do JS: `CartaoDoPontoNoJsTest::testSaidaPrevistaPelaEscala`.
        // 08:00 + 600 min = 18:00, e não 08:00 + 8 h + 1 h do protótipo (17:00).
        self::assertStringContainsString('const duracaoMinutos = 600;', (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('Feriado hoje: a tela recebe meta 0, e o JS não mostra saída prevista nem o lembrete, mesmo com a escala')]
    public function testFeriadoSemSaidaPrevista(): void
    {
        $jornada = (new JornadaColaborador())->setUser($this->user)->setTenant($this->x);
        $jornada->addBloco((new BlocoJornadaColaborador())->setDiasSemana([1, 2, 3, 4, 5, 6, 7])->setEntrada('08:00')
            ->setRepouso('12:00')->setRetorno('14:00')->setSaida('18:00')->setMinutosBloco(480));
        $feriado = new Feriado();
        $feriado->setNome('Feriado de teste');
        $feriado->setData(new \DateTime('today'));
        $feriado->setRecorrente(false);
        $feriado->setTenant($this->x);
        $this->em()->persist($jornada);
        $this->em()->persist($feriado);
        $this->em()->flush();

        $this->batida('entrada', new \DateTimeImmutable('today 08:00:00'));
        $tela = $this->tela();

        // O JS lê a meta deste atributo; com 0, `saidaPrevista` devolve null antes do ramo da escala
        // (`CartaoDoPontoNoJsTest::testSaidaPrevistaPelaEscala`). A escala continua chegando: quem esconde é a meta.
        self::assertSame('0', $tela->filter(self::CORPO . ' > #excedente-hoje')->attr('data-meta-dia-minutos'));
        self::assertStringContainsString('const duracaoMinutos = 600;', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('d-none', (string) $tela->filter(self::CORPO . ' > #ponto-aviso-dia')->attr('class'));
    }

    #[TestDox('Home Office hoje: a caixa da localização diz que não exige posição e não tem o botão de atualizar')]
    public function testHomeOffice(): void
    {
        $config = new HomeOfficeConfig();
        $config->setUser($this->user);
        $config->setTenant($this->x);
        $config->setDiasSemana([1, 2, 3, 4, 5, 6, 7]);
        $config->setAtivo(true);
        $this->em()->persist($config);
        $this->em()->flush();

        $tela = $this->tela();

        $caixa = $tela->filter(self::CORPO . ' > #gps-status.pe-caixa--info');
        self::assertSame('Home Office hoje: o ponto será registrado sem exigir localização.', trim($caixa->filter('span.pe-caixa-texto')->text()));
        self::assertCount(1, $caixa->filter('i.bi-house-laptop'));
        self::assertCount(0, $tela->filter('#btn-gps-atualizar'));
    }

    #[TestDox('P14: antes da entrada, a faixa mostra a última pendência deste escritório, com "Regularizar"')]
    public function testFaixaDaUltimaPendencia(): void
    {
        $dia = $this->diaUtilAtras(3);
        $this->batida('entrada', $dia->setTime(8, 0));

        $tela = $this->tela();

        $faixa = $tela->filter(self::CORPO . ' > #ponto-ultima-pendencia');
        self::assertCount(1, $faixa);
        self::assertSame(
            sprintf('Última pendência: %s, falta a saída.', $dia->format('d/m/Y')),
            trim($faixa->filter('span.pe-pendencia-texto')->text())
        );
        $regularizar = $faixa->filter('span.pe-pendencia-texto + button#btn-regularizar-pendencia');
        self::assertSame('Regularizar', trim($regularizar->text()));
        self::assertSame($dia->format('Y-m-d'), $regularizar->attr('data-dia'));
        self::assertSame('saida', $regularizar->attr('data-tipo'));
        self::assertStringContainsString('esquecimento=' . $dia->format('Y-m-d') . ':saida', (string) $regularizar->attr('data-url'));
        self::assertStringContainsString('competencia=' . $dia->format('Y-m'), (string) $regularizar->attr('data-url'));
    }

    #[TestDox('P14: com a entrada de hoje batida, a faixa não aparece')]
    public function testFaixaSomeDepoisDaEntrada(): void
    {
        $this->batida('entrada', $this->diaUtilAtras(3)->setTime(8, 0));
        $this->batida('entrada', new \DateTimeImmutable('today 00:00:01'));

        self::assertCount(0, $this->tela()->filter('#ponto-ultima-pendencia'));
    }

    #[TestDox('P14 entre escritórios: a pendência de outro escritório não vai para a faixa, nem o nome dele')]
    public function testFaixaNaoMostraPendenciaDeOutroEscritorio(): void
    {
        $y = $this->tenant('Escritório Y');
        $this->vincular($y);
        $diaY = $this->diaUtilAtras(4);
        $this->batidaEm($y, 'entrada', $diaY->setTime(9, 0));
        // Público do aviso em X (R7): um dia completo aqui nos últimos 30 dias.
        $diaX = $this->diaUtilAtras(5);
        foreach (['entrada' => 8, 'repouso' => 12, 'retorno' => 13, 'saida' => 17] as $tipo => $hora) {
            $this->batida($tipo, $diaX->setTime($hora, 0));
        }

        $tela = $this->tela();

        self::assertCount(1, $tela->filter('#pendencias-ponto'), 'controle: a lista de pendências mostra a de Y');
        self::assertCount(0, $tela->filter('#ponto-ultima-pendencia'), 'a faixa é só deste escritório');
        self::assertStringNotContainsString((string) $y->getName(), $tela->filter(self::CARTAO)->text());
    }

    private function tela(): Crawler
    {
        $this->logarComTenant($this->client, $this->user, $this->x);
        $crawler = $this->client->request('GET', '/ponto/');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** Um dia útil `$dias` atrás; caindo no fim de semana, recua até um dia útil. */
    private function diaUtilAtras(int $dias): \DateTimeImmutable
    {
        $dia = (new \DateTimeImmutable('today'))->modify(sprintf('-%d days', $dias));
        while ((int) $dia->format('N') >= 6) {
            $dia = $dia->modify('-1 day');
        }

        return $dia;
    }

    private function batida(string $tipo, \DateTimeImmutable $quando): void
    {
        $this->batidaEm($this->x, $tipo, $quando);
    }

    private function batidaEm(Tenant $tenant, string $tipo, \DateTimeImmutable $quando): void
    {
        $registro = new RegistroPonto();
        $registro->setUser($this->user);
        $registro->setTenant($tenant);
        $registro->setTipo($tipo);
        $registro->setDataHora(\DateTime::createFromImmutable($quando));
        $this->em()->persist($registro);
        $this->em()->flush();
    }

    private function vincular(Tenant $tenant): void
    {
        $em = $this->em();
        $papel = new TenantRole();
        $papel->setTenant($tenant);
        $papel->setName('Papel ' . uniqid());
        $papel->setIsSystem(false);
        $em->persist($papel);
        $permissao = $em->getRepository(Permission::class)->findOneBy(['code' => 'modules.ponto.view']);
        if ($permissao === null) {
            $permissao = new Permission();
            $permissao->setCode('modules.ponto.view');
            $permissao->setDescription('modules.ponto.view');
            $permissao->setGroup('modules');
            $em->persist($permissao);
        }
        $ligacao = new TenantRolePermission();
        $ligacao->setTenantRole($papel);
        $ligacao->setPermission($permissao);
        $em->persist($ligacao);
        $papel->getTenantRolePermissions()->add($ligacao);
        $vinculo = new UserTenant($this->user, $tenant);
        $vinculo->setTenantRole($papel);
        $em->persist($vinculo);
        $em->flush();
    }

    private function tenant(string $nome): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName($nome . ' ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    private function user(): User
    {
        $user = new User();
        $user->setEmail('cartao_ponto_' . uniqid() . '@test.com');
        $user->setFullName('Cartão do ponto');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
