<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Integration;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Exception\BatidaEmHorarioFuturoException;
use App\Ponto\Service\BatidaJaRegistrada;
use App\Ponto\UseCase\RegistrarBatidaDoEsquecimentoUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Regras do UseCase que grava a batida do esquecimento abonado. Usa o EntityManager real (o UseCase só
 * persiste) e escritórios gravados, porque a guarda de tenant compara ids.
 */
#[CoversClass(RegistrarBatidaDoEsquecimentoUseCase::class)]
final class RegistrarBatidaDoEsquecimentoUseCaseTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private EntityManagerInterface $em;
    private RegistrarBatidaDoEsquecimentoUseCase $useCase;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em      = static::getContainer()->get(EntityManagerInterface::class);
        $this->useCase = static::getContainer()->get(RegistrarBatidaDoEsquecimentoUseCase::class);
    }

    #[TestDox('Esquecimento completo vira batida com a data do pedido, a hora esquecida e o tipo pedido')]
    public function testEsquecimentoViraBatida(): void
    {
        $tenant = $this->criarTenant();
        $justificativa = $this->justificativa($tenant, 'esquecimento_registro', 'retorno', '13:15');

        $registro = ($this->useCase)($justificativa, $tenant);

        self::assertInstanceOf(RegistroPonto::class, $registro);
        self::assertSame('2026-03-10 13:15:00', $registro->getDataHora()?->format('Y-m-d H:i:s'));
        self::assertSame('retorno', $registro->getTipo());
        self::assertSame($tenant, $registro->getTenant());
        self::assertSame(RegistrarBatidaDoEsquecimentoUseCase::OBSERVACAO, $registro->getObservacao());
        self::assertTrue($this->em->contains($registro), 'a batida fica persistida para o flush de quem chama');
    }

    #[TestDox('Justificativa que não é esquecimento, ou sem tipo ou hora, não gera batida')]
    public function testSemEsquecimentoCompletoNaoGeraBatida(): void
    {
        $tenant = $this->criarTenant();

        self::assertNull(($this->useCase)($this->justificativa($tenant, 'atestado_medico', null, null), $tenant));
        self::assertNull(($this->useCase)($this->justificativa($tenant, 'esquecimento_registro', null, '08:00'), $tenant));
        self::assertNull(($this->useCase)($this->justificativa($tenant, 'esquecimento_registro', 'entrada', null), $tenant));
    }

    #[TestDox('Justificativa de outro escritório é recusada')]
    public function testOutroEscritorioERecusado(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();

        $this->expectException(\DomainException::class);
        ($this->useCase)($this->justificativa($tenantB, 'esquecimento_registro', 'entrada', '08:00'), $tenantA);
    }

    #[TestDox('Pedido com data e hora acima de agora no relógio do servidor é recusado e não grava batida')]
    public function testHorarioFuturoERecusado(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-03-10 13:00:00', new \DateTimeZone(date_default_timezone_get())));
        $tenant = $this->criarTenant();
        $futura = $this->justificativa($tenant, 'esquecimento_registro', 'saida', '13:01');

        self::assertTrue($this->useCase->pedeHorarioFuturo($futura));
        self::assertFalse($this->useCase->pedeHorarioFuturo($this->justificativa($tenant, 'esquecimento_registro', 'saida', '13:00')));

        $this->expectException(BatidaEmHorarioFuturoException::class);
        ($this->useCase)($futura, $tenant);
    }

    #[TestDox('Guarda do __invoke: com a batida do mesmo tipo já registrada naquele dia, escritório e pessoa, devolve null e não grava nada (spec ponto-esquecimento-nao-trava R1)')]
    public function testBatidaJaRegistradaNaoGrava(): void
    {
        $tenant = $this->criarTenant();
        $pessoa = new User();
        $pessoa->setEmail('esquecimento_guarda_' . uniqid() . '@test.com');
        $pessoa->setFullName('Guarda do esquecimento');
        $pessoa->setRoles(['ROLE_USER']);
        $pessoa->setPassword('x');
        $this->em->persist($pessoa);
        $existente = new RegistroPonto();
        $existente->setUser($pessoa);
        $existente->setTenant($tenant);
        $existente->setTipo('retorno');
        $existente->setDataHora(new \DateTime('2026-03-10 13:00:00'));
        $this->em->persist($existente);
        $this->em->flush();
        $justificativa = $this->justificativa($tenant, 'esquecimento_registro', 'retorno', '13:15');
        $justificativa->setUser($pessoa);

        self::assertSame(sprintf(BatidaJaRegistrada::MENSAGEM, 'retorno'), $this->useCase->recusa($justificativa, $tenant));
        self::assertNull(($this->useCase)($justificativa, $tenant));
        $this->em->flush();
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM registro_ponto WHERE user_id = ? AND tenant_id = ?',
            [$pessoa->getId(), $tenant->getId()],
        ), 'só a batida que já existia');
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant ESQUECIMENTO ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function justificativa(Tenant $tenant, string $tipo, ?string $tipoRegistro, ?string $hora): JustificativaPonto
    {
        $justificativa = new JustificativaPonto();
        $justificativa->setTenant($tenant);
        $justificativa->setData(new \DateTime('2026-03-10'));
        $justificativa->setStatus('abonado');
        $justificativa->setTipo($tipo);
        $justificativa->setTipoRegistroEsquecido($tipoRegistro);
        $justificativa->setHoraRegistroEsquecido($hora !== null ? new \DateTime('1970-01-01 ' . $hora . ':00') : null);

        return $justificativa;
    }
}
