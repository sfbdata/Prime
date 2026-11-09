<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\BlocoJornadaColaborador;
use App\Ponto\Entity\Feriado;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\LancamentoHorasPagas;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Enum\TipoJustificativa;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Etapa E2 do desenho novo do Ponto Eletrônico (desenho 16, `docs/specs/ponto-desenho-0811.md`; decisões de
 * 09/11/2026): a Folha de Ponto no molde do desenho, sobre o motor de hoje.
 *
 * O arranjo é conferido por filho direto (`>`). Também ficam travados o que saiu (contando zero), os textos literais, o
 * filtro do P25 (dia sem batida fora da tela, com o saldo igual ao de antes), os selos do dia (P27) e da justificativa
 * (P31, "Em análise" com a ampulheta na coluna Justificativa pelo P30 a), os meses desde o início da contagem (P23, D7)
 * e a ficha do colaborador, que ganha o mesmo molde e continua com todos os dias do mês (P60).
 *
 * Classes do desenho traduzidas para o prefixo da tela: `bjpT` = `pe-tab`, `resp` = `pe-resp`, `bjpLin` = `pe-lin`,
 * `bjpMais` = `pe-mais`, `bjpSm` = `pe-sm`, `bjpPl` = `pe-pl`, `bjpVazia` = `pe-vazia`, `larga` = `pe-larga`,
 * `toda` = `pe-toda`. O celular (até 760 px) é CSS: aqui ficam as classes que ele usa; os valores, em
 * `FolhaDoDesenhoCssTest`.
 */
#[Group('ponto')]
#[CoversClass(PontoController::class)]
#[CoversClass(TenantController::class)]
final class FolhaDoDesenhoTest extends JusPrimeWebTestCase
{
    private const REGIAO = '.pe-page > .pe-grade > .pe-dir > #ponto-regiao-folha:first-child';
    private const FOLHA = self::REGIAO . ' > section.pe-folha[data-screen-label="Folha de Ponto"]:first-child';
    private const BARRA = self::FOLHA . ' > div.pe-folha-barra:first-child';
    private const TABELA = self::FOLHA . ' > div.pe-folha-rolagem:nth-child(2) > table.pe-tab.pe-resp';
    private const LINHAS = self::TABELA . ' > tbody > tr.pe-lin';
    private const COLUNAS = ['Dia', 'Semana', 'Entrada', 'Repouso', 'Retorno', 'Saída', 'Trabalhado', 'Saldo dia', 'Saldo mês', 'Justificativa'];

