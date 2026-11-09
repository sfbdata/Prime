<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\BancoDeHorasOutput;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\BlocoJornadaColaborador;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\LancamentoHorasPagas;
use App\Ponto\Entity\RegistroPonto;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * E17 do desenho do Ponto (spec ponto-desenho-0811, P2, P51 a, P52; desenho 16 L106 e L200 a L208): o número do topo
 * vem dos lotes e ganha o triângulo acima do limite; a aba "Banco de Horas" tem os três quadros, a faixa do limite, a
 * tabela de lotes e a nota da lei. Arranjo por filho direto. O dia em andamento repete o "Saldo mês" de ontem também
 * na ficha do gestor. O banco usa a jornada da folha da /ponto (item 12 da E17): a soma dos meses da folha é o banco.
 *
 * Nos cenários de lotes a pessoa não tem batida nem abono (sem início da contagem, a folha não soma dia nenhum): os
 * lotes saem só de horas pagas, para os números não dependerem do dia em que a suíte roda.
 */
#[Group('ponto')]
#[CoversClass(PontoController::class)]
#[CoversClass(TenantController::class)]
final class BancoDeHorasNaTelaTest extends JusPrimeWebTestCase
{
    use CenarioDeJustificativaTrait;

    private const CARTAO = '.pe-page > .pe-grade > .pe-dir > #ponto-regiao-folha + section.pe-abas';
    private const PAINEL = self::CARTAO . ' > div#ponto-painel-banco.pe-abas-painel[role="tabpanel"]';
    private const TOPO = 'section.pe-registrar > div.pe-registrar-barra > span#ponto-banco-horas.pe-banco';

