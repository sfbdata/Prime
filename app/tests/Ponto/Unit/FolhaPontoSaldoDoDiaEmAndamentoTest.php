<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\JornadaColaboradorRepository;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Repository\LancamentoHorasPagasRepository;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\CalculadoraJornada;
use App\Ponto\Service\FeriadosDaPessoa;
use App\Ponto\Service\FolhaPontoBuilder;
use App\Ponto\Service\JornadaResolver;
use App\Repository\UserTenantRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O "Saldo mês" do dia em andamento (spec ponto-desenho-0811, E17, pendência registrada em e0606ade5; desenho 16
 * L157): hoje sem saída não apura o dia, mas a célula mostra o acumulado até ontem, o mesmo número da linha de ontem.
 * No 1º dia do mês não há acumulado: a célula fica vazia. O dia em andamento continua fora do saldo do mês e do banco.
 */
#[CoversClass(FolhaPontoBuilder::class)]
final class FolhaPontoSaldoDoDiaEmAndamentoTest extends TestCase
{
    #[TestDox('hoje sem saida mostra o acumulado ate ontem, igual a linha de ontem')]
    public function testHojeSemSaidaMostraOAcumuladoDeOntem(): void
    {
        $hoje = new \DateTimeImmutable('today');
        $rows = $this->folha($hoje->modify('-2 days'), $hoje, [$this->batida(RegistroPonto::TIPO_ENTRADA, $hoje->setTime(0, 0, 1))]);

        self::assertCount(3, $rows);
        self::assertSame(-960, $rows[1]['saldoAcumulado'], 'dois dias de falta até ontem');
        self::assertSame($rows[1]['saldoAcumulado'], $rows[2]['saldoAcumuladoAteOntem'], 'hoje repete o acumulado de ontem');
        self::assertNull($rows[2]['saldoAcumulado'], 'o dia em andamento não é apurado');
        self::assertNull($rows[2]['saldoDia']);
        self::assertSame(-960, (new FolhaPontoBuilder(...$this->dependencias()))->saldoAcumuladoFinal($rows), 'nem entra no saldo do mês');
    }

    /**
     * O modo da /ponto (`includeEmptyDays = false`): o dia sem batida some da folha, mas a falta dele conta. Entre o
     * último dia trabalhado e hoje há dois dias sem batida; o número de hoje tem de descontá-los (-480 cada), e não
     * repetir a linha de cima.
     */
    #[TestDox('no modo da /ponto, os dias sem batida entre o ultimo trabalhado e hoje contam no numero de hoje')]
    public function testModoDaTelaContaOsDiasSemBatida(): void
    {
        $hoje = new \DateTimeImmutable('today');
        $trabalhado = $hoje->modify('-3 days');
        $rows = $this->folha($trabalhado, $hoje, [
            $this->batida(RegistroPonto::TIPO_ENTRADA, $trabalhado->setTime(8, 0)),
            $this->batida(RegistroPonto::TIPO_SAIDA, $trabalhado->setTime(17, 0)),
            $this->batida(RegistroPonto::TIPO_ENTRADA, $hoje->setTime(0, 0, 1)),
        ], false);

        self::assertSame([$trabalhado->format('Y-m-d'), $hoje->format('Y-m-d')], array_column($rows, 'chaveDia'), 'os dois dias sem batida não aparecem');
        self::assertIsInt($rows[0]['saldoAcumulado']);
        self::assertSame($rows[0]['saldoAcumulado'] - 960, $rows[1]['saldoAcumuladoAteOntem']);
    }

    /** Uma fonte de "hoje" (item 10 da E17): o dia informado está em andamento e os seguintes são futuro. */
    #[TestDox('com o hoje informado, aquele dia fica em andamento e os seguintes nao contam')]
    public function testHojeInformado(): void
    {
        $hoje = new \DateTimeImmutable('2026-03-10');
        $rows = $this->folha($hoje->modify('-2 days'), $hoje->modify('+2 days'), [$this->batida(RegistroPonto::TIPO_ENTRADA, $hoje->setTime(0, 0, 1))], true, $hoje->setTime(15, 30));

        self::assertSame(-960, $rows[1]['saldoAcumulado'], 'dois dias de falta até a véspera');
        self::assertNull($rows[2]['saldoAcumulado'], 'o dia informado está em andamento, mesmo com hora');
        self::assertSame(-960, $rows[2]['saldoAcumuladoAteOntem']);
        self::assertNull($rows[3]['saldoAcumulado'], 'depois dele, futuro');
        self::assertSame(-960, (new FolhaPontoBuilder(...$this->dependencias()))->saldoAcumuladoFinal($rows));
    }

