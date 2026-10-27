<?php

declare(strict_types=1);

namespace App\Tests\ServiceDesk\Functional;

use App\Entity\Tenant\Tenant;
use App\ServiceDesk\DTO\ConfirmarRecargaInput;
use App\ServiceDesk\DTO\SolicitarRecargaInput;
use App\ServiceDesk\Entity\MovimentoDeSaldo;
use App\ServiceDesk\Entity\SaldoDoEscritorio;
use App\ServiceDesk\Enum\FormaDeRecarga;
use App\ServiceDesk\Enum\ResultadoDaConfirmacao;
use App\ServiceDesk\Pagamento\RecargaManual;
use App\ServiceDesk\Repository\MovimentoDeSaldoRepository;
use App\ServiceDesk\Repository\SaldoDoEscritorioRepository;
use App\ServiceDesk\Service\TransacaoDoRazaoNoDoctrine;
use App\ServiceDesk\UseCase\ConfirmarRecargaDeSaldoUseCase;
use App\ServiceDesk\UseCase\ConsultarSaldoDoEscritorioUseCase;
use App\ServiceDesk\UseCase\SolicitarRecargaDeSaldoUseCase;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Razão do saldo ponta a ponta no banco: pedir, confirmar, consultar e isolar por escritório. */
#[CoversNothing]
final class RazaoDoSaldoRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('recarga pendente não vira saldo; confirmada vira, uma vez só')]
    public function testRecargaPendenteEDepoisConfirmada(): void
    {
        $tenant = $this->criarTenant();
        $referencia = $this->pedirRecarga($tenant, 5_000);

        $antes = $this->consultar()->executar($tenant);
        self::assertSame(0, $antes->saldoCentavos);
        self::assertSame(5_000, $antes->pendenteCentavos);
        self::assertFalse($antes->podeAbrirChamado);

        $confirmar = $this->confirmar();
        self::assertTrue($confirmar->executar(new ConfirmarRecargaInput($referencia, 5_000, 'E2E-A'))->creditou());
        self::assertSame(ResultadoDaConfirmacao::JaConfirmada, $confirmar->executar(new ConfirmarRecargaInput($referencia, 5_000, 'E2E-A'))->resultado);

        $depois = $this->consultar()->executar($tenant);
        self::assertSame(5_000, $depois->saldoCentavos);
        self::assertSame(0, $depois->pendenteCentavos);
        self::assertTrue($depois->podeAbrirChamado);
        self::assertCount(1, $depois->extrato);
    }

    #[TestDox('saldo e extrato de um escritório não aparecem para outro')]
    public function testIsolamentoEntreEscritorios(): void
    {
        $a = $this->criarTenant();
        $b = $this->criarTenant();
        $referencia = $this->pedirRecarga($a, 2_000);
        $this->confirmar()
            ->executar(new ConfirmarRecargaInput($referencia, 2_000));

        $doB = $this->consultar()->executar($b);

        self::assertSame(0, $doB->saldoCentavos);
        self::assertSame([], $doB->extrato);
        self::assertSame(2_000, $this->consultar()->executar($a)->saldoCentavos);
    }

    #[TestDox('com o TenantFilter ligado em outro escritório, o Master ainda confirma a recarga e o filtro volta')]
    public function testConfirmaComFiltroDeOutroEscritorioLigado(): void
    {
        $a = $this->criarTenant();
        $b = $this->criarTenant();
        $referencia = $this->pedirRecarga($a, 2_000);
        $this->em->clear();

        $filtro = $this->em->getFilters()->enable('tenant');
        $filtro->setParameter('tenant', $b->getId(), Types::INTEGER);

        $out = $this->confirmar()->executar(new ConfirmarRecargaInput($referencia, 2_000, 'E2E-F'));

        self::assertSame(ResultadoDaConfirmacao::Confirmada, $out->resultado);
        self::assertTrue($this->em->getFilters()->isEnabled('tenant'), 'o filtro tem que voltar ligado');
        self::assertStringContainsString((string) $b->getId(), (string) $this->em->getFilters()->getFilter('tenant')->getParameter('tenant'));
    }

    #[TestDox('divergência fica gravada e a recarga segue pendente; referência desconhecida não derruba o EntityManager')]
    public function testDivergenciaENaoEncontradaNaoFecham(): void
    {
        $tenant = $this->criarTenant();
        $referencia = $this->pedirRecarga($tenant, 5_000);

        $divergente = $this->confirmar()->executar(new ConfirmarRecargaInput($referencia, 4_000, 'E2E-D'));
        $naoAchou = $this->confirmar()->executar(new ConfirmarRecargaInput('NADA', 5_000));

        self::assertSame(ResultadoDaConfirmacao::ValorDivergente, $divergente->resultado);
        self::assertSame(ResultadoDaConfirmacao::NaoEncontrada, $naoAchou->resultado);
        self::assertTrue($this->em->isOpen());
        $saldo = $this->consultar()->executar($tenant);
        self::assertSame(0, $saldo->saldoCentavos);
        self::assertSame(5_000, $saldo->pendenteCentavos);
    }

    #[TestDox('o saldo do escritório nasce uma vez só, mesmo travando de novo')]
    public function testTravarDuasVezesNaoDuplicaOSaldo(): void
    {
        $tenant = $this->criarTenant();

        [$primeiro, $segundo] = $this->em->wrapInTransaction(fn (): array => [
            $this->saldos()->travarDoEscritorio($tenant),
            $this->saldos()->travarDoEscritorio($tenant),
        ]);

        self::assertSame($primeiro->getId(), $segundo->getId());
        self::assertSame(1, $this->saldos()->count(['tenant' => $tenant]));
    }

    #[TestDox('o saldo já carregado no EntityManager é relido ao travar (sem perder crédito)')]
    public function testTravarLeOSaldoJaCarregado(): void
    {
        $tenant = $this->criarTenant();
        $this->confirmar()->executar(new ConfirmarRecargaInput($this->pedirRecarga($tenant, 2_000), 2_000));
        $carregado = $this->saldos()->doEscritorio($tenant);
        self::assertSame(2_000, $carregado->getSaldoCentavos());
        $this->em->getConnection()->executeStatement('UPDATE service_desk_saldo SET saldo_centavos = 9000 WHERE tenant_id = ?', [$tenant->getId()]);

        $travado = $this->em->wrapInTransaction(fn () => $this->saldos()->travarDoEscritorio($tenant));

        self::assertSame(9_000, $travado->getSaldoCentavos());
    }

    #[TestDox('pago a mais credita o pago e o extrato mostra o que entrou')]
    public function testPagoAMaisCreditaOPago(): void
    {
        $tenant = $this->criarTenant();
        $referencia = $this->pedirRecarga($tenant, 5_000);

        $out = $this->confirmar()->executar(new ConfirmarRecargaInput($referencia, 5_250, 'E2E-M'));

        self::assertSame(250, $out->diferencaCentavos);
        $saldo = $this->consultar()->executar($tenant);
        self::assertSame(5_250, $saldo->saldoCentavos);
        self::assertSame(5_250, $saldo->extrato[0]->valorCentavos);
    }

    private function pedirRecarga(Tenant $tenant, int $centavos): string
    {
        return (new SolicitarRecargaDeSaldoUseCase($this->movimentos(), new RecargaManual()))
            ->executar(new SolicitarRecargaInput($tenant, null, $centavos, FormaDeRecarga::Pix))
            ->referencia;
    }

    private function consultar(): ConsultarSaldoDoEscritorioUseCase
    {
        return new ConsultarSaldoDoEscritorioUseCase($this->saldos(), $this->movimentos());
    }

    private function confirmar(): ConfirmarRecargaDeSaldoUseCase
    {
        return new ConfirmarRecargaDeSaldoUseCase($this->movimentos(), $this->saldos(), new TransacaoDoRazaoNoDoctrine($this->em));
    }

    private function movimentos(): MovimentoDeSaldoRepository
    {
        return $this->em->getRepository(MovimentoDeSaldo::class);
    }

    private function saldos(): SaldoDoEscritorioRepository
    {
        return $this->em->getRepository(SaldoDoEscritorio::class);
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }
}
