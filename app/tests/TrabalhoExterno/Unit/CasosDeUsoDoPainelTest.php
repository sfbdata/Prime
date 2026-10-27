<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Unit;

use App\TrabalhoExterno\Entity\ConfiguracaoCentral;
use App\TrabalhoExterno\Entity\InteressadoEmVagas;
use App\TrabalhoExterno\Entity\LinkDeVagas;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\DemandaNaoEncontrada;
use App\TrabalhoExterno\Repository\CandidaturaRepository;
use App\TrabalhoExterno\Repository\CurriculoRepository;
use App\TrabalhoExterno\Repository\InteressadoEmVagasRepository;
use App\TrabalhoExterno\Repository\LinkDeVagasRepository;
use App\TrabalhoExterno\Repository\MetricaPublicaRepository;
use App\TrabalhoExterno\Repository\VagaRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeCurriculo;
use App\TrabalhoExterno\Service\LocalizadorDeVaga;
use App\TrabalhoExterno\Service\PainelDoBancoDeTalentos;
use App\TrabalhoExterno\Service\PlanilhaCsv;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;
use App\TrabalhoExterno\Service\PoliticaDaVaga;
use App\TrabalhoExterno\Service\PoliticaDoCurriculo;
use App\TrabalhoExterno\UseCase\ExportarAlertasCsvUseCase;
use App\TrabalhoExterno\UseCase\MontarPainelDeCurriculosUseCase;
use App\TrabalhoExterno\UseCase\RegistrarMetricaPublicaUseCase;
use Psr\Log\NullLogger;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Os casos de uso do painel do Master e dos contadores da página pública (spec VS 18.10.26 §4.9): quem
 * pode, o CSV dos alertas no formato do desenho e o que conta como visita e como clique.
 */
#[CoversClass(MontarPainelDeCurriculosUseCase::class)]
#[CoversClass(ExportarAlertasCsvUseCase::class)]
#[CoversClass(RegistrarMetricaPublicaUseCase::class)]
#[CoversClass(PlanilhaCsv::class)]
final class CasosDeUsoDoPainelTest extends TestCase
{
    /** @return iterable<string, array{Contexto}> */
    public static function quemNaoVe(): iterable
    {
        $cfg = new ConfiguracaoCentral();
        yield 'Equipe' => [Apoio::contexto(Perfil::Equipe, cfg: $cfg)];
        yield 'Solicitante' => [Apoio::contexto(Perfil::Solicitante, cfg: $cfg)];
        yield 'prévia do Master como Equipe' => [new Contexto(Apoio::usuario(1), Apoio::tenant(1), Perfil::Equipe, $cfg, previa: true)];
        yield 'prévia do Master como Master' => [new Contexto(Apoio::usuario(1), Apoio::tenant(1), Perfil::Master, $cfg, previa: true)];
    }

    #[DataProvider('quemNaoVe')]
    #[TestDox('o painel e o CSV dos alertas são só do Master de verdade: nega $_dataName sem ler nada')]
    public function testSoOMaster(Contexto $ctx): void
    {
        $interessados = $this->createMock(InteressadoEmVagasRepository::class);
        $interessados->expects(self::never())->method(self::anything());

        try {
            $this->painel($interessados)->executar($ctx);
            self::fail('o painel deveria ser negado');
        } catch (AcaoNaoPermitida $e) {
            self::assertSame('Estatísticas disponíveis só para o Master.', $e->getMessage());
        }
        $this->expectException(AcaoNaoPermitida::class);
        (new ExportarAlertasCsvUseCase($this->politica(), $interessados, new NullLogger()))->executar($ctx);
    }

    #[TestDox('o Master recebe o painel com os inscritos e os contadores da página pública')]
    public function testMasterMonta(): void
    {
        $interessados = $this->createStub(InteressadoEmVagasRepository::class);
        $interessados->method('contar')->willReturn(4);
        $metricas = $this->createStub(MetricaPublicaRepository::class);
        $metricas->method('totais')->willReturn(['visitas' => 9, 'cliques' => 2]);

        $ligado = new ConfiguracaoCentral();
        $ligado->definirTermoLigado(true);
        $p = $this->painel($interessados, $metricas)->executar(Apoio::contexto(Perfil::Master, cfg: $ligado));
        $desligado = $this->painel($interessados, $metricas)->executar(Apoio::contexto(Perfil::Master));

        self::assertSame('', $desligado['kpis'][3]['sub'], 'com o termo desligado o KPI não fala de termo');
        self::assertSame(4, $p['inscritos']);
        self::assertSame('0 termo(s) aceito(s) · 0 aguardando', $p['kpis'][3]['sub']);
        self::assertSame('9 visita(s) pelo link · 2 clique(s) em criar conta · clique para campanha', $p['kpis'][5]['sub']);
        self::assertSame('nenhuma publicada', $p['kpis'][7]['sub']);
    }

