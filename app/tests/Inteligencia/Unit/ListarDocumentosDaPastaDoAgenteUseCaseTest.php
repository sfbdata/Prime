<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Exception\PastaNaoEncontradaException;
use App\Inteligencia\UseCase\ListarDocumentosDaPastaDoAgenteUseCase;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Repository\PastaRepository;
use App\Service\PermissionChecker;
use App\Tests\Inteligencia\Support\DefineId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A ordem das guardas é a regra: pasta fora do escritório é "não encontrada" ANTES de qualquer
 * checagem de permissão — um 403 ali confirmaria que o id existe noutro escritório. E sem acesso por
 * item à pasta, nenhum documento é lido.
 */
#[CoversClass(ListarDocumentosDaPastaDoAgenteUseCase::class)]
final class ListarDocumentosDaPastaDoAgenteUseCaseTest extends TestCase
{
    #[TestDox('Pasta fora do escritório: não encontrada, sem consultar permissão nem documentos')]
    public function testPastaDeOutroEscritorio(): void
    {
        $tenant = new Tenant();
        $pastas = $this->createMock(PastaRepository::class);
        $pastas->expects(self::once())->method('findOneBy')->with(['id' => 5, 'tenant' => $tenant])->willReturn(null);
        $permissoes = $this->createMock(PermissionChecker::class);
        $permissoes->expects(self::never())->method('canAccessResource');
        $documentos = $this->createMock(PastaDocumentoRepository::class);
        $documentos->expects(self::never())->method('findByPastaComSecao');

        $this->expectException(PastaNaoEncontradaException::class);

        (new ListarDocumentosDaPastaDoAgenteUseCase($pastas, $documentos, $permissoes, $this->abasSemRegra()))->executar(5, new User(), $tenant);
    }

    #[TestDox('Sem acesso à pasta: recusa, sem ler documento')]
    public function testSemAcesso(): void
    {
        $tenant = new Tenant();
        $usuario = new User();
        $pasta = new Pasta();
        DefineId::em($pasta, 5);
        $pastas = $this->createStub(PastaRepository::class);
        $pastas->method('findOneBy')->willReturn($pasta);
        $permissoes = $this->createMock(PermissionChecker::class);
        $permissoes->expects(self::once())->method('canAccessResource')->with($usuario, $tenant, 'pasta', 5, 'view')->willReturn(false);
        $documentos = $this->createMock(PastaDocumentoRepository::class);
        $documentos->expects(self::never())->method('findByPastaComSecao');

        $this->expectException(SemPermissaoParaVerPastaException::class);

        (new ListarDocumentosDaPastaDoAgenteUseCase($pastas, $documentos, $permissoes, $this->abasSemRegra()))->executar(5, $usuario, $tenant);
    }

    #[TestDox('Com acesso: os documentos são lidos com o escritório explícito')]
    public function testComAcesso(): void
    {
        $tenant = new Tenant();
        $pasta = new Pasta();
        $pasta->setNup('AG-5');
        DefineId::em($pasta, 5);
        $pastas = $this->createStub(PastaRepository::class);
        $pastas->method('findOneBy')->willReturn($pasta);
        $permissoes = $this->createStub(PermissionChecker::class);
        $permissoes->method('canAccessResource')->willReturn(true);
        $documentos = $this->createMock(PastaDocumentoRepository::class);
        $documentos->expects(self::once())->method('findByPastaComSecao')->with($pasta, $tenant)->willReturn([]);

        $saida = (new ListarDocumentosDaPastaDoAgenteUseCase($pastas, $documentos, $permissoes, $this->abasSemRegra()))->executar(5, new User(), $tenant);

        self::assertSame(5, $saida->pastaId);
        self::assertSame('AG-5', $saida->nup);
        self::assertSame([], $saida->documentos);
    }

    /** Sem regra de aba oculta na pasta: o ocultar aba não muda nada aqui. */
    private function abasSemRegra(): \App\Pasta\Service\VisibilidadeDasAbasDaPasta
    {
        $regras = $this->createStub(\App\Pasta\Repository\AbaOcultaDaPastaRepository::class);
        $regras->method('daPasta')->willReturn([]);
        $vinculos = $this->createStub(\App\Repository\UserTenantRepository::class);
        $primarios = new \App\Tenant\Service\RegraDosPrimarios($vinculos);

        return new \App\Pasta\Service\VisibilidadeDasAbasDaPasta($regras, $primarios, new \App\Pasta\Service\EquipeQuePodeTerAbaOculta($vinculos, $primarios));
    }
}
