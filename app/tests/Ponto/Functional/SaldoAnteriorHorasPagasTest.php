<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\JornadaTenant;
use App\Ponto\Entity\LancamentoHorasPagas;
use App\Ponto\Service\FolhaPontoBuilder;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * As duas telas que mostram banco de horas têm de mostrar o MESMO número.
 *
 * O painel `/ponto` passa por `calcularSaldoAnual`; o "Saldo anterior" do espelho/PDF/XLSX passa
 * por `calcularSaldoAteMes`, dentro de `PontoController::montarDadosFolha`. Enquanto essa segunda
 * chamada era condicionada a `$jornada !== null`, um colaborador SEM `JornadaColaborador` via
 * -10h00 numa tela e 0h00 na outra — 600 minutos de diferença no mesmo sistema, para o mesmo mês.
 *
 * Este teste ancora o cenário exato: colaborador com jornada só do escritório, lançamento de
 * -600 min, e os dois caminhos batendo.
 */
#[CoversClass(PontoController::class)]
final class SaldoAnteriorHorasPagasTest extends JusPrimeWebTestCase
{
    #[TestDox('colaborador sem JornadaColaborador ve o mesmo lancamento no painel e no saldo anterior do espelho')]
    public function testColaboradorSemJornadaTemOMesmoSaldoNasDuasTelas(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenantComJornada();
        $colaborador = $this->criarColaboradorSemJornada($tenant);
        $autor = $this->criarColaboradorSemJornada($tenant);
        $anoAtual = (int) (new \DateTimeImmutable())->format('Y');

        // O lançamento NUNCA pode cair no mês corrente: `/ponto` resolve a competência exibida para
        // o mês corrente por padrão, e o rodapé "Horas pagas" (Tarefa 6) formata o valor com a MESMA
        // string ("-10h00m") que o card "Banco de horas" deste teste. Se coincidissem (lançamento
        // feito em janeiro rodando a suíte em janeiro, por exemplo), a asserção do painel passaria
        // por causa do rodapé, mesmo com `calcularSaldoAnual` quebrado — verde por sorte de
        // calendário. Escolher um mês sempre diferente do corrente fecha essa brecha.
        $mesAtual = (int) (new \DateTimeImmutable())->format('n');
        $mesLancamento = $mesAtual === 1 ? 2 : 1;
        $this->criarLancamento($tenant, $colaborador, $autor, $anoAtual, $mesLancamento, -600);

        // Caminho 1 — painel do colaborador (calcularSaldoAnual).
        $this->logarComTenant($client, $colaborador, $tenant);
        $client->request('GET', '/ponto/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            '-10h00m',
            (string) $client->getResponse()->getContent(),
            'o painel tem de refletir o lançamento mesmo sem jornada individual configurada',
        );

        // Caminho 2 — "Saldo anterior" do espelho/PDF/XLSX (calcularSaldoAteMes), pelo método
        // privado que os dois exportadores usam, no mês seguinte ao do lançamento.
        $dados = $this->montarDadosFolha($colaborador, $tenant, $anoAtual, $mesLancamento + 1);

        self::assertSame(
            '-10:00',
            $dados['saldoBancoAnterior'],
            'o saldo anterior não pode zerar as horas pagas de quem não tem JornadaColaborador',
        );

        // Caminho 3 — o lançamento da PRÓPRIA competência exibida tem de entrar no "Saldo do Banco
        // de Horas Atual" do bloco assinado. Colaborador sem jornada e sem batida: o saldo do mês é
        // nulo e o anterior é zero, então o -600 do lançamento é a única parcela — se ele fosse
        // descartado no caminho, o campo sairia '+0:00' em vez de '-10:00'.
        $dadosDoMesDoLancamento = $this->montarDadosFolha($colaborador, $tenant, $anoAtual, $mesLancamento);
        self::assertSame(
            '-10:00',
            $dadosDoMesDoLancamento['saldoBancoAtual'],
            'o lançamento da competência exibida tem de entrar no saldo atual do bloco assinado',
        );
        self::assertSame(
            '10:00',
            $dadosDoMesDoLancamento['horasACompensar'],
            'e a compensação cobrada acompanha o acumulado negativo',
        );
    }

    /**
     * Chama o `montarDadosFolha` privado do controller — é ele que o PDF e o XLSX consomem, e a
     * linha corrigida vive lá dentro. Testar pela rota exigiria abrir um PDF ou um XLSX para ler
     * um número que este acesso direto entrega exato.
     *
     * @return array<string, mixed>
     */
    private function montarDadosFolha(User $colaborador, Tenant $tenant, int $ano, int $mes): array
    {
        $container  = static::getContainer();
        $controller = $container->get(PontoController::class);
        $builder    = $container->get(FolhaPontoBuilder::class);

        $metodo = new \ReflectionMethod($controller, 'montarDadosFolha');

        /** @var array<string, mixed> $dados */
        $dados = $metodo->invoke($controller, $colaborador, $tenant, $ano, $mes, [], [], null, null, $builder, null);

        return $dados;
    }

    private function criarTenantComJornada(): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant SALDO ANTERIOR ' . uniqid());
        $em->persist($tenant);

        $jornadaTenant = new JornadaTenant();
        $jornadaTenant->setTenant($tenant);
        $em->persist($jornadaTenant);
        $em->flush();

        return $tenant;
    }

    /** Colaborador com acesso ao módulo Ponto, mas SEM JornadaColaborador própria. */
    private function criarColaboradorSemJornada(Tenant $tenant): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('colab_' . uniqid() . '@test.com');
        $user->setFullName('Colaborador Sem Jornada');
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

        self::assertNull($user->getJornadaColaborador(), 'o cenário exige colaborador sem jornada individual');

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
        $lancamento->setMotivo('Horas pagas na folha');
        $lancamento->setCriadoPor($autor);
        $lancamento->setCriadoEm(new \DateTimeImmutable());
        $em->persist($lancamento);
        $em->flush();
    }
}
