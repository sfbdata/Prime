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
use PHPUnit\Framework\Attributes\TestDox;

/**
 * O total do bloco assinado (PDF/XLSX) tem de SOMAR as horas pagas da própria competência.
 *
 * Antes da correção, `saldoBancoAtual`/`horasACompensar` vinham só do `saldoAcumulado` da última
 * linha da tabela (batidas), ignorando por completo o lançamento do mês. Dois efeitos práticos:
 *
 * 1. O papel de um mês e o do mês seguinte não fechavam entre si — o "Saldo atual" de janeiro e o
 *    "Saldo anterior" de fevereiro (que já soma os lançamentos, via `calcularSaldoAteMes`)
 *    divergiam em exatos os minutos lançados.
 * 2. Num mês com BONIFICAÇÃO (lançamento positivo) e batidas deficitárias, o documento chegava a
 *    cobrar do colaborador uma compensação MAIOR do que a devida de verdade — dinheiro contra a
 *    pessoa que assina o papel.
 */
#[CoversClass(PontoController::class)]
final class HorasPagasTotalAssinadoTest extends JusPrimeWebTestCase
{
    #[TestDox('desconto no lancamento: saldo atual e horas a compensar somam o desconto, nao so a batida')]
    public function testDescontoEntraNoTotalDoBlocoAssinado(): void
    {
        // Batida do mês rende +120min (saldoAcumulado da última linha); lançamento desconta -600min.
        // Total real: 120 - 600 = -480min = -8:00. Antes da correção, o total ignorava o lançamento
        // e mostrava "+2:00" (só a batida) com "Horas a Compensar: –" (nenhuma, porque +120 é positivo).
        $tenant = $this->criarTenant();
        $colaborador = $this->criarColaborador($tenant);
        $autor = $this->criarColaborador($tenant);
        $ano = 2026;
        $mes = 1;

        $this->criarLancamento($tenant, $colaborador, $autor, $ano, $mes, -600);

        $dados = $this->montarDadosFolha($colaborador, $tenant, $ano, $mes, $this->folhaRowsComSaldoAcumulado(120));

        self::assertSame(
            '-8:00',
            $dados['saldoBancoAtual'],
            'o saldo atual tem de somar o desconto do lançamento (-600) ao saldo das batidas (+120): -480min = -8:00',
        );
        self::assertSame(
            '8:00',
            $dados['horasACompensar'],
            'com o total negativo, tem de aparecer "Horas a Compensar" (8:00), não "–"',
        );
    }

    #[TestDox('bonificacao no lancamento: a compensacao cobrada cai, nao pode continuar cobrando o valor cheio')]
    public function testBonificacaoReduzAHorasACompensarNoTotalDoBlocoAssinado(): void
    {
        // Batida do mês fecha em -600min (deficit); lançamento acrescenta +480min de bonificação.
        // Total real: -600 + 480 = -120min = -2:00 → só 2:00 de compensação devida. Antes da
        // correção, o total ignorava a bonificação e cobrava as 10:00 cheias da batida — dinheiro
        // contra o colaborador.
        $tenant = $this->criarTenant();
        $colaborador = $this->criarColaborador($tenant);
        $autor = $this->criarColaborador($tenant);
        $ano = 2026;
        $mes = 1;

        $this->criarLancamento($tenant, $colaborador, $autor, $ano, $mes, 480);

        $dados = $this->montarDadosFolha($colaborador, $tenant, $ano, $mes, $this->folhaRowsComSaldoAcumulado(-600));

        self::assertSame(
            '-2:00',
            $dados['saldoBancoAtual'],
            'o saldo atual tem de refletir a bonificação somada ao deficit da batida: -600+480 = -120min = -2:00',
        );
        self::assertSame(
            '2:00',
            $dados['horasACompensar'],
            'a bonificação tem de abater a compensação cobrada — o documento não pode cobrar as 10:00 cheias da batida',
        );
    }

    /**
     * Folha de um único dia, com o `saldoAcumulado` da última (única) linha controlado por quem
     * chama — é exatamente o valor que o loop de `montarDadosFolha` usa como `saldoBancoAtualMinutos`
     * antes de somar o lançamento.
     *
     * @return array<int, array<string, mixed>>
     */
    private function folhaRowsComSaldoAcumulado(int $saldoAcumulado): array
    {
        return [
            [
                'diaMes' => 15,
                'diaSemana' => 'Quinta',
                'chaveDia' => '2026-01-15',
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
