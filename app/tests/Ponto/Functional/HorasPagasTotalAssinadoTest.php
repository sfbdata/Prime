<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\LancamentoHorasPagas;
use App\Ponto\Service\FolhaPontoBuilder;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * "Saldo do Banco de Horas Atual" do bloco assinado (PDF/XLSX) é o banco ACUMULADO:
 *
 *     anterior (calcularSaldoAteMes) + saldo do mês (buildRows) + horas pagas da competência
 *
 * e "Horas a Compensar" é o módulo dele quando negativo. Não existe linha própria "Horas pagas" no
 * bloco assinado — o lançamento já está dentro do total.
 *
 * Decisão do dono em 2026-07-31, depois do smoke. Antes disso o campo era só o `saldoAcumulado` da
 * última linha da tabela, e `buildRows` faz esse acumulado NASCER EM ZERO no dia 1º do intervalo
 * pedido: apesar do rótulo, era o saldo DAQUELE MÊS, ao lado de um "Saldo Anterior" que sempre foi
 * acumulado. Dois rótulos irmãos medindo coisas diferentes.
 *
 * Era essa base errada que tornava a soma perigosa — o cenário do caso de uso nº 1 (terceiro teste)
 * é o que prova a diferença: colaborador acumula 100h, recebe as 100h em dinheiro, o admin lança
 * -6000 em agosto, agosto é trabalhado na jornada exata. Somando ao saldo MENSAL o papel dizia
 * "Horas a Compensar: 100:00", cobrando de volta o que acabou de ser pago; com a base acumulada
 * fecha em zero, que é o devido.
 *
 * Sem contagem dupla: `calcularSaldoAteMes` cobre as competências ANTERIORES e
 * `somarHorasPagasDaCompetencia` cobre a ATUAL — faixas que não se sobrepõem.
 */
#[CoversClass(PontoController::class)]
final class HorasPagasTotalAssinadoTest extends JusPrimeWebTestCase
{
    #[TestDox('desconto no lancamento entra no saldo atual acumulado e cria a compensacao correspondente')]
    public function testDescontoEntraNoTotalAcumulado(): void
    {
        // Sem competência anterior: anterior = 0. Batida do mês rende +120min; lançamento desconta
        // -600min. Acumulado = 0 + 120 - 600 = -480 => -8:00, com 8:00 a compensar.
        $tenant = $this->criarTenant();
        $colaborador = $this->criarColaborador($tenant);
        $autor = $this->criarColaborador($tenant);
        $ano = 2026;
        $mes = 1;

        $this->criarLancamento($tenant, $colaborador, $autor, $ano, $mes, -600);

        $dados = $this->montarDadosFolha($colaborador, $tenant, $ano, $mes, $this->folhaRowsComSaldoAcumulado($ano, $mes, 120));

        self::assertSame(
            '+0:00',
            $dados['saldoBancoAnterior'],
            'não há competência anterior: o acumulado começa em zero',
        );
        self::assertSame(
            '-8:00',
            $dados['saldoBancoAtual'],
            'o saldo atual é acumulado: 0 (anterior) + 120 (mês) - 600 (pago) = -480min',
        );
        self::assertSame(
            '8:00',
            $dados['horasACompensar'],
            'o acumulado ficou negativo, então há compensação a cobrar — e ela é o módulo dele',
        );
        self::assertArrayNotHasKey(
            'horasPagasMinutos',
            $dados,
            'o bloco assinado não tem mais linha própria "Horas pagas" — o valor já está no total',
        );
    }

    #[TestDox('bonificacao no lancamento reduz a compensacao cobrada no bloco assinado')]
    public function testBonificacaoEntraNoTotalAcumulado(): void
    {
        // Sem competência anterior: anterior = 0. Batida do mês fecha em -600min; lançamento
        // acrescenta +480min de bonificação. Acumulado = 0 - 600 + 480 = -120 => -2:00, 2:00 a
        // compensar (em vez das 10:00 que o deficit do mês sozinho cobraria).
        $tenant = $this->criarTenant();
        $colaborador = $this->criarColaborador($tenant);
        $autor = $this->criarColaborador($tenant);
        $ano = 2026;
        $mes = 1;

        $this->criarLancamento($tenant, $colaborador, $autor, $ano, $mes, 480);

        $dados = $this->montarDadosFolha($colaborador, $tenant, $ano, $mes, $this->folhaRowsComSaldoAcumulado($ano, $mes, -600));

        self::assertSame(
            '-2:00',
            $dados['saldoBancoAtual'],
            'o saldo atual é acumulado: 0 (anterior) - 600 (mês) + 480 (bonificação) = -120min',
        );
        self::assertSame(
            '2:00',
            $dados['horasACompensar'],
            'a bonificação abate a compensação cobrada: 10:00 viram 2:00',
        );
    }