    #[TestDox('o banco por competencia vai ate o mes do hoje informado')]
    public function testBancoVaiAteOMesDoHojeInformado(): void
    {
        $builder = new FolhaPontoBuilder(...$this->dependencias());
        $user = (new User())->setEmail('banco-hoje@t.com')->setFullName('Banco Hoje');
        $saldos = static fn (string $hoje): array => array_keys($builder->saldosDoBancoPorCompetencia($user, new Tenant(), [], null, new \DateTimeImmutable('2026-01-10'), new \DateTimeImmutable($hoje)));

        self::assertSame(['2026-01', '2026-02', '2026-03'], $saldos('2026-03-15'));
        self::assertSame(['2026-01', '2026-02'], $saldos('2026-02-28'));
    }

    #[TestDox('no primeiro dia do periodo o dia em andamento nao mostra numero')]
    public function testPrimeiroDiaNaoMostraNumero(): void
    {
        $hoje = new \DateTimeImmutable('today');
        $rows = $this->folha($hoje, $hoje, []);

        self::assertCount(1, $rows);
        self::assertNull($rows[0]['saldoAcumuladoAteOntem'], 'sem dia apurado antes, nada (nem 0h)');
        self::assertNull($rows[0]['saldoAcumulado']);
    }

    #[TestDox('dia apurado nao preenche o acumulado ate ontem')]
    public function testDiaApuradoNaoPreencheOCampo(): void
    {
        $hoje = new \DateTimeImmutable('today');
        $rows = $this->folha($hoje->modify('-1 day'), $hoje, [
            $this->batida(RegistroPonto::TIPO_ENTRADA, $hoje->setTime(0, 0, 1)),
            $this->batida(RegistroPonto::TIPO_SAIDA, $hoje->setTime(0, 0, 2)),
        ]);

        self::assertNull($rows[0]['saldoAcumuladoAteOntem']);
        self::assertNull($rows[1]['saldoAcumuladoAteOntem'], 'hoje com saída já tem o próprio Saldo mês');
        self::assertNotNull($rows[1]['saldoAcumulado']);
    }

    /**
     * @param list<RegistroPonto> $batidas
     * @return array<int, array<string, mixed>>
     */
    private function folha(\DateTimeImmutable $inicio, \DateTimeImmutable $fim, array $batidas, bool $diasVazios = true, ?\DateTimeImmutable $hoje = null): array
    {
        return (new FolhaPontoBuilder(...$this->dependencias()))->buildRows(
            $inicio,
            $fim->setTime(23, 59, 59),
            $batidas,
            $diasVazios,
            false,
            $this->jornadaTodoDia(),
            [],
            [],
            null,
            new \DateTimeImmutable('2020-01-01'),
            $hoje,
        );
    }

    /** @return array{CalculadoraJornada, RegistroPontoRepository, JustificativaPontoRepository, LancamentoHorasPagasRepository, JornadaColaboradorRepository} */
    private function dependencias(): array
    {
        return [
            new CalculadoraJornada(new JornadaResolver()),
            $this->createStub(RegistroPontoRepository::class),
            $this->createStub(JustificativaPontoRepository::class),
            $this->createStub(LancamentoHorasPagasRepository::class),
            $this->createStub(JornadaColaboradorRepository::class),
            new FeriadosDaPessoa($this->createStub(UserTenantRepository::class)),
        ];
    }

    /** Os sete dias úteis, 8h por dia: a falta de qualquer dia da semana vale -480, em qualquer data da suíte. */
    private function jornadaTodoDia(): JornadaColaborador
    {
        $user = new User();
        $user->setEmail('andamento@t.com')->setFullName('Andamento');

        $jornada = new JornadaColaborador();
        $jornada->setUser($user);
        $jornada->setDiasSemana([1, 2, 3, 4, 5, 6, 7]);
        $jornada->setCargaHorariaDiaria(480);
        $jornada->setEntrada1('09:00');
        $jornada->setSaida1('12:00');
        $jornada->setEntrada2('13:00');
        $jornada->setSaida2('18:00');

        return $jornada;
    }

    private function batida(string $tipo, \DateTimeImmutable $quando): RegistroPonto
    {
        $batida = new RegistroPonto();
        $batida->setTipo($tipo);
        $batida->setDataHora($quando);

        return $batida;
    }
}
