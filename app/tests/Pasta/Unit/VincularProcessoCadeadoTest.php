<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\TipoDeApenso;
use App\Pasta\Exception\ProcessoOcultoPeloCadeadoException;
use App\Pasta\Repository\CadeadoDaPastaRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\Service\NivelNoCadeado;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use App\Pasta\UseCase\VincularApensadoUseCase;
use App\Pasta\UseCase\VincularProcessoUseCase;
use App\Processo\Entity\Processo;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Tenant\Service\RegraDosPrimarios;
use App\Twig\NumeroProcessoExtension;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Achado 3.04 da auditoria (regra K3 do cadeado): o processo cujas pastas são TODAS ocultas para a pessoa
 * não abre para ela, então também não pode ser vinculado por ela a outra pasta (o vínculo daria uma pasta
 * visível ao processo e o abriria). Os dois UseCases de vínculo recusam; com uma pasta visível, seguem.
 */
#[CoversClass(VincularProcessoUseCase::class)]
#[CoversClass(VincularApensadoUseCase::class)]
final class VincularProcessoCadeadoTest extends TestCase
{
    private const PROCESSO_OCULTO = 70;
    private const PROCESSO_VISIVEL = 71;

    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->tenant  = new Tenant();
        $this->usuario = new User();
    }

    #[TestDox('Vincular: processo de pastas todas ocultas para a pessoa lança a exceção e não grava nada')]
    public function testVincularProcessoOcultoRecusa(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');
        $pasta = $this->pasta();

        try {
            (new VincularProcessoUseCase($em, $this->acesso([self::PROCESSO_OCULTO])))
                ->executar($pasta, $this->processo(self::PROCESSO_OCULTO), $this->usuario);
            self::fail('o processo oculto foi vinculado');
        } catch (ProcessoOcultoPeloCadeadoException) {
        }

        self::assertCount(0, $pasta->getPastaProcessos());
    }

    #[TestDox('Vincular: processo com pelo menos uma pasta visível segue vinculando')]
    public function testVincularProcessoComPastaVisivelSegue(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');
        $pasta = $this->pasta();

        (new VincularProcessoUseCase($em, $this->acesso([self::PROCESSO_OCULTO])))
            ->executar($pasta, $this->processo(self::PROCESSO_VISIVEL), $this->usuario);

        self::assertCount(1, $pasta->getPastaProcessos());
    }

    #[TestDox('Apensado: processo de pastas todas ocultas para a pessoa lança a exceção e não grava nada')]
    public function testApensadoOcultoRecusa(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');
        $em->expects(self::never())->method('persist');
        $pasta = $this->pasta();

        try {
            $this->apensado($em, [self::PROCESSO_OCULTO])
                ->executar($pasta, $this->processo(self::PROCESSO_OCULTO), TipoDeApenso::Embargos, $this->usuario);
            self::fail('o processo oculto foi apensado');
        } catch (ProcessoOcultoPeloCadeadoException) {
        }

        self::assertCount(0, $pasta->getPastaProcessos());
    }

    #[TestDox('Apensado: processo com pelo menos uma pasta visível segue vinculando')]
    public function testApensadoComPastaVisivelSegue(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');
        $pasta = $this->pasta();

        $this->apensado($em, [self::PROCESSO_OCULTO])
            ->executar($pasta, $this->processo(self::PROCESSO_VISIVEL), TipoDeApenso::Embargos, $this->usuario);

        self::assertCount(1, $pasta->getPastaProcessos());
    }

    /** @param list<int> $ocultos */
    private function apensado(EntityManagerInterface $em, array $ocultos): VincularApensadoUseCase
    {
        return new VincularApensadoUseCase($em, new RegistroDoSistemaNaPasta($em), new NumeroProcessoExtension(), $this->acesso($ocultos));
    }

    /**
     * O `AcessoAPasta` de verdade (final), sobre um repositório de cadeados dublê: a pessoa não é primária nem
     * Master, tem uma pasta oculta e os processos dessa pasta são `$ocultos`.
     *
     * @param list<int> $ocultos
     */
    private function acesso(array $ocultos): AcessoAPasta
    {
        $cadeados = $this->createStub(CadeadoDaPastaRepository::class);
        $cadeados->method('idsDasPastasOcultasPara')->willReturn([900]);
        $cadeados->method('processosSoDestasPastas')->willReturn($ocultos);
        $vinculos = $this->createStub(UserTenantRepository::class);
        $tenantContext = (new \ReflectionClass(TenantContext::class))->newInstanceWithoutConstructor();

        return new AcessoAPasta(
            new NivelNoCadeado($cadeados, $vinculos, new RegraDosPrimarios($vinculos), $tenantContext),
            $this->createStub(PermissionChecker::class),
        );
    }

    private function pasta(): Pasta
    {
        $pasta = new Pasta();
        $pasta->setTenant($this->tenant);

        return $pasta;
    }

    private function processo(int $id): Processo
    {
        $processo = new Processo();
        $processo->setTenant($this->tenant);
        $processo->setNumeroProcesso(str_pad((string) $id, 20, '0', STR_PAD_LEFT));
        (new \ReflectionProperty(Processo::class, 'id'))->setValue($processo, $id);

        return $processo;
    }
}
