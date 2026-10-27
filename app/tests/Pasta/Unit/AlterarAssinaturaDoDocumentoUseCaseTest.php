<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Enum\OrigemDaAssinatura;
use App\Pasta\Exception\AssinaturaDoDocumentoRecusadaException;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\UseCase\AlterarAssinaturaDoDocumentoUseCase;
use App\Service\PermissionChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(AlterarAssinaturaDoDocumentoUseCase::class)]
#[CoversClass(PastaDocumento::class)]
final class AlterarAssinaturaDoDocumentoUseCaseTest extends TestCase
{
    private const AGORA = '2026-10-23 14:30:00';

    private PastaDocumentoRepository&MockObject $repository;
    private PermissionChecker&MockObject $permissionChecker;
    private AlterarAssinaturaDoDocumentoUseCase $useCase;
    private Tenant $tenant;
    private User $usuario;
    private Pasta $pasta;

    protected function setUp(): void
    {
        $this->repository        = $this->createMock(PastaDocumentoRepository::class);
        $this->permissionChecker = $this->createMock(PermissionChecker::class);
        $this->useCase           = new AlterarAssinaturaDoDocumentoUseCase(
            $this->repository,
            $this->permissionChecker,
            new MockClock(new \DateTimeImmutable(self::AGORA)),
        );

        $this->tenant  = new Tenant();
        $this->usuario = new User();
        $this->pasta   = $this->pasta($this->tenant, 7);
    }

    #[TestDox('marcar grava o selo manual com quem marcou e quando, exigindo EDITAR a pasta')]
    public function testMarcar(): void
    {
        $doc = $this->documento($this->pasta, $this->tenant);
        $this->permissionChecker
            ->expects(self::once())
            ->method('canAccessResource')
            ->with($this->usuario, $this->tenant, AccessRequest::RESOURCE_PASTA, 7, AccessRequest::ACTION_EDIT)
            ->willReturn(true);
        $this->repository->expects(self::once())->method('salvar');

        $this->useCase->marcar($this->pasta, $doc, $this->usuario, $this->tenant);

        self::assertTrue($doc->estaAssinado());
        self::assertSame(OrigemDaAssinatura::Manual, $doc->getAssinaturaOrigem());
        self::assertSame($this->usuario, $doc->getAssinadoPor());
        self::assertEquals(new \DateTimeImmutable(self::AGORA), $doc->getAssinadoEm());
    }

    #[TestDox('desmarcar apaga o selo manual por inteiro')]
    public function testDesmarcarManual(): void
    {
        $doc = $this->documento($this->pasta, $this->tenant);
        $doc->marcarAssinadoManualmente($this->usuario, new \DateTimeImmutable('2026-10-01'));
        $this->permissionChecker->method('canAccessResource')->willReturn(true);
        $this->repository->expects(self::once())->method('salvar');

        $this->useCase->desmarcar($this->pasta, $doc, $this->usuario, $this->tenant);

        self::assertFalse($doc->estaAssinado());
        self::assertNull($doc->getAssinaturaOrigem());
        self::assertNull($doc->getAssinadoPor());
        self::assertNull($doc->getAssinadoEm());
    }

    #[TestDox('assinatura digital não se desmarca pela tela')]
    public function testDigitalNaoSeDesmarca(): void
    {
        $doc = $this->documento($this->pasta, $this->tenant);
        $doc->marcarAssinadoDigitalmente(new \DateTimeImmutable('2026-10-01'), null);
        $this->permissionChecker->method('canAccessResource')->willReturn(true);
        $this->repository->expects(self::never())->method('salvar');

        try {
            $this->useCase->desmarcar($this->pasta, $doc, $this->usuario, $this->tenant);
            self::fail('desmarcou a assinatura digital');
        } catch (AssinaturaDoDocumentoRecusadaException) {
        }

        self::assertSame(OrigemDaAssinatura::Digital, $doc->getAssinaturaOrigem());
    }

    #[TestDox('marcar o que já está assinado é recusado (não sobrescreve quem e quando)')]
    public function testMarcarDuasVezes(): void
    {
        $doc = $this->documento($this->pasta, $this->tenant);
        $doc->marcarAssinadoDigitalmente(new \DateTimeImmutable('2026-10-01'), null);
        $this->permissionChecker->method('canAccessResource')->willReturn(true);
        $this->repository->expects(self::never())->method('salvar');

        $this->expectException(AssinaturaDoDocumentoRecusadaException::class);
        $this->useCase->marcar($this->pasta, $doc, $this->usuario, $this->tenant);
    }

    #[TestDox('desmarcar o que não está assinado é recusado')]
    public function testDesmarcarSemSelo(): void
    {
        $doc = $this->documento($this->pasta, $this->tenant);
        $this->permissionChecker->method('canAccessResource')->willReturn(true);
        $this->repository->expects(self::never())->method('salvar');

        $this->expectException(AssinaturaDoDocumentoRecusadaException::class);
        $this->useCase->desmarcar($this->pasta, $doc, $this->usuario, $this->tenant);
    }

    #[TestDox('sem permissão de editar a pasta: recusa e não grava')]
    public function testSemPermissao(): void
    {
        $doc = $this->documento($this->pasta, $this->tenant);
        $this->permissionChecker->method('canAccessResource')->willReturn(false);
        $this->repository->expects(self::never())->method('salvar');

        try {
            $this->useCase->marcar($this->pasta, $doc, $this->usuario, $this->tenant);
            self::fail('marcou sem permissão');
        } catch (SemPermissaoParaEditarPastaException) {
        }

        self::assertFalse($doc->estaAssinado());
    }

    #[TestDox('documento de outro escritório ou de outra pasta: "não encontrado", antes da permissão')]
    public function testDocumentoDeOutroEscritorioOuPasta(): void
    {
        $this->permissionChecker->expects(self::never())->method('canAccessResource');
        $this->repository->expects(self::never())->method('salvar');

        $outroTenant = new Tenant();
        $casos       = [
            'documento de outro escritório' => [$this->pasta, $this->documento($this->pasta($outroTenant, 8), $outroTenant)],
            'documento de outra pasta'      => [$this->pasta, $this->documento($this->pasta($this->tenant, 9), $this->tenant)],
            'pasta de outro escritório'     => [$this->pasta($outroTenant, 10), $this->documento($this->pasta, $this->tenant)],
        ];

        foreach ($casos as $rotulo => [$pasta, $doc]) {
            try {
                $this->useCase->marcar($pasta, $doc, $this->usuario, $this->tenant);
                self::fail($rotulo . ': marcou');
            } catch (PastaDeOutroEscritorioException) {
            }
            self::assertFalse($doc->estaAssinado(), $rotulo);
        }
    }

    private function pasta(Tenant $tenant, int $id): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('P-' . $id);
        $pasta->setTenant($tenant);
        (new \ReflectionProperty(Pasta::class, 'id'))->setValue($pasta, $id);

        return $pasta;
    }

    private function documento(Pasta $pasta, Tenant $tenant): PastaDocumento
    {
        return (new PastaDocumento())
            ->setPasta($pasta)
            ->setTenant($tenant)
            ->setCategoria(PastaDocumento::CATEGORIA_CONTRATO);
    }
}