    private KernelBrowser $client;
    private Tenant $escritorio;
    private User $eu;
    private \DateTimeImmutable $mes;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->escritorio = $this->escritorio('Escritório Banco');
        $this->eu = $this->pessoa('banco');
        $this->vincularColaborador($this->eu, $this->escritorio);
        $this->mes = new \DateTimeImmutable('first day of this month midnight');
    }

    #[TestDox('Acima do limite: aba aberta pelo endereço, três quadros, faixa amarela, lotes do mais antigo ao mais novo, nota e triângulo no topo')]
    public function testAcimaDoLimite(): void
    {
        $antigo = $this->mes->modify('-12 months');
        $passado = $this->mes->modify('-1 month');
        $this->horasPagas($this->escritorio, $antigo, 600);   // 10h de um ano atrás: vencido
        $this->horasPagas($this->escritorio, $passado, 9000); // 150h do mês passado: no prazo
        $this->horasPagas($this->escritorio, $this->mes, -60); // 1h compensada neste mês, abate o mais antigo
        $outro = $this->escritorio('Outro');
        $this->vincularColaborador($this->eu, $outro);
        $this->horasPagas($outro, $passado, 99_000);          // outro escritório: não entra

        $tela = $this->tela('?aba=banco');

        self::assertCount(1, $tela->filter(self::CARTAO . ' > div.pe-abas-barra > button#ponto-aba-banco[aria-selected="true"][tabindex="0"]'));
        $painel = $tela->filter(self::PAINEL . ':not([hidden])');
        self::assertCount(1, $painel, 'o endereço abre a aba do banco');
        self::assertCount(1, $tela->filter(self::CARTAO . ' > .pe-abas-painel:not([hidden])'), 'um painel aberto só');

        $quadros = $painel->filter('div.pe-abas-painel > div.pe-bh-kpis:first-child > div.pe-bh-kpi');
        self::assertSame(['SALDO ATUAL', 'LIMITE CONFIGURADO', 'PRAZO DE COMPENSAÇÃO'], $quadros->each(static fn (Crawler $q): string => $q->filter('div.pe-bh-kpi > div.pe-bh-kpi-rotulo:first-child')->text()));
        self::assertSame(['+159h00m', '120h', '6 meses'], $quadros->each(static fn (Crawler $q): string => $q->filter('div.pe-bh-kpi > b.pe-bh-kpi-valor:last-child')->text()));
        self::assertSame(['pe-cor--pos', 'pe-cor--neg', 'pe-cor--zero'], $quadros->each(static fn (Crawler $q): string => explode(' ', (string) $q->filter('b')->attr('class'))[1]), 'o limite fica vermelho acima dele (desenho L205)');

        $faixa = $painel->filter('div.pe-bh-kpis + div.pe-bh-aviso');
        self::assertCount(1, $faixa->filter('div.pe-bh-aviso > i.bi.bi-exclamation-triangle:first-child'));
        self::assertSame('Saldo acima do limite configurado. Planeje a compensação para evitar passivo.', trim($faixa->text()));

        $linhas = $painel->filter('div.pe-bh-aviso + div.pe-abas-rolagem > table.pe-tab:not(.pe-resp) > tbody > tr.pe-just-lin');
        self::assertSame(['Competência', 'Crédito', 'Compensado', 'Saldo', 'Vence em', 'Situação'], $painel->filter('table.pe-tab > thead > tr > th')->each(static fn (Crawler $th): string => $th->text()));
        self::assertSame(
            [
                [$antigo->format('m/Y'), '+10h00m', '1h00m', '+9h00m', $antigo->modify('+6 months')->modify('last day of this month')->format('d/m/Y'), 'Vencido'],
                [$passado->format('m/Y'), '+150h00m', '0h', '+150h00m', $passado->modify('+6 months')->modify('last day of this month')->format('d/m/Y'), 'No prazo'],
                [$this->mes->format('m/Y'), '0h', '1h00m', '0h', 'Sem prazo', 'Compensação'],
            ],
            $linhas->each(static fn (Crawler $tr): array => $tr->filter('td')->each(static fn (Crawler $td): string => trim($td->text()))),
            'célula sem valor: "0h" e "Sem prazo" (spec, seção 8, item 7)',
        );
        self::assertSame(
            ['background-color: #a3232b;', 'background-color: #1e7b45;', 'background-color: #5b7383;'],
            $linhas->each(static fn (Crawler $tr): string => (string) $tr->filter('td:last-child > span.pe-bh-selo:only-child')->attr('style')),
        );
        self::assertCount(1, $linhas->eq(0)->filter('td:first-child > b'), 'a competência em negrito');
        self::assertCount(1, $linhas->eq(0)->filter('td:nth-child(4).pe-bh-saldo'));

        $nota = $painel->filter('div.pe-abas-rolagem + div.pe-bh-nota:last-child');
        self::assertStringStartsWith('Horas negativas abatem primeiro os créditos mais antigos.', trim($nota->text()));
        self::assertStringContainsString('art. 59, § 5º, da CLT', $nota->text());

        self::assertSame('+159h00m', $tela->filter(self::TOPO . ' > b.pe-banco-valor')->text(), 'o topo é o saldo dos lotes');
        $triangulo = $tela->filter(self::TOPO . ' > b.pe-banco-valor + i.bi.bi-exclamation-triangle-fill.pe-banco-alerta:last-child');
        self::assertCount(1, $triangulo, 'o triângulo amarelo logo depois do número (desenho L106)');
        self::assertSame('Saldo acima do limite configurado', $triangulo->attr('title'));

        foreach (['—', '–'] as $travessao) {
            self::assertStringNotContainsString($travessao, $painel->text());
        }
    }

    #[TestDox('O banco atravessa o ano no topo e na aba: o crédito de dezembro continua em janeiro')]
    public function testAtravessaOAno(): void
    {
        $dezembro = new \DateTimeImmutable(sprintf('%d-12-01', (int) $this->mes->format('Y') - 1));
        $this->horasPagas($this->escritorio, $dezembro, 300);
        $this->horasPagas($this->escritorio, $this->mes, -60);

        $tela = $this->tela();

        self::assertSame('+4h00m', $tela->filter(self::TOPO . ' > b.pe-banco-valor')->text(), 'até a E17 o topo mostraria só -1h00m');
        self::assertSame('+4h00m', $tela->filter(self::PAINEL . '[hidden] div.pe-bh-kpi:first-child > b')->text(), 'a aba fechada já vem pronta, com o mesmo número');
    }

    #[TestDox('Sem lote: quadros com 0h, sem faixa, sem triângulo, a frase no lugar da tabela; a aba fica fechada')]
    public function testSemLote(): void
    {
        $tela = $this->tela();

        self::assertCount(1, $tela->filter(self::CARTAO . ' > div.pe-abas-barra > button#ponto-aba-banco[aria-selected="false"]'));
        $painel = $tela->filter(self::PAINEL . '[hidden]');
        self::assertCount(1, $painel);
        self::assertSame('0h', $painel->filter('div.pe-bh-kpi:first-child > b.pe-bh-kpi-valor')->text());
        self::assertCount(0, $painel->filter('.pe-bh-aviso'));
        self::assertSame('Nenhum lote no banco de horas.', trim($painel->filter('div.pe-bh-kpis + div.pe-abas-rolagem table.pe-tab > tbody > tr:only-child > td.pe-abas-vazio[colspan="6"]')->text()));
        self::assertSame('0h', $tela->filter(self::TOPO . ' > b.pe-banco-valor')->text());
        self::assertCount(0, $tela->filter('.pe-banco-alerta'));
    }

    #[TestDox('Prazo de 1 mês, no singular')]
    public function testPrazoDeUmMes(): void
    {
        $html = static::getContainer()->get('twig')->render('ponto/_abas_banco.html.twig', [
            'bancoDeHoras' => new BancoDeHorasOutput(0, 7200, 1, false, []),
        ]);

        self::assertSame('1 mês', (new Crawler($html))->filter('div.pe-bh-kpis > div.pe-bh-kpi:nth-child(3) > b.pe-bh-kpi-valor')->text());
    }

    /**
     * Item 12 da E17: quem não tem jornada pessoal conta os dias pela jornada do escritório, a mesma da folha (até aqui o
     * banco não contava dia nenhum). Desde a rodada 3 o total "Saldo do mês" da /ponto e o da ficha saem do banco, então
     * comparar com eles não prova nada sozinho: a prova são as LINHAS da ficha do gestor, que mostram todos os dias e
     * saem do motor da folha, sem passar pelo banco. O "Saldo mês" da última linha de cada mês tem de ser o total da
     * /ponto, e a soma dos meses, com as horas pagas, o topo.
     */
    #[TestDox('Sem jornada pessoal: o banco é a soma dos meses nas linhas da folha, contada pela jornada do escritório')]
    public function testSemJornadaPessoalOBancoEASomaDaFolha(): void
    {
        $this->mesesTrabalhados();

        $this->assertBancoIgualASomaDaFolha();
    }

    /**
     * O mesmo, com uma versão de jornada que começa no dia 15 do mês passado e dias contados antes dela. Cai se o banco
     * trocar `jornadaDaFolha` por `historicoDe() ?? jornada vazia`: os dias antes da versão saem do banco e ficam nas linhas.
     */
    #[TestDox('Primeira versão da jornada no meio do mês: os dias de antes contam pela do escritório, no banco e nas linhas')]
    public function testDiasAntesDaPrimeiraVersaoContamNoBanco(): void
    {
        $this->jornadaDeOitoHorasTodoDia($this->mes->modify('-1 month')->modify('+14 days'));
        $this->mesesTrabalhados();

        $this->assertBancoIgualASomaDaFolha();
    }

    #[TestDox('Na ficha do gestor, o "Saldo mês" do dia em andamento repete o de ontem (desenho L157); no dia 1º, vazio')]
    public function testFichaRepeteOSaldoMesDeOntem(): void
    {
        $hoje = new \DateTimeImmutable('today');
        $this->jornadaDeOitoHorasTodoDia();
        $this->batida($hoje->modify('-2 days')->setTime(8, 0), RegistroPonto::TIPO_ENTRADA);
        $this->batida($hoje->modify('-2 days')->setTime(17, 0), RegistroPonto::TIPO_SAIDA);
        $this->batida($hoje->setTime(0, 1), RegistroPonto::TIPO_ENTRADA);
        $gestor = $this->pessoa('gestor_banco');
        $this->vincularPrimario($gestor, $this->escritorio);

        $this->logarComTenant($this->client, $gestor, $this->escritorio);
        $ficha = $this->client->request('GET', sprintf('/tenant/%d/user/%d/edit-role?tab=ponto&competencia=%s', $this->escritorio->getId(), $this->eu->getId(), $hoje->format('Y-m')));
        self::assertResponseIsSuccessful();

        $saldoMes = static fn (\DateTimeImmutable $dia): Crawler => $ficha->filter(sprintf('#tab-ponto tr.pe-lin[data-chave-dia="%s"] > td.pe-sm[data-r="Saldo mês"]', $dia->format('Y-m-d')));
        self::assertCount(1, $saldoMes($hoje));
        if ($hoje->format('j') === '1') {
            self::assertSame('', trim($saldoMes($hoje)->text()));

            return;
        }
        self::assertNotSame('', trim($saldoMes($hoje->modify('-1 day'))->text()), 'controle: ontem tem Saldo mês');
        self::assertSame(trim($saldoMes($hoje->modify('-1 day'))->text()), trim($saldoMes($hoje)->text()));
    }

    /**
     * Batidas no 1º e no último dia do mês passado e ontem (se ontem é deste mês); hoje, nenhuma (dia sem saída não
     * conta). Horas pagas nos dois meses, para a folha mostrar os totais.
     */
    private function mesesTrabalhados(): void
    {
        $passado = $this->mes->modify('-1 month');
        $ontem = new \DateTimeImmutable('yesterday');
        foreach (array_unique([$passado, $passado->modify('last day of this month'), $ontem], SORT_REGULAR) as $dia) {
            $this->batida($dia->setTime(8, 0), RegistroPonto::TIPO_ENTRADA);
            $this->batida($dia->setTime(17, 0), RegistroPonto::TIPO_SAIDA);
        }
        $this->horasPagas($this->escritorio, $passado, 60);
        $this->horasPagas($this->escritorio, $this->mes, 60);
    }

    /**
     * Mês passado e este: o "Saldo mês" da última linha preenchida da ficha é o total "Saldo do mês" da /ponto, e a soma
     * dos dois, com as horas pagas, é o número do topo.
     */
    private function assertBancoIgualASomaDaFolha(): void
    {
        $gestor = $this->pessoa('gestor_linhas');
        $this->vincularPrimario($gestor, $this->escritorio);
        $linhas = [];
        $totais = [];
        $topos = [];
        foreach ([$this->mes->modify('-1 month'), $this->mes] as $competencia) {
            $tela = $this->tela('?competencia=' . $competencia->format('Y-m'));
            $valores = $tela->filter('#ponto-regiao-folha div.pe-folha-totais > div.pe-folha-total > b.pe-folha-total-valor');
            self::assertCount(3, $valores, 'Saldo do mês, Horas pagas e Total do mês de ' . $competencia->format('m/Y'));
            self::assertSame(60, $this->minutos($valores->eq(1)->text()));
            $totais[] = $this->minutos($valores->eq(0)->text());
            $topos[] = $this->minutos($tela->filter(self::TOPO . ' > b.pe-banco-valor')->text());
            $linhas[] = $this->ultimoSaldoMesNaFicha($gestor, $competencia);
        }

        self::assertNotSame(0, array_sum($linhas), 'controle: as linhas contaram os dias pela jornada do escritório');
        self::assertSame($linhas, $totais, 'o total da /ponto de cada mês é a última linha da ficha');
        self::assertSame([array_sum($linhas) + 120, array_sum($linhas) + 120], $topos, 'o topo é a soma das linhas');
    }

    /** O "Saldo mês" da última linha preenchida da ficha do gestor (todos os dias do mês), em minutos; 0 sem nenhuma. */
    private function ultimoSaldoMesNaFicha(User $gestor, \DateTimeImmutable $competencia): int
    {
        $this->logarComTenant($this->client, $gestor, $this->escritorio);
        $ficha = $this->client->request('GET', sprintf('/tenant/%d/user/%d/edit-role?tab=ponto&competencia=%s', $this->escritorio->getId(), $this->eu->getId(), $competencia->format('Y-m')));
        self::assertResponseIsSuccessful();
        $preenchidas = array_values(array_filter(
            $ficha->filter('#tab-ponto tr.pe-lin > td.pe-sm[data-r="Saldo mês"]')->each(static fn (Crawler $td): string => trim($td->text())),
            static fn (string $texto): bool => $texto !== '',
        ));

        return $preenchidas === [] ? 0 : $this->minutos($preenchidas[count($preenchidas) - 1]);
    }

    /** "+1h00m", "-7h00m", "0h00m" e "0h" em minutos. */
    private function minutos(string $texto): int
    {
        self::assertMatchesRegularExpression('/^[+-]?\d+h(\d{2}m)?$/D', trim($texto));
        preg_match('/^([+-]?)(\d+)h(?:(\d{2})m)?$/D', trim($texto), $partes);
        $minutos = (int) $partes[2] * 60 + (int) ($partes[3] ?? 0);

        return $partes[1] === '-' ? -$minutos : $minutos;
    }

    private function tela(string $consulta = ''): Crawler
    {
        $this->logarComTenant($this->client, $this->eu, $this->escritorio);
        $this->em()->clear();
        $tela = $this->client->request('GET', '/ponto/' . $consulta);
        self::assertResponseIsSuccessful();

        return $tela;
    }

    private function horasPagas(Tenant $tenant, \DateTimeImmutable $competencia, int $minutos): void
    {
        $lancamento = new LancamentoHorasPagas();
        $lancamento->setTenant($tenant);
        $lancamento->setUser($this->eu);
        $lancamento->setAno((int) $competencia->format('Y'));
        $lancamento->setMes((int) $competencia->format('n'));
        $lancamento->setMinutos($minutos);
        $lancamento->setMotivo('Lançamento de teste');
        $lancamento->setCriadoPor($this->eu);
        $lancamento->setCriadoEm(new \DateTimeImmutable());
        $this->em()->persist($lancamento);
        $this->em()->flush();
    }

    /** Os sete dias da semana, 8h por dia: ontem tem saldo qualquer que seja o dia da semana. */
    private function jornadaDeOitoHorasTodoDia(?\DateTimeImmutable $valeAPartirDe = null): void
    {
        $jornada = (new JornadaColaborador())->setUser($this->eu)->setTenant($this->escritorio)->setCargaHorariaSemanal(3360);
        $jornada->setValeAPartirDe($valeAPartirDe ?? new \DateTimeImmutable('first day of -6 months'));
        $jornada->addBloco((new BlocoJornadaColaborador())->setDiasSemana([1, 2, 3, 4, 5, 6, 7])->setEntrada('08:00')
            ->setRepouso('12:00')->setRetorno('13:00')->setSaida('17:00')->setMinutosBloco(480));
        $this->em()->persist($jornada);
        $this->em()->flush();
    }

    private function batida(\DateTimeImmutable $quando, string $tipo): void
    {
        $registro = new RegistroPonto();
        $registro->setUser($this->eu);
        $registro->setTenant($this->escritorio);
        $registro->setTipo($tipo);
        $registro->setDataHora(new \DateTime($quando->format('Y-m-d H:i:s')));
        $registro->setSedeNomeSnapshot('Teste');
        $this->em()->persist($registro);
        $this->em()->flush();
    }
}
