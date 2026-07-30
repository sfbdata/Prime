<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Repository\LancamentoHorasPagasRepository;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\CalculadoraJornada;
use App\Ponto\Service\FolhaPontoBuilder;
use App\Ponto\Service\JornadaResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O banco de horas não é persistido: é recalculado a cada leitura. O lançamento de horas pagas
 * entra aqui, nos agregadores — nunca em buildRows, que é por dia.
 */
#[CoversClass(FolhaPontoBuilder::class)]
final class FolhaPontoBuilderHorasPagasTest extends TestCase
{
    #[TestDox('lancamento negativo reduz o saldo anual')]
    public function testLancamentoNegativoReduzSaldoAnual(): void
    {
        $builder = $this->builderComLancamentos([2026 => [1 => -600]]);

        $saldo = $builder->calcularSaldoAnual(
            $this->userComJornada(),
            2026,
            [],
            null,
            new \DateTimeImmutable('2026-01-01'),
            $this->tenant(),
        );

        self::assertSame(-600, $saldo, 'as horas pagas do mês 1 deveriam ter descontado o saldo');
    }

    #[TestDox('lancamento positivo aumenta o saldo anual')]
    public function testLancamentoPositivoAumentaSaldoAnual(): void
    {
        $builder = $this->builderComLancamentos([2026 => [1 => 480]]);

        $saldo = $builder->calcularSaldoAnual(
            $this->userComJornada(),
            2026,
            [],
            null,
            new \DateTimeImmutable('2026-01-01'),
            $this->tenant(),
        );

        self::assertSame(480, $saldo);
    }

    #[TestDox('lancamento de competencia ANTERIOR ao inicio da contagem ainda conta')]
    public function testLancamentoAnteriorAoInicioDaContagemAindaConta(): void
    {
        // início da contagem em dezembro, lançamento em janeiro do mesmo ano: a varredura mensal
        // nunca passaria por janeiro. O lançamento não pode sumir por isso.
        $builder = $this->builderComLancamentos([2026 => [1 => -300]]);

        $saldo = $builder->calcularSaldoAnual(
            $this->userComJornada(),
            2026,
            [],
            null,
            new \DateTimeImmutable('2026-12-01'),
            $this->tenant(),
        );

        self::assertSame(-300, $saldo, 'horas pagas fora da janela de contagem não podem evaporar');
    }

    #[TestDox('colaborador SEM jornada configurada ainda recebe o lancamento')]
    public function testColaboradorSemJornadaAindaRecebeLancamento(): void
    {
        $builder = $this->builderComLancamentos([2026 => [3 => -120]]);

        $userSemJornada = $this->createStub(User::class);
        $userSemJornada->method('getJornadaColaborador')->willReturn(null);

        $saldo = $builder->calcularSaldoAnual($userSemJornada, 2026, [], null, new \DateTimeImmutable('2026-01-01'), $this->tenant());

        self::assertSame(-120, $saldo, 'sem jornada o saldo é 0, mas o lançamento manual continua valendo');
    }

    #[TestDox('colaborador SEM nenhuma batida ainda recebe o lancamento')]
    public function testColaboradorSemBatidaAindaRecebeLancamento(): void
    {
        $builder = $this->builderComLancamentos([2026 => [3 => 240]]);

        // inicioContagem null = colaborador sem nenhum registro de ponto
        $saldo = $builder->calcularSaldoAnual($this->userComJornada(), 2026, [], null, null, $this->tenant());

        self::assertSame(240, $saldo);
    }

    #[TestDox('dois lancamentos na mesma competencia somam')]
    public function testDoisLancamentosNaMesmaCompetenciaSomam(): void
    {
        // o repositório já devolve a soma; aqui o contrato é: o builder usa o valor como veio
        $builder = $this->builderComLancamentos([2026 => [1 => -6000 + 480]]);

        $saldo = $builder->calcularSaldoAnual($this->userComJornada(), 2026, [], null, new \DateTimeImmutable('2026-01-01'), $this->tenant());

        self::assertSame(-5520, $saldo);
    }

    #[TestDox('calcularSaldoAteMes inclui os meses ate o pedido e exclui os posteriores')]
    public function testSaldoAteMesRespeitaOCorte(): void
    {
        $builder = $this->builderComLancamentos([2026 => [1 => -100, 2 => -200, 5 => -400]]);

        $saldo = $builder->calcularSaldoAteMes(
            $this->userComJornada(),
            2026,
            2,
            [],
            null,
            new \DateTimeImmutable('2026-01-01'),
            $this->tenant(),
        );

        self::assertSame(-300, $saldo, 'maio não pode entrar num saldo até fevereiro');
    }