    #[TestDox('CSV dos alertas: colunas do desenho, BOM, ";", aspas dobradas, "Brasil" sem UF, áreas com "; ", origem e fórmula neutralizada')]
    public function testCsvDosAlertas(): void
    {
        $doLink = new InteressadoEmVagas('ana@exemplo.test');
        $doLink->definirPreferencias('DF', ['Cível', 'Família'], LinkDeVagas::hash('t'), new \DateTimeImmutable());
        $semUf = new InteressadoEmVagas('=cmd|"x"@exemplo.test');
        $interessados = $this->createStub(InteressadoEmVagasRepository::class);
        $interessados->method('paraExportar')->willReturn([$doLink, $semUf]);

        $log = new TestHandler();
        $master = Apoio::contexto(Perfil::Master, usuario: Apoio::usuario(77));
        $linhas = explode("\n", (new ExportarAlertasCsvUseCase($this->politica(), $interessados, new Logger('app', [$log])))->executar($master));

        $hoje = (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('d/m/Y');
        self::assertSame("\u{FEFF}" . '"E-mail";"UF";"Áreas";"Inscrito em";"Origem"', $linhas[0]);
        self::assertSame('"ana@exemplo.test";"DF";"Cível; Família";"' . $hoje . '";"link de vagas"', $linhas[1]);
        self::assertSame('"\'=cmd|""x""@exemplo.test";"Brasil";"";"' . $hoje . '";"página de vagas"', $linhas[2]);
        self::assertCount(3, $linhas);

        self::assertCount(1, $log->getRecords(), 'cada download fica no log da aplicação');
        $registro = $log->getRecords()[0];
        self::assertSame(Level::Info, $registro->level);
        self::assertSame([77, 2], [$registro->context['usuario'], $registro->context['linhas']]);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $registro->context['em']));
        self::assertStringNotContainsString('@exemplo.test', $registro->message . json_encode($registro->context), 'sem os e-mails no log');
    }

    #[TestDox('sem inscritos: "não encontrado" com o texto do toast do desenho')]
    public function testSemInscritos(): void
    {
        $interessados = $this->createStub(InteressadoEmVagasRepository::class);
        $interessados->method('paraExportar')->willReturn([]);

        $this->expectException(DemandaNaoEncontrada::class);
        $this->expectExceptionMessage('Compartilhe vagas para começar a captar e-mails.');
        (new ExportarAlertasCsvUseCase($this->politica(), $interessados, new NullLogger()))->executar(Apoio::contexto(Perfil::Master));
    }

    /** @return iterable<string, array{string, ?string, bool, string, int, int, bool}> */
    public static function eventos(): iterable
    {
        $chrome = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36';
        yield 'visita com link válido' => [RegistrarMetricaPublicaUseCase::VISITA, 'valido', true, $chrome, 1, 0, true];
        yield 'visita com token desconhecido' => [RegistrarMetricaPublicaUseCase::VISITA, 'desconhecido', false, $chrome, 0, 0, false];
        yield 'clique com link válido' => [RegistrarMetricaPublicaUseCase::CLIQUE, 'valido', true, $chrome, 0, 1, true];
        yield 'clique sem token' => [RegistrarMetricaPublicaUseCase::CLIQUE, null, false, $chrome, 0, 1, true];
        yield 'clique com token desconhecido conta sem link' => [RegistrarMetricaPublicaUseCase::CLIQUE, 'desconhecido', false, $chrome, 0, 1, true];
        yield 'Googlebot' => [RegistrarMetricaPublicaUseCase::VISITA, 'valido', true, 'Mozilla/5.0 (compatible; Googlebot/2.1)', 0, 0, false];
        yield 'prévia do WhatsApp' => [RegistrarMetricaPublicaUseCase::VISITA, 'valido', true, 'WhatsApp/2.23.20.0', 0, 0, false];
        yield 'facebookexternalhit' => [RegistrarMetricaPublicaUseCase::CLIQUE, null, false, 'facebookexternalhit/1.1', 0, 0, false];
        yield 'sem User-Agent' => [RegistrarMetricaPublicaUseCase::CLIQUE, null, false, '', 0, 0, false];
        yield 'celular CUBOT NOTE 7 é gente' => [RegistrarMetricaPublicaUseCase::CLIQUE, null, false, 'Mozilla/5.0 (Linux; Android 10; CUBOT NOTE 7) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36', 0, 1, true];
        yield 'Googlebot/2.1 sozinho' => [RegistrarMetricaPublicaUseCase::VISITA, 'valido', true, 'Googlebot/2.1 (+http://www.google.com/bot.html)', 0, 0, false];
        yield 'bingbot/2.0' => [RegistrarMetricaPublicaUseCase::CLIQUE, null, false, 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)', 0, 0, false];
        yield 'Slackbot-LinkExpanding' => [RegistrarMetricaPublicaUseCase::VISITA, 'valido', true, 'Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)', 0, 0, false];
    }

    #[DataProvider('eventos')]
    #[TestDox('contador anônimo: $_dataName')]
    public function testContadores(string $evento, ?string $token, bool $linkExiste, string $ua, int $visitas, int $cliques, bool $conta): void
    {
        [$link] = LinkDeVagas::gerar([1], Apoio::usuario(1), 'Ana');
        $links = $this->createStub(LinkDeVagasRepository::class);
        $links->method('doToken')->willReturn($linkExiste ? $link : null);
        $metricas = $this->createMock(MetricaPublicaRepository::class);
        $hoje = (new \DateTimeImmutable('today', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
        $metricas->expects($conta ? self::once() : self::never())->method('somar')
            ->with(self::callback(static fn (\DateTimeImmutable $d): bool => $d->format('Y-m-d') === $hoje), $linkExiste ? $link : null, $visitas, $cliques);

        self::assertSame($conta, (new RegistrarMetricaPublicaUseCase($links, $metricas, new NullLogger()))->executar($evento, $token, $ua));
    }

    #[TestDox('a falha ao gravar a métrica não sobe: vira aviso no log e o evento não conta')]
    public function testFalhaAoGravarNaoDerruba(): void
    {
        $metricas = $this->createStub(MetricaPublicaRepository::class);
        $metricas->method('somar')->willThrowException(new \RuntimeException('banco fora'));
        $log = new TestHandler();

        $contou = (new RegistrarMetricaPublicaUseCase($this->createStub(LinkDeVagasRepository::class), $metricas, new Logger('app', [$log])))
            ->executar(RegistrarMetricaPublicaUseCase::CLIQUE, null, 'Mozilla/5.0 Chrome/130.0');

        self::assertFalse($contou);
        self::assertTrue($log->hasWarningThatContains('métrica pública não gravada'));
        self::assertSame('banco fora', $log->getRecords()[0]->context['erro']);
    }

    private function painel(InteressadoEmVagasRepository $interessados, ?MetricaPublicaRepository $metricas = null): MontarPainelDeCurriculosUseCase
    {
        $politica = $this->politica();
        $vagas = new PoliticaDaVaga(new PoliticaDaDemanda());
        $curriculos = $this->createStub(CurriculoRepository::class);
        $candidaturas = $this->createStub(CandidaturaRepository::class);
        $candidaturas->method('statusEEnvios')->willReturn([]);
        $candidaturas->method('primeirosAceitesDasRodadas')->willReturn([]);
        $candidaturas->method('resumoPorVaga')->willReturn([]);
        if ($metricas === null) {
            $metricas = $this->createStub(MetricaPublicaRepository::class);
            $metricas->method('totais')->willReturn(['visitas' => 0, 'cliques' => 0]);
        }

        return new MontarPainelDeCurriculosUseCase(
            $politica,
            new LocalizadorDeCurriculo($curriculos, $politica, $candidaturas, $vagas),
            new LocalizadorDeVaga($this->createStub(VagaRepository::class), $vagas, $curriculos, $candidaturas),
            $candidaturas,
            $interessados,
            $metricas,
            new PainelDoBancoDeTalentos(),
            $this->createStub(\App\TrabalhoExterno\Repository\TermoDeVoluntariadoRepository::class),
        );
    }

    private function politica(): PoliticaDoCurriculo
    {
        return new PoliticaDoCurriculo(new PoliticaDaVaga(new PoliticaDaDemanda()), new PoliticaDaDemanda());
    }
}
