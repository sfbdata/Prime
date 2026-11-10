<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Lotacao;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\BlocoJornadaColaborador;
use App\Ponto\Entity\Feriado;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Service\VerificadorAlertaPonto;
use App\Tests\Central\Support\CentralHttpTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * O aviso de ponto (/ponto/alerta-horario) segue o feriado de quem está vendo (E6, `FeriadosDaPessoa`): o de todas as
 * sedes e o da lotação da pessoa calam o aviso de entrada; o de outra lotação não. O dia que a escala não cobre
 * (folga, domingo) também o cala. O resto da regra (o que sai e o que fica no dia sem escala) está no teste de unidade
 * do `VerificadorAlertaPonto`.
 */
#[Group('ponto')]
#[CoversClass(PontoController::class)]
#[CoversClass(VerificadorAlertaPonto::class)]
final class AlertaDeHorarioNoFeriadoTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;
    use CentralHttpTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = $this->cliente();
    }

    #[TestDox('Sem feriado: sem entrada hoje, o aviso pede a entrada')]
    public function testSemFeriado(): void
    {
        [$ana, $tenant] = $this->colaboradoraComAviso();

        self::assertSame('entrada', $this->aviso($ana, $tenant)['tipo']);
    }

    #[TestDox('Feriado de todas as sedes hoje: sem aviso')]
    public function testFeriadoDeTodasAsSedes(): void
    {
        [$ana, $tenant] = $this->colaboradoraComAviso();
        $this->feriadoDeHoje($tenant, null);

        self::assertFalse($this->aviso($ana, $tenant)['alertar']);
    }

    #[TestDox('Feriado da lotação da pessoa hoje: sem aviso')]
    public function testFeriadoDaPropriaLotacao(): void
    {
        [$ana, $tenant] = $this->colaboradoraComAviso();
        $maceio = $this->lotacao('Maceió', $tenant);
        $this->lotar($ana, $tenant, $maceio);
        $this->feriadoDeHoje($tenant, $maceio);

        self::assertFalse($this->aviso($ana, $tenant)['alertar']);
    }

    #[TestDox('Feriado só de outra lotação hoje: não vale para a pessoa, o aviso pede a entrada')]
    public function testFeriadoDeOutraLotacao(): void
    {
        [$ana, $tenant] = $this->colaboradoraComAviso();
        $this->lotar($ana, $tenant, $this->lotacao('Maceió', $tenant));
        $this->feriadoDeHoje($tenant, $this->lotacao('Brasília', $tenant));

        self::assertSame('entrada', $this->aviso($ana, $tenant)['tipo'], 'o feriado de Brasília não é de quem está em Maceió');
    }

    #[TestDox('Dia fora da escala (a escala cobre os outros dias da semana): sem aviso')]
    public function testDiaForaDaEscala(): void
    {
        $hoje = (int) (new \DateTimeImmutable())->format('N');
        [$ana, $tenant] = $this->colaboradoraComAviso(array_values(array_diff([1, 2, 3, 4, 5, 6, 7], [$hoje])));

        self::assertFalse($this->aviso($ana, $tenant)['alertar']);
    }

    /**
     * @param list<int> $dias os dias da semana da escala (1 = segunda)
     *
     * @return array{0: User, 1: Tenant}
     */
    private function colaboradoraComAviso(array $dias = [1, 2, 3, 4, 5, 6, 7]): array
    {
        [, $tenant] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($tenant, ['modules.ponto.view'], 'ana');
        $jornada = (new JornadaColaborador())->setUser($ana)->setTenant($tenant)->setAlertaHabilitado(true);
        $jornada->addBloco((new BlocoJornadaColaborador())->setDiasSemana($dias)->setEntrada('08:00')
            ->setRepouso('12:00')->setRetorno('13:00')->setSaida('17:00')->setMinutosBloco(480));
        $this->em()->persist($jornada);
        $this->em()->flush();

        return [$ana, $tenant];
    }

    /** @return array<string, mixed> */
    private function aviso(User $quem, Tenant $tenant): array
    {
        $this->logarComTenant($this->client, $quem, $tenant);
        $this->client->request('GET', '/ponto/alerta-horario');
        self::assertResponseIsSuccessful();

        $dados = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($dados);

        return $dados;
    }

    private function lotacao(string $nome, Tenant $tenant): Lotacao
    {
        $lotacao = (new Lotacao())->setNome($nome)->setTenant($tenant);
        $this->em()->persist($lotacao);
        $this->em()->flush();

        return $lotacao;
    }

    private function lotar(User $pessoa, Tenant $tenant, Lotacao $lotacao): void
    {
        $vinculo = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $pessoa, 'tenant' => $tenant]);
        self::assertNotNull($vinculo);
        $vinculo->setLotacao($lotacao);
        $this->em()->flush();
    }

    private function feriadoDeHoje(Tenant $tenant, ?Lotacao $lotacao): void
    {
        $feriado = (new Feriado())->setNome('Feriado de teste')->setData(new \DateTime('today'))->setRecorrente(false)
            ->setTenant($tenant)->setLotacao($lotacao);
        $this->em()->persist($feriado);
        $this->em()->flush();
    }
}
