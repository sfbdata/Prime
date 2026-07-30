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
 * O lançamento de horas pagas entra no bloco assinado (PDF/XLSX) APENAS na linha própria
 * "Horas pagas". "Saldo do Banco de Horas Atual" e "Horas a Compensar" NÃO o somam.
 *
 * Decisão do dono, tomada na onda final de correção da frente, com a razão registrada aqui porque é
 * exatamente o ponto em que uma rodada anterior errou:
 *
 * `saldoBancoAtual`/`horasACompensar` derivam do `saldoAcumulado` da última linha da tabela, e
 * `buildRows` faz esse acumulado NASCER EM ZERO no primeiro dia do intervalo pedido — o dia 1º do mês
 * exportado. Apesar do rótulo, o número é o saldo DAQUELE MÊS, não o banco acumulado. Somar um
 * lançamento mensal a um saldo mensal que não representa o banco produz número sem significado:
 *
 *   colaborador acumula 100h de banco → o escritório paga as 100h em dinheiro → o admin lança -6000
 *   em agosto → agosto foi trabalhado na jornada exata (saldo do mês = 0) → o papel dizia
 *   "Horas a Compensar: 100:00", cobrando de volta o que acabou de ser pago.
 *
 * O rótulo enganoso é preexistente e continua; corrigi-lo muda o PDF de todos os escritórios e é
 * frente própria. O terceiro cenário abaixo é o que impede a soma de voltar.
 */
#[CoversClass(PontoController::class)]
final class HorasPagasTotalAssinadoTest extends JusPrimeWebTestCase
{
    #[TestDox('desconto no lancamento nao mexe no saldo atual nem nas horas a compensar do bloco assinado')]
    public function testDescontoNaoEntraNoTotalDoBlocoAssinado(): void
    {
        // Batida do mês rende +120min (saldoAcumulado da última linha); lançamento desconta -600min.
        // O bloco assinado continua reportando o saldo do MÊS (+2:00), sem compensação a cobrar; o
        // desconto aparece só na linha "Horas pagas".
        $tenant = $this->criarTenant();
        $colaborador = $this->criarColaborador($tenant);
        $autor = $this->criarColaborador($tenant);
        $ano = 2026;
        $mes = 1;

        $this->criarLancamento($tenant, $colaborador, $autor, $ano, $mes, -600);

        $dados = $this->montarDadosFolha($colaborador, $tenant, $ano, $mes, $this->folhaRowsComSaldoAcumulado($ano, $mes, 120));

        self::assertSame(
            '+2:00',
            $dados['saldoBancoAtual'],
            'o saldo atual é o do mês (batidas: +120min); o lançamento não pode alterá-lo',
        );
        self::assertSame(
            '–',
            $dados['horasACompensar'],
            'saldo do mês positivo não gera compensação — o desconto não pode criar uma',
        );
        self::assertSame(
            -600,
            $dados['horasPagasMinutos'],
            'o desconto tem de aparecer na linha própria "Horas pagas"',
        );
    }

    #[TestDox('bonificacao no lancamento nao mexe no saldo atual nem nas horas a compensar do bloco assinado')]
    public function testBonificacaoNaoEntraNoTotalDoBlocoAssinado(): void
    {
        // Batida do mês fecha em -600min (deficit); lançamento acrescenta +480min de bonificação.
        // O bloco assinado continua reportando o deficit do MÊS (-10:00 / 10:00 a compensar); a
        // bonificação aparece só na linha "Horas pagas".
        $tenant = $this->criarTenant();
        $colaborador = $this->criarColaborador($tenant);
        $autor = $this->criarColaborador($tenant);
        $ano = 2026;
        $mes = 1;

        $this->criarLancamento($tenant, $colaborador, $autor, $ano, $mes, 480);

        $dados = $this->montarDadosFolha($colaborador, $tenant, $ano, $mes, $this->folhaRowsComSaldoAcumulado($ano, $mes, -600));

        self::assertSame(
            '-10:00',
            $dados['saldoBancoAtual'],
            'o saldo atual é o do mês (batidas: -600min); a bonificação não pode alterá-lo',
        );
        self::assertSame(
            '10:00',
            $dados['horasACompensar'],
            'a compensação cobrada é a do deficit do mês, sem interferência do lançamento',
        );
        self::assertSame(
            480,
            $dados['horasPagasMinutos'],
            'a bonificação tem de aparecer na linha própria "Horas pagas"',
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
            -6000,
            $dados['horasPagasMinutos'],
            'o pagamento aparece na linha "Horas pagas" de agosto',
        );
        self::assertSame(
            '+0:00',
            $dados['saldoBancoAtual'],
            'agosto foi trabalhado na jornada exata: o saldo do mês é zero e o pagamento não o mexe',
        );
        self::assertSame(
            '–',
            $dados['horasACompensar'],
            'o colaborador NÃO pode ser cobrado a compensar as horas que acabou de receber em dinheiro',
        );
    }

    /**
     * Não-regressão para quem nunca usou a feature: sem nenhum lançamento, os quatro campos do bloco
     * assinado têm de sair exatamente como saíam antes da frente — derivados só das batidas.
     */
    #[DataProvider('saldosSemLancamento')]
    #[TestDox('sem lancamento nenhum, o bloco assinado sai identico ao de hoje')]
    public function testSemLancamentoOsCamposDoBlocoAssinadoNaoMudam(
        ?int $saldoAcumulado,
        string $saldoBancoAtualEsperado,
        string $horasACompensarEsperado,
    ): void {
        $tenant = $this->criarTenant();
        $colaborador = $this->criarColaborador($tenant);
        $ano = 2026;
        $mes = 1;

        $dados = $this->montarDadosFolha($colaborador, $tenant, $ano, $mes, $this->folhaRowsComSaldoAcumulado($ano, $mes, $saldoAcumulado));

        self::assertSame($saldoBancoAtualEsperado, $dados['saldoBancoAtual'], 'saldo atual sem lançamento vem só das batidas');
        self::assertSame($horasACompensarEsperado, $dados['horasACompensar'], 'horas a compensar sem lançamento vêm só das batidas');
        self::assertSame('+0:00', $dados['saldoBancoAnterior'], 'saldo anterior sem batida nem lançamento é zero');
        self::assertSame(0, $dados['horasPagasMinutos'], 'sem lançamento a linha "Horas pagas" não aparece');
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
            'mes sem saldo apurado'   => [null, '–', '–'],
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