    #[TestDox('buildRows NAO e afetado pelo lancamento')]
    public function testBuildRowsNaoEAfetado(): void
    {
        $builder = $this->builderComLancamentos([2026 => [4 => -6000]]);
        $jornada = $this->jornadaSimples();

        $rows = $builder->buildRows(
            new \DateTimeImmutable('2026-04-01'),
            new \DateTimeImmutable('2026-04-30'),
            [],
            true,
            false,
            $jornada,
            [],
            [],
            null,
            new \DateTimeImmutable('2020-01-01'),
        );

        foreach ($rows as $row) {
            self::assertNotSame(-6000, $row['saldoDia'], 'horas pagas nunca entram numa linha de dia');
        }
    }

    #[TestDox('sem lancamento nenhum o saldo fica identico ao comportamento antigo')]
    public function testSemLancamentoSaldoNaoMuda(): void
    {
        $builder = $this->builderComLancamentos([]);

        $saldo = $builder->calcularSaldoAnual($this->userComJornada(), 2026, [], null, null, $this->tenant());

        self::assertSame(0, $saldo);
    }

    #[TestDox('sem tenant informado o lancamento e ignorado, sem quebrar')]
    public function testSemTenantIgnoraLancamento(): void
    {
        $builder = $this->builderComLancamentos([2026 => [1 => -600]]);

        $saldo = $builder->calcularSaldoAnual($this->userComJornada(), 2026, [], null, null, null);

        self::assertSame(0, $saldo, 'sem tenant não há como filtrar com segurança: não soma');
    }

    #[TestDox('somarHorasPagasDaCompetencia (metodo publico da tela) tambem nao soma sem tenant')]
    public function testSomarHorasPagasDaCompetenciaSemTenantNaoSoma(): void
    {
        // calcularSaldoAnual/AteMes usam o agregador PRIVADO (somarHorasPagasDoPeriodo); este
        // método público é o que as telas chamam para exibir a linha "Horas pagas" do mês — tem
        // sua própria guarda de tenant e merece prova própria, senão o guard fica sem cobertura.
        $builder = $this->builderComLancamentos([2026 => [3 => -600]]);

        $saldo = $builder->somarHorasPagasDaCompetencia($this->userComJornada(), null, 2026, 3);

        self::assertSame(0, $saldo, 'sem tenant não há como filtrar com segurança: não soma');
    }

    /**
     * @param array<int, array<int, int>> $porAnoMes minutos indexados por [ano][mês]
     */
    private function builderComLancamentos(array $porAnoMes): FolhaPontoBuilder
    {
        $repo = $this->createStub(LancamentoHorasPagasRepository::class);
        $repo->method('somarPorCompetencia')->willReturnCallback(
            static fn (User $u, Tenant $t, int $ano, int $mes): int => $porAnoMes[$ano][$mes] ?? 0
        );

        return new FolhaPontoBuilder(
            new CalculadoraJornada(new JornadaResolver()),
            $this->createStub(RegistroPontoRepository::class),
            $this->createStub(JustificativaPontoRepository::class),
            $repo,
        );
    }

    private function userComJornada(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getJornadaColaborador')->willReturn($this->jornadaSimples());

        return $user;
    }

    private function tenant(): Tenant
    {
        return $this->createStub(Tenant::class);
    }

    /**
     * NÃO é cópia verbatim do jornadaSimples() de FolhaPontoBuilderTest — o brief pedia a cópia
     * verbatim, mas ela quebra estes testes (ver relatório da Tarefa 3): com dias úteis reais
     * (padrão da entidade é segunda-sexta, 480 min/dia) e sem nenhuma batida, calcularSaldoAnual
     * soma um déficit real de falta em CADA dia útil entre 01/jan e "hoje" — um número que muda
     * com a data do relógio e teria ofuscado por completo os poucos minutos do lançamento sob
     * teste. Aqui a jornada é neutra de propósito (usuário setado para não quebrar
     * `$jornada->getUser()` dentro de calcularSaldoDia, mas SEM dia útil configurado) para que o
     * saldo por dia dê sempre 0 e o teste isole só o efeito das horas pagas.
     */
    private function jornadaSimples(): JornadaColaborador
    {
        $user = new User();
        $user->setEmail('t@t.com')->setFullName('T');

        $jornada = new JornadaColaborador();
        $jornada->setUser($user);
        $jornada->setDiasSemana([]);

        return $jornada;
    }
}