    /**
     * O caso de uso nº 1 da spec, ponta a ponta — é ele que a soma revertida quebrava.
     */
    #[TestDox('100h acumuladas e pagas em dinheiro nao viram compensacao cobrada do colaborador')]
    public function testHorasPagasDeBancoAcumuladoNaoViramCompensacaoCobrada(): void
    {
        // Colaborador chega em agosto com +100h de banco (aqui construídas por um lançamento de
        // julho, que é o único jeito de acumular saldo sem jornada configurada — o "Saldo anterior"
        // passa por calcularSaldoAteMes, que soma os lançamentos das competências anteriores).
        // O escritório paga as 100h em dinheiro e lança -6000 em agosto. Agosto foi trabalhado na
        // jornada exata: saldo do mês = 0.
        //
        // Com a soma indevida, o papel dizia "Horas a Compensar: 100:00" — cobrando do funcionário
        // as horas que ele acabou de receber.
        $tenant = $this->criarTenant();
        $colaborador = $this->criarColaborador($tenant);
        $autor = $this->criarColaborador($tenant);
        $ano = 2025;

        $this->criarLancamento($tenant, $colaborador, $autor, $ano, 7, 6000);   // acúmulo até julho
        $this->criarLancamento($tenant, $colaborador, $autor, $ano, 8, -6000);  // pagamento em agosto

        $dados = $this->montarDadosFolha($colaborador, $tenant, $ano, 8, $this->folhaRowsComSaldoAcumulado($ano, 8, 0));

        self::assertSame(
            '+100:00',
            $dados['saldoBancoAnterior'],
            'o saldo anterior soma os lançamentos das competências anteriores (calcularSaldoAteMes)',
        );
        self::assertSame(
            '+0:00',
            $dados['saldoBancoAtual'],
            'o banco zera: +6000 (anterior) + 0 (agosto na jornada exata) - 6000 (pago) = 0',
        );
        self::assertSame(
            '–',
            $dados['horasACompensar'],
            'o colaborador NÃO pode ser cobrado a compensar as horas que acabou de receber em dinheiro',
        );
    }

    /**
     * Sem nenhum lançamento e sem competência anterior, o acumulado é só o saldo do mês — ou seja,
     * quem nunca usou a feature vê os mesmos números de antes.
     *
     * A exceção deliberada é a última linha: mês sem saldo apurado (nenhuma batida) exibia '–' e
     * passa a exibir '+0:00', porque o acumulado existe mesmo quando o mês não tem movimento. É a
     * consequência assumida de o campo deixar de ser mensal.
     */
    #[DataProvider('saldosSemLancamento')]
    #[TestDox('sem lancamento nenhum, o acumulado e o proprio saldo do mes')]
    public function testSemLancamentoOSaldoAtualEOSaldoDoMes(
        ?int $saldoAcumulado,
        string $saldoBancoAtualEsperado,
        string $horasACompensarEsperado,
    ): void {
        $tenant = $this->criarTenant();
        $colaborador = $this->criarColaborador($tenant);
        $ano = 2026;
        $mes = 1;

        $dados = $this->montarDadosFolha($colaborador, $tenant, $ano, $mes, $this->folhaRowsComSaldoAcumulado($ano, $mes, $saldoAcumulado));

        self::assertSame($saldoBancoAtualEsperado, $dados['saldoBancoAtual'], 'sem anterior e sem lançamento, o acumulado é o saldo do mês');
        self::assertSame($horasACompensarEsperado, $dados['horasACompensar'], 'a compensação é o módulo do acumulado quando ele é negativo');
        self::assertSame('+0:00', $dados['saldoBancoAnterior'], 'saldo anterior sem batida nem lançamento é zero');
    }

    /**
     * @return array<string, array{0: int|null, 1: string, 2: string}>
     */
    public static function saldosSemLancamento(): array
    {
        return [
            'saldo do mes positivo'   => [120, '+2:00', '–'],
            'saldo do mes negativo'   => [-600, '-10:00', '10:00'],
            'saldo do mes zerado'     => [0, '+0:00', '–'],
            'mes sem saldo apurado'   => [null, '+0:00', '–'],
        ];
    }

    /**
     * Folha de um único dia, com o `saldoAcumulado` da última (única) linha controlado por quem
     * chama — é exatamente o valor que o loop de `montarDadosFolha` usa como `saldoBancoAtualMinutos`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function folhaRowsComSaldoAcumulado(int $ano, int $mes, ?int $saldoAcumulado): array
    {
        $chaveDia = sprintf('%04d-%02d-15', $ano, $mes);

        return [
            [
                'diaMes' => 15,
                'diaSemana' => 'Quinta',
                'chaveDia' => $chaveDia,
                'entrada' => '09:00:00',
                'saida' => '11:00:00',
                'minutosTrabalhadosDia' => 120,
                'saldoDia' => $saldoAcumulado,
                'saldoAcumulado' => $saldoAcumulado,
                'minutosIntervalo' => null,
                'isFeriado' => false,
                'fimSemana' => false,
            ],
        ];
    }

    /**
     * Chama o `montarDadosFolha` privado do controller — é ele que o PDF e o XLSX consomem.
     *
     * @param array<int, array<string, mixed>> $folhaRows
     * @return array<string, mixed>
     */
    private function montarDadosFolha(User $colaborador, Tenant $tenant, int $ano, int $mes, array $folhaRows): array
    {
        $container  = static::getContainer();
        $controller = $container->get(PontoController::class);
        $builder    = $container->get(FolhaPontoBuilder::class);

        $metodo = new \ReflectionMethod($controller, 'montarDadosFolha');

        /** @var array<string, mixed> $dados */
        $dados = $metodo->invoke($controller, $colaborador, $tenant, $ano, $mes, $folhaRows, [], null, null, $builder, null);

        return $dados;
    }

    private function criarTenant(): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant TOTAL ASSINADO ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarColaborador(Tenant $tenant): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('colab_total_' . uniqid() . '@test.com');
        $user->setFullName('Colaborador Total Assinado');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Perfil ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }

    private function criarLancamento(Tenant $tenant, User $colaborador, User $autor, int $ano, int $mes, int $minutos): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $lancamento = new LancamentoHorasPagas();
        $lancamento->setTenant($tenant);
        $lancamento->setUser($colaborador);
        $lancamento->setAno($ano);
        $lancamento->setMes($mes);
        $lancamento->setMinutos($minutos);
        $lancamento->setMotivo('Lançamento de fixture');
        $lancamento->setCriadoPor($autor);
        $lancamento->setCriadoEm(new \DateTimeImmutable());
        $em->persist($lancamento);
        $em->flush();
    }
}
