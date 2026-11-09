<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
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
 * na ficha do gestor.
 *
 * Os lotes daqui saem só de horas pagas (pessoa sem jornada pessoal: a folha não soma nada), para os números não
 * dependerem do dia em que a suíte roda.
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
                [$passado->format('m/Y'), '+150h00m', '', '+150h00m', $passado->modify('+6 months')->modify('last day of this month')->format('d/m/Y'), 'No prazo'],
                [$this->mes->format('m/Y'), '', '1h00m', '', '', 'Compensação'],
            ],
            $linhas->each(static fn (Crawler $tr): array => $tr->filter('td')->each(static fn (Crawler $td): string => trim($td->text()))),
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
    private function jornadaDeOitoHorasTodoDia(): void
    {
        $jornada = (new JornadaColaborador())->setUser($this->eu)->setTenant($this->escritorio)->setCargaHorariaSemanal(3360);
        $jornada->setValeAPartirDe(new \DateTimeImmutable('first day of -6 months'));
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
