<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\RegistroPonto;
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
    // ──────────────────────────────────────────────────────────────────
    // calcularSaldoAnual
    // ──────────────────────────────────────────────────────────────────

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

    #[TestDox('lancamento de dezembro entra no saldo anual (limite superior do periodo)')]
    public function testLancamentoDeDezembroEntraNoSaldoAnual(): void
    {
        // Prova o limite superior "12" de somarHorasPagasDoPeriodo em calcularSaldoAnual: um
        // lançamento no último mês do ano não pode ficar de fora da varredura de jan a dez.
        $builder = $this->builderComLancamentos([2026 => [12 => -600]]);

        $saldo = $builder->calcularSaldoAnual(
            $this->userComJornada(),
            2026,
            [],
            null,
            new \DateTimeImmutable('2026-01-01'),
            $this->tenant(),
        );

        self::assertSame(-600, $saldo, 'dezembro é o último mês do ano e tem de entrar no saldo anual');
    }

    #[TestDox('lancamento de competencia ANTERIOR ao inicio da contagem ainda conta')]
    public function testLancamentoAnteriorAoInicioDaContagemAindaConta(): void
    {
        // Início da contagem no FUTURO relativo ao relógio (nunca uma data fixa): garante
        // $inicio > $fim em calcularSaldoAnual não importa quando a suíte rodar. Com uma data
        // fixa (ex.: 2026-12-01), o teste pararia de cobrir esse desvio silenciosamente assim
        // que o relógio passasse dessa data — teste que expira é pior que teste ausente.
        $anoAtual = (int) (new \DateTimeImmutable())->format('Y');
        $inicioContagemFutura = new \DateTimeImmutable('+2 years');

        $builder = $this->builderComLancamentos([$anoAtual => [1 => -300]]);

        $saldo = $builder->calcularSaldoAnual(
            $this->userComJornada(),
            $anoAtual,
            [],
            null,
            $inicioContagemFutura,
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

    #[TestDox('sem lancamento nenhum o saldo fica identico ao comportamento antigo')]
    public function testSemLancamentoSaldoNaoMuda(): void
    {
        $builder = $this->builderComLancamentos([]);

        $saldo = $builder->calcularSaldoAnual($this->userComJornada(), 2026, [], null, null, $this->tenant());

        self::assertSame(0, $saldo);
    }

    #[TestDox('tenant null (nao ha tenant) ignora o lancamento, sem quebrar')]
    public function testSemTenantIgnoraLancamento(): void
    {
        $builder = $this->builderComLancamentos([2026 => [1 => -600]]);

        $saldo = $builder->calcularSaldoAnual($this->userComJornada(), 2026, [], null, null, null);

        self::assertSame(0, $saldo, 'sem tenant não há como filtrar com segurança: não soma');
    }

    #[TestDox('omitir o tenant em calcularSaldoAnual falha em vez de perder o lancamento calado')]
    public function testOmitirTenantNoSaldoAnualFalha(): void
    {
        // `null` é resposta ("não há tenant"); OMITIR é erro de chamada. Sem esta sentinela, um
        // chamador futuro que esquecesse o tenant zeraria as horas pagas em silêncio — sem
        // exceção, sem log, com o banco de horas errado na tela do colaborador.
        $builder = $this->builderComLancamentos([2026 => [1 => -600]]);

        $this->expectException(\InvalidArgumentException::class);
        $builder->calcularSaldoAnual($this->userComJornada(), 2026, [], null, new \DateTimeImmutable('2026-01-01'));
    }

    #[TestDox('omitir o tenant em calcularSaldoAteMes falha em vez de perder o lancamento calado')]
    public function testOmitirTenantNoSaldoAteMesFalha(): void
    {
        $builder = $this->builderComLancamentos([2026 => [1 => -600]]);

        $this->expectException(\InvalidArgumentException::class);
        $builder->calcularSaldoAteMes($this->userComJornada(), 2026, 3, [], null, new \DateTimeImmutable('2026-01-01'));
    }

    // ──────────────────────────────────────────────────────────────────
    // calcularSaldoAteMes — espelha as mesmas 3 saídas antecipadas de calcularSaldoAnual
    // ──────────────────────────────────────────────────────────────────

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

    #[TestDox('calcularSaldoAteMes: colaborador SEM jornada configurada ainda recebe o lancamento')]
    public function testAteMesSemJornadaAindaRecebeLancamento(): void
    {
        $builder = $this->builderComLancamentos([2026 => [3 => -120]]);

        $userSemJornada = $this->createStub(User::class);
        $userSemJornada->method('getJornadaColaborador')->willReturn(null);

        $saldo = $builder->calcularSaldoAteMes($userSemJornada, 2026, 3, [], null, new \DateTimeImmutable('2026-01-01'), $this->tenant());

        self::assertSame(-120, $saldo, 'sem jornada o saldo é 0, mas o lançamento manual continua valendo');
    }

    #[TestDox('calcularSaldoAteMes: colaborador SEM nenhuma batida ainda recebe o lancamento')]
    public function testAteMesSemBatidaAindaRecebeLancamento(): void
    {
        $builder = $this->builderComLancamentos([2026 => [3 => 240]]);

        // inicioContagem null = colaborador sem nenhum registro de ponto
        $saldo = $builder->calcularSaldoAteMes($this->userComJornada(), 2026, 3, [], null, null, $this->tenant());

        self::assertSame(240, $saldo);
    }

    #[TestDox('calcularSaldoAteMes: inicio de contagem posterior ao fim do periodo ainda recebe o lancamento')]
    public function testAteMesInicioPosteriorAoFimAindaRecebeLancamento(): void
    {
        // Mesmo cuidado do teste equivalente de calcularSaldoAnual: data de início relativa ao
        // relógio (+2 anos), nunca fixa, para $inicio > $fim valer sempre, em qualquer execução.
        $anoAtual = (int) (new \DateTimeImmutable())->format('Y');
        $mesAtual = (int) (new \DateTimeImmutable())->format('n');
        $inicioContagemFutura = new \DateTimeImmutable('+2 years');

        $builder = $this->builderComLancamentos([$anoAtual => [1 => -300]]);

        $saldo = $builder->calcularSaldoAteMes(
            $this->userComJornada(),
            $anoAtual,
            $mesAtual,
            [],
            null,
            $inicioContagemFutura,
            $this->tenant(),
        );

        self::assertSame(-300, $saldo, 'início de contagem no futuro não pode apagar o lançamento');
    }

    // ──────────────────────────────────────────────────────────────────
    // somarHorasPagasDaCompetencia (método público das telas)
    // ──────────────────────────────────────────────────────────────────

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

    #[TestDox('calcularSaldoAteMes pede UMA soma de periodo (jan ate o mes), nao uma por mes')]
    public function testSaldoAteMesUsaUmaQuerySoComOIntervaloCerto(): void
    {
        // Mock (não stub): prova que o agregador resolve o intervalo numa chamada só e com as
        // pontas certas. O painel /ponto é caminho quente — voltar ao laço mês a mês (12
        // round-trips por load) faria este teste falhar por excesso de chamadas.
        $user   = $this->userComJornada();
        $tenant = $this->tenant();

        $repo = $this->createMock(LancamentoHorasPagasRepository::class);
        $repo->expects(self::once())
            ->method('somarPorPeriodo')
            ->with($user, $tenant, 2026, 1, 4)
            ->willReturn(-600);

        $builder = new FolhaPontoBuilder(
            new CalculadoraJornada(new JornadaResolver()),
            $this->createStub(RegistroPontoRepository::class),
            $this->createStub(JustificativaPontoRepository::class),
            $repo,
        );

        $saldo = $builder->calcularSaldoAteMes($user, 2026, 4, [], null, null, $tenant);

        self::assertSame(-600, $saldo);
    }

    #[TestDox('calcularSaldoAnual pede UMA soma de periodo cobrindo o ano inteiro')]
    public function testSaldoAnualUsaUmaQuerySoComOAnoInteiro(): void
    {
        $user   = $this->userComJornada();
        $tenant = $this->tenant();

        $repo = $this->createMock(LancamentoHorasPagasRepository::class);
        $repo->expects(self::once())
            ->method('somarPorPeriodo')
            ->with($user, $tenant, 2026, 1, 12)
            ->willReturn(-600);

        $builder = new FolhaPontoBuilder(
            new CalculadoraJornada(new JornadaResolver()),
            $this->createStub(RegistroPontoRepository::class),
            $this->createStub(JustificativaPontoRepository::class),
            $repo,
        );

        self::assertSame(-600, $builder->calcularSaldoAnual($user, 2026, [], null, null, $tenant));
    }

    #[TestDox('somarHorasPagasDaCompetencia devolve o valor do repositorio na competencia certa')]
    public function testSomarHorasPagasDaCompetenciaCaminhoFeliz(): void
    {
        // Mock (não stub): a expectativa de argumentos prova que ano/mês chegam na ORDEM certa
        // ao repositório. Se a chamada trocasse $ano por $mes (ou vice-versa), o mock rejeitaria
        // a chamada e o teste falharia — nenhum teste anterior provava essa ordem.
        $user = $this->userComJornada();
        $tenant = $this->tenant();

        $repo = $this->createMock(LancamentoHorasPagasRepository::class);
        $repo->expects(self::once())
            ->method('somarPorCompetencia')
            ->with($user, $tenant, 2026, 8)
            ->willReturn(-600);

        $builder = new FolhaPontoBuilder(
            new CalculadoraJornada(new JornadaResolver()),
            $this->createStub(RegistroPontoRepository::class),
            $this->createStub(JustificativaPontoRepository::class),
            $repo,
        );

        $saldo = $builder->somarHorasPagasDaCompetencia($user, $tenant, 2026, 8);

        self::assertSame(-600, $saldo);
    }

    // ──────────────────────────────────────────────────────────────────
    // buildRows — invariante: nunca é afetado pelo lançamento
    // ──────────────────────────────────────────────────────────────────

    #[TestDox('buildRows produz a mesma serie de saldoDia e saldoAcumulado com ou sem lancamento')]
    public function testBuildRowsNaoEAfetado(): void
    {
        // Jornada com dias úteis REAIS (não a neutra usada nos testes de saldo acima): com
        // saldoDia sempre 0, comparar "com vs. sem lançamento" seria trivial. Com dias úteis de
        // verdade, abril/2026 (mês fechado no passado) gera saldoDia/saldoAcumulado não-triviais,
        // e a comparação pega qualquer desvio — não só um valor exato de -6000.
        $jornada = $this->jornadaComDiasUteis();

        $buildRowsDeAbril = static fn (FolhaPontoBuilder $builder): array => $builder->buildRows(
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

        $rowsSemLancamento = $buildRowsDeAbril($this->builderComLancamentos([]));
        $rowsComLancamento = $buildRowsDeAbril($this->builderComLancamentos([2026 => [4 => -6000]]));

        self::assertNotEmpty($rowsSemLancamento);
        self::assertCount(count($rowsSemLancamento), $rowsComLancamento);

        foreach ($rowsSemLancamento as $indice => $rowSemLancamento) {
            self::assertSame(
                $rowSemLancamento['saldoDia'],
                $rowsComLancamento[$indice]['saldoDia'],
                sprintf('saldoDia do dia %s não pode mudar com o lançamento', $rowSemLancamento['diaMes']),
            );
            self::assertSame(
                $rowSemLancamento['saldoAcumulado'],
                $rowsComLancamento[$indice]['saldoAcumulado'],
                sprintf('saldoAcumulado do dia %s não pode mudar com o lançamento', $rowSemLancamento['diaMes']),
            );
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Interação real: saldo de batidas + lançamento na MESMA chamada
    // ──────────────────────────────────────────────────────────────────

    #[TestDox('saldo de batidas reais e o lancamento se somam na mesma chamada')]
    public function testSaldoComBatidasReaisSomaComLancamento(): void
    {
        // Mês fechado no passado (janeiro/2020) para não depender da data do relógio.
        // diasSemana=[4] (só quinta) isola um único dia útil no intervalo contado:
        // 30/01/2020 é quinta (com batida, déficit real de 180 min); 31/01/2020 é sexta
        // (não é dia útil, não conta mesmo sem batida).
        $jornada = $this->jornadaComDiasUteis([4]);

        $userComJornadaReal = $this->createStub(User::class);
        $userComJornadaReal->method('getJornadaColaborador')->willReturn($jornada);

        $entrada = new RegistroPonto();
        $entrada->setTipo(RegistroPonto::TIPO_ENTRADA);
        $entrada->setDataHora(new \DateTimeImmutable('2020-01-30 09:00:00'));

        $saida = new RegistroPonto();
        $saida->setTipo(RegistroPonto::TIPO_SAIDA);
        $saida->setDataHora(new \DateTimeImmutable('2020-01-30 14:00:00')); // 5h = 300 min

        $registroRepo = $this->createStub(RegistroPontoRepository::class);
        $registroRepo->method('findByUserAndCompetencia')->willReturn([$entrada, $saida]);

        $lancamentoRepo = $this->createStub(LancamentoHorasPagasRepository::class);
        $lancamentoRepo->method('somarPorPeriodo')->willReturnCallback(
            static fn (User $u, Tenant $t, int $ano, int $mesInicial, int $mesFinal): int
                => ($ano === 2020 && $mesInicial <= 1 && $mesFinal >= 1) ? -300 : 0
        );

        $builder = new FolhaPontoBuilder(
            new CalculadoraJornada(new JornadaResolver()),
            $registroRepo,
            $this->createStub(JustificativaPontoRepository::class),
            $lancamentoRepo,
        );

        $saldo = $builder->calcularSaldoAteMes(
            $userComJornadaReal,
            2020,
            1,
            [],
            null,
            new \DateTimeImmutable('2020-01-30'),
            $this->tenant(),
        );

        // -180 (déficit real de 30/01: trabalhou 300 min de 480 esperados) + -300 (lançamento) = -480
        self::assertSame(-480, $saldo, 'o saldo de batidas reais e o lançamento têm de se somar na mesma chamada');
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
        // Os agregadores usam somarPorPeriodo (uma query por intervalo, não uma por mês). O stub
        // reproduz a mesma semântica somando o intervalo INCLUSIVE nas duas pontas — se o builder
        // pedir um intervalo errado, o valor esperado do teste não fecha.
        $repo->method('somarPorPeriodo')->willReturnCallback(
            static function (User $u, Tenant $t, int $ano, int $mesInicial, int $mesFinal) use ($porAnoMes): int {
                $total = 0;
                for ($mes = $mesInicial; $mes <= $mesFinal; $mes++) {
                    $total += $porAnoMes[$ano][$mes] ?? 0;
                }

                return $total;
            }
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

    /**
     * Jornada com dia(s) útil(eis) REAL(is) configurado(s) — ao contrário de jornadaSimples()
     * (neutra), esta gera saldoDia/saldoAcumulado de verdade. Usada só onde o teste precisa
     * discriminar contribuição real de batidas (buildRows e a interação com lançamentos).
     *
     * @param int[] $diasSemana dias da semana (1=segunda .. 7=domingo) considerados úteis
     */
    private function jornadaComDiasUteis(array $diasSemana = [1, 2, 3, 4, 5]): JornadaColaborador
    {
        $user = new User();
        $user->setEmail('real@t.com')->setFullName('Real');

        $jornada = new JornadaColaborador();
        $jornada->setUser($user);
        $jornada->setDiasSemana($diasSemana);
        $jornada->setCargaHorariaDiaria(480);
        $jornada->setEntrada1('09:00');
        $jornada->setSaida1('12:00');
        $jornada->setEntrada2('13:00');
        $jornada->setSaida2('18:00');

        return $jornada;
    }
}
