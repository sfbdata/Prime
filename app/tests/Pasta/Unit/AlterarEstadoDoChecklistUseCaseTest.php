<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\MotivoDesativacaoChecklist;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\UseCase\AlterarEstadoDoChecklistUseCase;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(AlterarEstadoDoChecklistUseCase::class)]
#[CoversClass(Pasta::class)]
final class AlterarEstadoDoChecklistUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private PermissionChecker&MockObject $permissionChecker;
    private AlterarEstadoDoChecklistUseCase $useCase;
    private Tenant $tenant;
    private User $usuario;
    private Pasta $pasta;
    private \DateTimeImmutable $agora;

    protected function setUp(): void
    {
        $this->em                = $this->createMock(EntityManagerInterface::class);
        $this->permissionChecker = $this->createMock(PermissionChecker::class);
        $this->agora             = new \DateTimeImmutable('2026-10-06 14:30:00');

        $relogio = $this->createStub(ClockInterface::class);
        $relogio->method('now')->willReturn($this->agora);

        $this->useCase = new AlterarEstadoDoChecklistUseCase($this->em, $this->permissionChecker, $relogio);

        $this->tenant  = new Tenant();
        $this->usuario = new User();
        $this->pasta   = new Pasta();
        $this->pasta->setNup('1234');
        $this->pasta->setTenant($this->tenant);
    }

    #[TestDox('Pasta nova nasce com o checklist ATIVO, sem autor, data nem motivo')]
    public function testPadraoEhAtivo(): void
    {
        $pasta = new Pasta();

        self::assertTrue($pasta->isChecklistAtivo());
        self::assertNull($pasta->getChecklistDesativadoEm());
        self::assertNull($pasta->getChecklistDesativadoPor());
        self::assertNull($pasta->getChecklistMotivo());
    }

    #[TestDox('Desativar grava quem, quando (relógio) e o motivo, dá flush uma vez e devolve true; a permissão é a de EDITAR a pasta')]
    public function testDesativa(): void
    {
        $this->permissionChecker
            ->expects(self::once())
            ->method('canAccessResource')
            ->with($this->usuario, $this->tenant, AccessRequest::RESOURCE_PASTA, self::anything(), AccessRequest::ACTION_EDIT)
            ->willReturn(true);
        $this->em->expects(self::once())->method('flush');

        self::assertTrue($this->useCase->executar($this->pasta, false, 'encerrada', $this->usuario, $this->tenant));

        self::assertFalse($this->pasta->isChecklistAtivo());
        self::assertSame($this->agora, $this->pasta->getChecklistDesativadoEm());
        self::assertSame($this->usuario, $this->pasta->getChecklistDesativadoPor());
        self::assertSame(MotivoDesativacaoChecklist::Encerrada, $this->pasta->getChecklistMotivo());
    }

    #[TestDox('Reativar limpa os três campos, dá flush e devolve true; o motivo enviado é ignorado')]
    public function testReativaLimpa(): void
    {
        $this->pasta->desativarChecklist(MotivoDesativacaoChecklist::OutroSistema, $this->usuario, $this->agora);
        $this->permissionChecker->method('canAccessResource')->willReturn(true);
        $this->em->expects(self::once())->method('flush');

        self::assertTrue($this->useCase->executar($this->pasta, true, 'lixo', $this->usuario, $this->tenant));

        self::assertTrue($this->pasta->isChecklistAtivo());
        self::assertNull($this->pasta->getChecklistDesativadoEm());
        self::assertNull($this->pasta->getChecklistDesativadoPor());
        self::assertNull($this->pasta->getChecklistMotivo());
    }

    #[TestDox('Desativar o que já está desativado não grava nada e NÃO troca motivo, autor nem data')]
    public function testDesativarDeNovoNaoSobrescreve(): void
    {
        $antes = new \DateTimeImmutable('2026-09-01 10:00:00');
        $outro = new User();
        $this->pasta->desativarChecklist(MotivoDesativacaoChecklist::NaoSeAplica, $outro, $antes);
        $this->permissionChecker->method('canAccessResource')->willReturn(true);
        $this->em->expects(self::never())->method('flush');

        self::assertFalse($this->useCase->executar($this->pasta, false, 'encerrada', $this->usuario, $this->tenant));

        self::assertSame(MotivoDesativacaoChecklist::NaoSeAplica, $this->pasta->getChecklistMotivo());
        self::assertSame($outro, $this->pasta->getChecklistDesativadoPor());
        self::assertSame($antes, $this->pasta->getChecklistDesativadoEm());
    }

    #[TestDox('Reativar o que já está ativo não grava nada e devolve false')]
    public function testReativarAtivoNaoGrava(): void
    {
        $this->permissionChecker->method('canAccessResource')->willReturn(true);
        $this->em->expects(self::never())->method('flush');

        self::assertFalse($this->useCase->executar($this->pasta, true, null, $this->usuario, $this->tenant));
        self::assertTrue($this->pasta->isChecklistAtivo());
    }

    #[TestDox('Desativar sem motivo, ou com motivo fora dos quatro, lança InvalidArgumentException sem gravar')]
    public function testMotivoInvalido(): void
    {
        $this->permissionChecker->method('canAccessResource')->willReturn(true);
        $this->em->expects(self::never())->method('flush');

        foreach ([null, '', 'qualquer', 'ENCERRADA', 'Pasta encerrada'] as $motivo) {
            try {
                $this->useCase->executar($this->pasta, false, $motivo, $this->usuario, $this->tenant);
                self::fail('motivo inválido aceito: ' . var_export($motivo, true));
            } catch (\InvalidArgumentException) {
                self::assertTrue($this->pasta->isChecklistAtivo());
            }
        }
    }

    #[TestDox('Os quatro motivos do desenho são aceitos, cada um com o rótulo do desenho')]
    public function testQuatroMotivos(): void
    {
        self::assertSame(
            [
                'nao_se_aplica'             => 'Não se aplica a esta pasta',
                'outro_sistema'             => 'Documentos controlados em outro sistema',
                'administrativa_consultiva' => 'Pasta administrativa ou consultiva',
                'encerrada'                 => 'Pasta encerrada',
            ],
            array_combine(
                array_map(static fn (MotivoDesativacaoChecklist $m): string => $m->value, MotivoDesativacaoChecklist::cases()),
                array_map(static fn (MotivoDesativacaoChecklist $m): string => $m->rotulo(), MotivoDesativacaoChecklist::cases()),
            ),
        );
    }

    #[TestDox('Pasta de outro escritório: lança PastaDeOutroEscritorioException antes de conferir permissão, sem gravar')]
    public function testOutroEscritorio(): void
    {
        $this->permissionChecker->expects(self::never())->method('canAccessResource');
        $this->em->expects(self::never())->method('flush');

        $this->expectException(PastaDeOutroEscritorioException::class);

        try {
            $this->useCase->executar($this->pasta, false, 'encerrada', $this->usuario, new Tenant());
        } finally {
            self::assertTrue($this->pasta->isChecklistAtivo());
        }
    }

    #[TestDox('Sem permissão de editar: lança SemPermissaoParaEditarPastaException ANTES de validar o motivo, sem gravar')]
    public function testSemPermissaoAntesDoMotivo(): void
    {
        $this->permissionChecker->method('canAccessResource')->willReturn(false);
        $this->em->expects(self::never())->method('flush');

        $this->expectException(SemPermissaoParaEditarPastaException::class);

        try {
            $this->useCase->executar($this->pasta, false, 'motivo-invalido', $this->usuario, $this->tenant);
        } finally {
            self::assertTrue($this->pasta->isChecklistAtivo());
        }
    }

    #[TestDox('A entidade recusa desativar duas vezes e reativar o que está ativo (guarda contra sobrescrever o registro)')]
    public function testEntidadeRecusaTransicaoInvalida(): void
    {
        $pasta = new Pasta();

        try {
            $pasta->reativarChecklist();
            self::fail('reativou o que estava ativo');
        } catch (\LogicException) {
        }

        $pasta->desativarChecklist(MotivoDesativacaoChecklist::Encerrada, $this->usuario, $this->agora);

        $this->expectException(\LogicException::class);
        $pasta->desativarChecklist(MotivoDesativacaoChecklist::NaoSeAplica, $this->usuario, $this->agora);
    }
}