    private KernelBrowser $client;
    private Tenant $x;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->x = $this->tenant('Escritório X');
        $this->user = $this->user('folha_desenho_');
        $this->vincularColaborador($this->user, $this->x);
    }

    #[TestDox('A barra da folha segue o desenho: tabela, título sem o mês, lista do mês sem rótulo, espaçador e Baixar PDF por último')]
    public function testArranjoDaBarra(): void
    {
        $tela = $this->tela();

        self::assertCount(1, $tela->filter(self::FOLHA), 'a folha é a primeira coisa da coluna da direita');
        self::assertSame(
            ['i', 'span.pe-folha-titulo', 'select.pe-folha-mes', 'span.pe-folha-espaco', 'button', 'button', 'button'],
            $tela->filter(self::BARRA . ' > *')->each(static fn (Crawler $no): string => $no->nodeName() . (in_array($no->nodeName(), ['span', 'select'], true) ? '.' . explode(' ', (string) $no->attr('class'))[0] : '')),
            'a barra na ordem do desenho; os botões que o desenho não tem (P88 e P89, ficam) antes do Baixar PDF'
        );
        self::assertCount(1, $tela->filter(self::BARRA . ' > i.bi.bi-table:first-child'));
        self::assertSame('Folha de Ponto', trim($tela->filter(self::BARRA . ' > span.pe-folha-titulo')->text()));

        $mes = $tela->filter(self::BARRA . ' > select[aria-label="Competência"]');
        self::assertCount(1, $mes, 'a lista do mês é filha direta da barra');
        self::assertSame('competencia', $mes->attr('name'));
        self::assertCount(0, $tela->filter(self::BARRA . ' label'), 'sem o rótulo "Mês"');
        self::assertCount(0, $tela->filter(self::BARRA . ' form'), 'a lista não fica dentro de um formulário na barra');
        self::assertCount(1, $tela->filter(sprintf('form#%s[method="get"]', $mes->attr('form'))), 'o formulário do mês mora fora da barra');

        self::assertSame(
            ['Ver PDF', 'Baixar XLSX', 'Baixar PDF'],
            $tela->filter(self::BARRA . ' > button.pe-folha-btn')->each(static fn (Crawler $no): string => trim($no->text()))
        );
        $baixar = $tela->filter(self::BARRA . ' > button:last-child');
        self::assertCount(1, $baixar->filter('i.bi-file-earmark-pdf'), 'Baixar PDF é o último da barra');
        self::assertStringContainsString('/ponto/exportar-folha-pdf?ano=', (string) $baixar->attr('data-folha-baixar'), 'P87: baixa direto');
        self::assertStringNotContainsString('inline', (string) $baixar->attr('data-folha-baixar'));
        self::assertStringContainsString('/ponto/exportar-folha-xlsx?ano=', (string) $tela->filter(self::BARRA . ' > button.pe-folha-btn')->eq(1)->attr('data-folha-baixar'), 'P88: o XLSX fica');
        self::assertSame('#modalPreviewFolhaPonto', $tela->filter(self::BARRA . ' > button.pe-folha-btn')->eq(0)->attr('data-bs-target'), 'P89: ver antes de baixar fica');
    }

    #[TestDox('A tabela tem as dez colunas do desenho, nesta ordem, e o dia completo e em ordem mostra o saldo em texto verde')]
    public function testTabelaELinhaDoDesenho(): void
    {
        $this->jornadaDeOitoHoras();
        $dia = $this->diaUtilDoMesAnterior(1);
        $this->diaCompleto($dia, '18:00:00');

        $tela = $this->tela($dia->format('Y-m'));

        self::assertSame(self::COLUNAS, $tela->filter(self::TABELA . ' > thead > tr > th')->each(static fn (Crawler $no): string => trim($no->text())));

        $linha = $tela->filter(sprintf('%s[data-chave-dia="%s"]', self::LINHAS, $dia->format('Y-m-d')));
        self::assertCount(1, $linha);
        self::assertSame($dia->format('d'), trim($linha->filter('td.pe-larga:first-child > b')->text()));
        self::assertSame($this->nomeDaSemana($dia), trim($linha->filter('td.pe-larga:nth-child(2)')->text()));
        self::assertNull($linha->filter('td:nth-child(1)')->attr('data-r'), 'Dia e Semana não têm nome de campo no celular');
        self::assertNull($linha->filter('td:nth-child(2)')->attr('data-r'));
        self::assertSame(
            ['Entrada', 'Repouso', 'Retorno', 'Saída', 'Trabalhado', 'Saldo dia', 'Saldo mês', 'Justificativa'],
            $linha->filter('td[data-r]')->each(static fn (Crawler $no): ?string => $no->attr('data-r'))
        );
        self::assertSame('08:00:00', trim($linha->filter('td[data-r="Entrada"]:nth-child(3)')->text()), 'hora como gravada');
        self::assertSame('18:00:00', trim($linha->filter('td[data-r="Saída"]:nth-child(6)')->text()));
        self::assertSame('9h00m', trim($linha->filter('td[data-r="Trabalhado"]:nth-child(7)')->text()));
        self::assertSame('+1h00m', trim($linha->filter('td.pe-larga[data-r="Saldo dia"]:nth-child(8) > b.pe-pl.pe-cor--pos')->text()));
        self::assertSame('+1h00m', trim($linha->filter('td.pe-sm[data-r="Saldo mês"]:nth-child(9).pe-cor--pos')->text()));
        self::assertCount(1, $linha->filter('td.pe-vazia[data-r="Justificativa"]:nth-child(10)'), 'sem justificativa, a célula some no celular');
    }

    #[TestDox('P25: o dia sem batida sai da tela e continua descontando; o rodapé é o mês inteiro, a conta do banco')]
    public function testDiaSemBatidaSaiDaTelaEContinuaNoSaldo(): void
    {
        $this->jornadaDeOitoHoras();
        [$d0, $d1, $d2, $d3, $d4] = array_map(fn (int $n): \DateTimeImmutable => $this->diaUtilDoMesAnterior($n), [1, 2, 3, 4, 5]);
        $this->diaCompleto($d0, '18:00:00');
        $this->diaCompleto($d2, '17:00:00');
        $this->justificativa($d4, 'atestado_medico', 'abonado');
        $this->lancamento((int) $d0->format('Y'), (int) $d0->format('n'), 60);

        $tela = $this->tela($d0->format('Y-m'));

        self::assertSame(
            [$d0->format('Y-m-d'), $d2->format('Y-m-d')],
            $tela->filter(self::LINHAS)->each(static fn (Crawler $no): ?string => $no->attr('data-chave-dia')),
            'só os dias com batida; o dia só com atestado também sai (filtro do desenho)'
        );
        self::assertCount(0, $tela->filter(sprintf('tr[data-chave-dia="%s"], tr[data-chave-dia="%s"]', $d1->format('Y-m-d'), $d3->format('Y-m-d'))));
        self::assertSame('+1h00m', trim($tela->filter(sprintf('tr[data-chave-dia="%s"] > td.pe-sm', $d0->format('Y-m-d')))->text()));
        $saldoMes = $tela->filter(sprintf('tr[data-chave-dia="%s"] > td.pe-sm', $d2->format('Y-m-d')));
        self::assertSame('-7h00m', trim($saldoMes->text()), 'o dia sem batida que sumiu ainda desconta as 8 h dele');
        self::assertStringContainsString('pe-cor--neg', (string) $saldoMes->attr('class'));

        // O rodapé é a conta do banco para o mês (E17): -15h00m até o atestado de d4 e mais as faltas dos dias úteis depois
        // dele, que a tela não mostra. Até a E17 parava na última linha que a folha montava (d4), e a soma dos meses da
        // folha não fechava com o banco. Filtrar a tela não pode mudar o número.
        $faltasDepois = 0;
        for ($dia = $d4->modify('+1 day'); $dia->format('m') === $d4->format('m'); $dia = $dia->modify('+1 day')) {
            $faltasDepois += (int) $dia->format('N') <= 5 ? 1 : 0;
        }
        $saldo = -15 - 8 * $faltasDepois;
        $this->assertTotais($tela, sprintf('%dh00m', $saldo), '+1h00m', sprintf('%dh00m', $saldo + 1));
        self::assertStringContainsString($d4->format('d/m/Y'), $tela->filter('.pe-dir')->text(), 'o atestado continua visível em Minhas Justificativas');
    }

    #[TestDox('P27: selos do Saldo dia no molde do desenho (fora de sequência, registro incompleto com a dica, em andamento)')]
    public function testSelosDoSaldoDia(): void
    {
        $fora = $this->diaUtilDoMesAnterior(1);
        $incompleto = $this->diaUtilDoMesAnterior(2);
        foreach (['entrada' => '09:00:00', 'repouso' => '12:00:00', 'retorno' => '11:00:00', 'saida' => '18:00:00'] as $tipo => $hora) {
            $this->batida($fora, $tipo, $hora);
        }
        $this->batida($incompleto, 'entrada', '09:00:00');
        // Antes da primeira tela: ela limpa o EntityManager e solta o usuário e o escritório.
        $this->batida(new \DateTimeImmutable('today'), 'entrada', '00:01:00');

        $tela = $this->tela($fora->format('Y-m'));

        $seloFora = $this->linha($tela, $fora)->filter('td.pe-larga[data-r="Saldo dia"] > span.pe-tag.pe-tag--erro');
        self::assertCount(1, $seloFora->filter('i.bi-exclamation-octagon'));
        self::assertSame('Fora de sequência', trim($seloFora->text()));

        $seloIncompleto = $this->linha($tela, $incompleto)->filter('td.pe-larga[data-r="Saldo dia"] > span.pe-tag.pe-tag--alerta');
        self::assertCount(1, $seloIncompleto->filter('i.bi-exclamation-triangle'));
        self::assertSame('Registro incompleto', trim($seloIncompleto->text()));
        self::assertStringContainsString('Faltam batidas neste dia', (string) $seloIncompleto->attr('title'), 'a dica do sistema fica');

        $hoje = $this->linha($this->tela(), new \DateTimeImmutable('today'));
        $seloHoje = $hoje->filter('td.pe-larga[data-r="Saldo dia"] > span.pe-tag.pe-tag--info');
        self::assertCount(1, $seloHoje->filter('i.bi-clock'));
        self::assertSame('Em andamento', trim($seloHoje->text()));
    }

    #[TestDox('P31 e P30 a: selos da justificativa; "Em análise" com a ampulheta na coluna Justificativa, nunca na célula da batida')]
    public function testSelosDaJustificativa(): void
    {
        [$abonada, $analise, $recusada, $naoAbonada] = array_map(fn (int $n): \DateTimeImmutable => $this->diaUtilDoMesAnterior($n), [1, 2, 3, 4]);
        foreach ([$abonada, $analise, $recusada, $naoAbonada] as $dia) {
            $this->batida($dia, 'entrada', '09:00:00');
        }
        $parcial = $this->justificativa($abonada, 'atestado_medico', 'abonado');
        $parcial->setAbonoParcial(true)->setHoraInicioAbono(new \DateTime('08:00'))->setHoraFimAbono(new \DateTime('12:00'));
        $this->em()->flush();
        $this->justificativa($analise, 'esquecimento_registro', 'pendente')->setTipoRegistroEsquecido('saida')->setHoraRegistroEsquecido(new \DateTime('18:05'));
        $this->em()->flush();
        $this->justificativa($recusada, 'atestado_medico', 'rejeitado');
        $this->justificativa($naoAbonada, 'falta_nao_justificada', 'abonado');

        $tela = $this->tela($abonada->format('Y-m'));

        $esperado = [
            [$abonada, 'pe-just--abonada', 'bi-check-circle', 'Abonada'],
            [$analise, 'pe-just--analise', 'bi-hourglass-split', 'Em análise'],
            [$recusada, 'pe-just--recusada', 'bi-x-circle', 'Recusada'],
            [$naoAbonada, 'pe-just--nao-abonada', 'bi-x-circle', 'Não abonada'],
        ];
        foreach ($esperado as [$dia, $classe, $icone, $texto]) {
            $selo = $this->linha($tela, $dia)->filter(sprintf('td[data-r="Justificativa"]:nth-child(10) > span.pe-just.%s', $classe));
            self::assertCount(1, $selo->filter('i.' . $icone), $texto);
            self::assertSame($texto, trim($selo->text()));
        }
        self::assertSame(TipoJustificativa::AtestadoMedico->label(), $this->linha($tela, $abonada)->filter('span.pe-just')->attr('title'), 'o tipo fica na dica do selo');
        self::assertSame('08:00 às 12:00', trim($this->linha($tela, $abonada)->filter('td[data-r="Justificativa"] > span.pe-folha-sub')->text()), 'o horário do abono parcial fica');

        $linhaAnalise = $this->linha($tela, $analise);
        self::assertCount(0, $linhaAnalise->filter('td[data-r="Entrada"] i.bi-hourglass-split, td[data-r="Repouso"] i.bi-hourglass-split, td[data-r="Retorno"] i.bi-hourglass-split, td[data-r="Saída"] i.bi-hourglass-split'), 'a ampulheta saiu da célula');
        self::assertCount(0, $linhaAnalise->filter('td[data-r="Saída"] button'), 'a saída já pedida não ganha outro "+"');
        self::assertCount(1, $linhaAnalise->filter('td[data-r="Repouso"] > button.pe-mais > i.bi-plus-circle'));
        self::assertSame('Enviar registro de esquecimento: Repouso', $linhaAnalise->filter('td[data-r="Repouso"] > button.pe-mais')->attr('aria-label'));
        self::assertCount(0, $tela->filter(self::FOLHA . ' .badge'), 'os selos são do desenho, não do Bootstrap');
    }

    #[TestDox('Saldo dia com buraco na sequência: o dia sem repouso ou sem entrada não quebra a folha e compara com o horário anterior que existe')]
    public function testSequenciaComBuraco(): void
    {
        [$semRepousoFora, $semRepousoEmOrdem, $semEntrada] = array_map(fn (int $n): \DateTimeImmutable => $this->diaUtilDoMesAnterior($n), [1, 2, 3]);
        foreach (['entrada' => '08:00:00', 'retorno' => '13:00:00', 'saida' => '12:00:00'] as $tipo => $hora) {
            $this->batida($semRepousoFora, $tipo, $hora);
        }
        foreach (['entrada' => '08:00:00', 'retorno' => '13:00:00', 'saida' => '18:00:00'] as $tipo => $hora) {
            $this->batida($semRepousoEmOrdem, $tipo, $hora);
        }
        foreach (['repouso' => '12:00:00', 'retorno' => '13:00:00', 'saida' => '18:00:00'] as $tipo => $hora) {
            $this->batida($semEntrada, $tipo, $hora);
        }

        $tela = $this->tela($semRepousoFora->format('Y-m'));

        $seloFora = $this->linha($tela, $semRepousoFora)->filter('td.pe-larga[data-r="Saldo dia"] > span.pe-tag.pe-tag--erro');
        self::assertSame('Fora de sequência', trim($seloFora->text()), 'a saída antes do retorno, sem repouso no meio');
        self::assertCount(0, $this->linha($tela, $semRepousoEmOrdem)->filter('span.pe-tag--erro'), 'sem repouso, mas em ordem');
        self::assertCount(0, $this->linha($tela, $semEntrada)->filter('span.pe-tag--erro'), 'sem entrada, mas em ordem');
    }

    #[TestDox('Esquecimento pendente num dia em que outra justificativa vence a escolha: "Em análise" ao lado do selo escolhido, e a saída pedida sem "+"')]
    public function testEmAnaliseAoLadoDaJustificativaEscolhida(): void
    {
        $dia = $this->diaUtilDoMesAnterior(1);
        $this->batida($dia, 'entrada', '09:00:00');
        $this->justificativa($dia, 'esquecimento_registro', 'pendente')->setTipoRegistroEsquecido('saida')->setHoraRegistroEsquecido(new \DateTime('18:05'));
        $this->em()->flush();
        // Abonada que perdoa o déficit vence a escolha do dia (`indexarPorDia`, precedência 2).
        $this->justificativa($dia, 'atestado_medico', 'abonado');

        $linha = $this->linha($this->tela($dia->format('Y-m')), $dia);

        self::assertSame(
            ['Abonada', 'Em análise'],
            $linha->filter('td[data-r="Justificativa"] > span.pe-just')->each(static fn (Crawler $no): string => trim($no->text()))
        );
        self::assertCount(1, $linha->filter('td[data-r="Justificativa"] > span.pe-just.pe-just--analise > i.bi-hourglass-split'));
        self::assertCount(0, $linha->filter('td[data-r="Saída"] button'), 'a saída já pedida não ganha outro "+"');
    }

    /**
     * Até a E17 a célula ficava vazia até a saída. O desenho (L157) mostra o acumulado até ontem no dia em andamento:
     * o mesmo número e a mesma cor da linha de ontem (ontem trabalhado, para a linha aparecer na /ponto, que só mostra
     * dia com batida). No dia 1º não há ontem no mês, e a célula fica vazia (o caso certo para qualquer data está em
     * FolhaPontoSaldoDoDiaEmAndamentoTest).
     */
    #[TestDox('Dia em andamento: o "Saldo mês" de hoje repete o de ontem até a saída (desenho L157); no dia 1º, vazio')]
    public function testSaldoMesDoDiaEmAndamentoRepeteOAcumuladoDeOntem(): void
    {
        $this->jornadaDeOitoHoras();
        $this->diaCompleto($this->diaUtilDoMesAnterior(1), '18:00:00');
        $hoje = new \DateTimeImmutable('today');
        $ontem = $hoje->modify('-1 day');
        if ($ontem->format('Y-m') === $hoje->format('Y-m')) {
            $this->diaCompleto($ontem, '18:00:00');
        }
        $this->batida($hoje, 'entrada', '00:01:00');

        $tela = $this->tela();
        $saldoMesDeHoje = $this->linha($tela, $hoje)->filter('td.pe-sm[data-r="Saldo mês"]');

        if ($hoje->format('j') === '1') {
            self::assertSame('', trim($saldoMesDeHoje->text()));
            self::assertStringContainsString('pe-vazia', (string) $saldoMesDeHoje->attr('class'));

            return;
        }
        $saldoMesDeOntem = $this->linha($tela, $ontem)->filter('td.pe-sm[data-r="Saldo mês"]');
        self::assertNotSame('', trim($saldoMesDeOntem->text()), 'controle: ontem tem Saldo mês');
        self::assertSame(trim($saldoMesDeOntem->text()), trim($saldoMesDeHoje->text()));
        self::assertSame($saldoMesDeOntem->attr('class'), $saldoMesDeHoje->attr('class'), 'a mesma cor de ontem');
    }

    #[TestDox('O que saiu conta zero na /ponto: cartão, lista do Bootstrap, menu Visualizar / Exportar, tabela listrada do Bootstrap, linhas coloridas e cabeçalhos antigos')]
    public function testOQueSaiuContaZero(): void
    {
        $sabado = $this->sabadoDoMesAnterior();
        $feriado = $this->diaUtilDoMesAnterior(1);
        $this->batida($sabado, 'entrada', '09:00:00');
        $this->batida($feriado, 'entrada', '09:00:00');
        $this->feriado($feriado);

        $tela = $this->tela($feriado->format('Y-m'));
        self::assertCount(2, $tela->filter(self::LINHAS), 'controle: o sábado e o feriado estão na folha');

        foreach (['.card', '.card-header', 'h3.card-title', '.form-select', '.btn-group', '.dropdown-toggle', '.dropdown-menu', 'table.table', '.table-striped', 'tr.table-secondary', 'tr.table-warning', '.badge', 'label'] as $saiu) {
            self::assertCount(0, $tela->filter(self::REGIAO . ' ' . $saiu), $saiu . ' saiu');
        }
        $texto = $tela->filter(self::FOLHA)->text();
        foreach (['Dia do Mês', 'Dia da Semana', 'Saldo Dia', 'Saldo do Mês', 'Visualizar / Exportar', 'Folha de Ponto - ', 'FERIADO', 'Pendente', 'Rejeitada', '—', '–'] as $saiu) {
            self::assertStringNotContainsString($saiu, $texto);
        }
        self::assertSame($this->nomeDaSemana($feriado), trim($this->linha($tela, $feriado)->filter('td:nth-child(2)')->getNode(0)?->firstChild?->textContent ?? ''), 'a semana sem o "FERIADO - " do motor');
        self::assertCount(1, $this->linha($tela, $feriado)->filter('td:nth-child(2) > span.pe-tag.pe-tag--info > i.bi-calendar-event'), 'feriado trabalhado: o selo do desenho, na Semana (sistema, fica)');
    }

    #[TestDox('Folha vazia: o cabeçalho continua e a linha única diz "Nenhuma batida nesta competência."')]
    public function testFolhaVazia(): void
    {
        $tela = $this->tela();

        self::assertCount(10, $tela->filter(self::TABELA . ' > thead > tr > th'));
        $vazia = $tela->filter(self::TABELA . ' > tbody > tr > td.pe-toda.pe-folha-vazia[colspan="10"]');
        self::assertCount(1, $vazia);
        self::assertSame('Nenhuma batida nesta competência.', trim($vazia->text()));
        self::assertCount(0, $tela->filter(self::LINHAS));
    }

    #[TestDox('P23 e D7: os meses vão do primeiro da contagem neste escritório até o corrente, o mais recente primeiro; outro escritório não entra')]
    public function testMesesDesdeOInicioDaContagem(): void
    {
        $mesAtual = new \DateTimeImmutable('first day of this month');
        $this->batida($mesAtual->modify('-3 months')->modify('+9 days'), 'entrada', '09:00:00');
        $y = $this->tenant('Escritório Y');
        $this->vincularColaborador($this->user, $y);
        $this->batidaEm($y, $mesAtual->modify('-5 months')->modify('+9 days'), 'entrada', '09:00:00');

        $mes = $this->tela()->filter(self::BARRA . ' > select > option');
        $esperado = array_map(static fn (int $n): \DateTimeImmutable => $mesAtual->modify(sprintf('-%d months', $n)), [0, 1, 2, 3]);
        self::assertSame(array_map(static fn (\DateTimeImmutable $m): string => $m->format('Y-m'), $esperado), $mes->each(static fn (Crawler $no): ?string => $no->attr('value')));
        self::assertSame(array_map(static fn (\DateTimeImmutable $m): string => $m->format('m/Y'), $esperado), $mes->each(static fn (Crawler $no): string => trim($no->text())));
        self::assertSame($mesAtual->format('Y-m'), $mes->filter('[selected]')->attr('value'), 'abre no mês corrente do servidor');

        $semBatida = $this->tela($mesAtual->modify('-2 months')->format('Y-m'));
        self::assertSame($mesAtual->modify('-2 months')->format('Y-m'), $semBatida->filter(self::BARRA . ' > select > option[selected]')->attr('value'), 'mês sem batida dentro da contagem abre');
        $antes = $this->tela($mesAtual->modify('-4 months')->format('Y-m'));
        self::assertSame($mesAtual->format('Y-m'), $antes->filter(self::BARRA . ' > select > option[selected]')->attr('value'), 'antes da contagem daqui cai no mês corrente');
    }

    #[TestDox('Ficha do colaborador: o mesmo molde, com todos os dias do mês, a jornada na barra, o "+" do gestor e o feriado no selo do desenho')]
    public function testFichaComOMoldeETodosOsDias(): void
    {
        $admin = $this->user('folha_desenho_admin_');
        $this->vincularAdmin($admin, $this->x);
        $this->jornadaDeOitoHoras();
        // O feriado vem depois da primeira batida: antes dela o dia está fora da contagem e não é apurado.
        $dia = $this->diaUtilDoMesAnterior(1);
        $feriado = $this->diaUtilDoMesAnterior(3);
        $this->batida($dia, 'entrada', '09:00:00');
        $this->feriado($feriado);

        $this->logarComTenant($this->client, $admin, $this->x);
        $ficha = $this->client->request('GET', sprintf('/tenant/%d/user/%d/edit-role?competencia=%s', $this->x->getId(), $this->user->getId(), $dia->format('Y-m')));
        self::assertResponseIsSuccessful();

        $folha = '#tab-ponto > section.pe-folha[data-screen-label="Folha de Ponto"]';
        self::assertCount(1, $ficha->filter($folha));
        self::assertCount(1, $ficha->filter('#tab-ponto > link[rel="stylesheet"][href*="ponto-eletronico.css"]'), 'a ficha carrega o CSS da folha');
        self::assertSame(sprintf('Batidas de ponto - %s', $dia->format('m/Y')), trim($ficha->filter($folha . ' > div.pe-folha-barra > span.pe-folha-titulo')->text()));
        self::assertSame('40h00m/sem (Personalizada)', trim($ficha->filter($folha . ' > div.pe-folha-barra > span.pe-folha-jornada')->text()), 'o selo da jornada fica (sistema)');
        self::assertCount(1, $ficha->filter($folha . ' > div.pe-folha-barra > select[aria-label="Competência"]'));

        $linhas = $ficha->filter($folha . ' > div.pe-folha-rolagem > table.pe-tab.pe-resp > tbody > tr.pe-lin');
        self::assertCount((int) $dia->format('t'), $linhas, 'a ficha mostra todos os dias do mês (o filtro do P25 é só da /ponto)');
        $linhaDoDia = $this->linha($ficha, $dia);
        self::assertCount(1, $linhaDoDia->filter('td[data-r="Saída"] > button.pe-mais[onclick*="abrirModalAddBatida"] > i.bi-plus-circle'), 'o "+" do gestor no molde do desenho');
        self::assertCount(1, $linhaDoDia->filter('td[data-r="Entrada"] > span.pe-folha-celula > a.pe-folha-hora'));
        $seloFeriado = $this->linha($ficha, $feriado)->filter('td.pe-larga[data-r="Saldo dia"] > span.pe-tag.pe-tag--info');
        self::assertCount(1, $seloFeriado->filter('i.bi-calendar-event'));
        self::assertSame('Feriado', trim($seloFeriado->text()));

        foreach (['.card', '.table-striped', 'table.table', '.form-select', '.dropdown-toggle', '.badge'] as $saiu) {
            self::assertCount(0, $ficha->filter('#tab-ponto ' . $saiu), $saiu . ' saiu da ficha');
        }
    }

    #[TestDox('Celular por classe: Dia, Semana e Saldo dia ocupam duas colunas; Trabalhado zero e Justificativa vazia somem')]
    public function testCelularPorClasse(): void
    {
        $this->jornadaDeOitoHoras();
        $dia = $this->diaUtilDoMesAnterior(1);
        $this->batida($dia, 'entrada', '09:00:00');

        $linha = $this->linha($this->tela($dia->format('Y-m')), $dia);

        self::assertSame(['1', '2', '8'], $linha->filter('td.pe-larga')->each(static fn (Crawler $no): string => (string) (count($no->previousAll()) + 1)));
        self::assertCount(1, $linha->filter('td.pe-vazia[data-r="Trabalhado"]'), 'Trabalhado zero some no celular');
        self::assertCount(1, $linha->filter('td.pe-vazia[data-r="Justificativa"]'));
        self::assertCount(0, $linha->filter('td.pe-vazia[data-r="Entrada"], td.pe-vazia[data-r="Saldo dia"]'));
    }

    private function assertTotais(Crawler $tela, string $saldo, string $pagas, string $total): void
    {
        $totais = $tela->filter(self::FOLHA . ' > div.pe-folha-totais:last-child > div.pe-folha-total');
        self::assertSame(['Saldo do mês', 'Horas pagas', 'Total do mês'], $totais->each(static fn (Crawler $no): string => trim($no->filter('span.pe-folha-total-rotulo')->text())));
        self::assertSame([$saldo, $pagas, $total], $totais->each(static fn (Crawler $no): string => trim($no->filter('b.pe-folha-total-valor')->text())));
    }

    private function tela(?string $competencia = null): Crawler
    {
        $this->logarComTenant($this->client, $this->user, $this->x);
        $this->em()->clear();
        $crawler = $this->client->request('GET', '/ponto/' . ($competencia !== null ? '?competencia=' . $competencia : ''));
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function linha(Crawler $tela, \DateTimeInterface $dia): Crawler
    {
        $linha = $tela->filter(sprintf('table.pe-tab.pe-resp > tbody > tr.pe-lin[data-chave-dia="%s"]', $dia->format('Y-m-d')));
        self::assertCount(1, $linha, 'a linha do dia ' . $dia->format('Y-m-d') . ' deveria estar na folha');

        return $linha;
    }

    private function nomeDaSemana(\DateTimeInterface $dia): string
    {
        return ['Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado', 'Domingo'][(int) $dia->format('N') - 1];
    }

    /** N-ésimo dia útil do mês anterior: sempre no passado, fora do fim de semana. */
    private function diaUtilDoMesAnterior(int $ordinal): \DateTimeImmutable
    {
        $dia = new \DateTimeImmutable('first day of last month');
        $achados = 0;
        while (true) {
            if ((int) $dia->format('N') <= 5 && ++$achados === $ordinal) {
                return $dia->setTime(0, 0);
            }
            $dia = $dia->modify('+1 day');
        }
    }

    private function sabadoDoMesAnterior(): \DateTimeImmutable
    {
        $dia = new \DateTimeImmutable('first day of last month');
        while ((int) $dia->format('N') !== 6) {
            $dia = $dia->modify('+1 day');
        }

        return $dia->setTime(0, 0);
    }

    private function diaCompleto(\DateTimeImmutable $dia, string $saida): void
    {
        foreach (['entrada' => '08:00:00', 'repouso' => '12:00:00', 'retorno' => '13:00:00', 'saida' => $saida] as $tipo => $hora) {
            $this->batida($dia, $tipo, $hora);
        }
    }

    private function batida(\DateTimeImmutable $dia, string $tipo, string $hora): void
    {
        $this->batidaEm($this->x, $dia, $tipo, $hora);
    }

    private function batidaEm(Tenant $tenant, \DateTimeImmutable $dia, string $tipo, string $hora): void
    {
        $registro = new RegistroPonto();
        $registro->setUser($this->user);
        $registro->setTenant($tenant);
        $registro->setTipo($tipo);
        $registro->setDataHora(new \DateTime($dia->format('Y-m-d') . ' ' . $hora));
        $registro->setSedeNomeSnapshot('Teste');
        $this->em()->persist($registro);
        $this->em()->flush();
    }

    private function justificativa(\DateTimeImmutable $dia, string $tipo, string $status): JustificativaPonto
    {
        $justificativa = new JustificativaPonto();
        $justificativa->setUser($this->user);
        $justificativa->setTenant($this->x);
        $justificativa->setData(new \DateTime($dia->format('Y-m-d')));
        $justificativa->setTipo($tipo);
        $justificativa->setStatus($status);
        $this->em()->persist($justificativa);
        $this->em()->flush();

        return $justificativa;
    }

    private function feriado(\DateTimeImmutable $dia): void
    {
        $feriado = new Feriado();
        $feriado->setNome('Feriado de teste');
        $feriado->setData(new \DateTime($dia->format('Y-m-d')));
        $feriado->setRecorrente(false);
        $feriado->setTenant($this->x);
        $this->em()->persist($feriado);
        $this->em()->flush();
    }

    private function lancamento(int $ano, int $mes, int $minutos): void
    {
        $lancamento = new LancamentoHorasPagas();
        $lancamento->setTenant($this->x);
        $lancamento->setUser($this->user);
        $lancamento->setAno($ano);
        $lancamento->setMes($mes);
        $lancamento->setMinutos($minutos);
        $lancamento->setMotivo('Lançamento de teste');
        $lancamento->setCriadoPor($this->user);
        $lancamento->setCriadoEm(new \DateTimeImmutable());
        $this->em()->persist($lancamento);
        $this->em()->flush();
    }

    /** Segunda a sexta, 08:00 a 12:00 e 13:00 a 17:00 (8 h por dia, 40 h na semana), valendo desde antes do mês anterior. */
    private function jornadaDeOitoHoras(): void
    {
        $jornada = (new JornadaColaborador())->setUser($this->user)->setTenant($this->x)->setCargaHorariaSemanal(2400);
        $jornada->setValeAPartirDe(new \DateTimeImmutable('first day of -6 months'));
        $jornada->addBloco((new BlocoJornadaColaborador())->setDiasSemana([1, 2, 3, 4, 5])->setEntrada('08:00')
            ->setRepouso('12:00')->setRetorno('13:00')->setSaida('17:00')->setMinutosBloco(480));
        $this->em()->persist($jornada);
        $this->em()->flush();
    }

    private function vincularColaborador(User $user, Tenant $tenant): void
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
        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($papel);
        $em->persist($vinculo);
        $em->flush();
    }

    private function vincularAdmin(User $admin, Tenant $tenant): void
    {
        $papel = new TenantRole();
        $papel->setTenant($tenant);
        $papel->setName('Administrador ' . uniqid());
        $papel->setIsSystem(true);
        $this->em()->persist($papel);
        $vinculo = new UserTenant($admin, $tenant);
        $vinculo->setTenantRole($papel);
        $this->em()->persist($vinculo);
        $this->em()->flush();
    }

    private function tenant(string $nome): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName($nome . ' ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    private function user(string $prefixo): User
    {
        $user = new User();
        $user->setEmail($prefixo . uniqid() . '@test.com');
        $user->setFullName('Folha do desenho');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
