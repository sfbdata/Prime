<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\DTO\RemoverSubsecaoDaSeccionalInput;
use App\Master\Entity\SeccionalOab;
use App\Master\Entity\SubsecaoOab;
use App\Master\Exception\SubsecaoNaoEncontrada;
use App\Master\Repository\SubsecaoOabRepository;
use App\Master\UseCase\RemoverSubsecaoDaSeccionalUseCase;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Remover subseção na janela da OAB (spec master-oab-estados-que-faltam, "SUBSEÇÕES"): só a subseção da UF da URL,
 * apagada dentro do escopo de plataforma. A busca pelo id DENTRO da UF se prova no teste funcional.
 */
#[CoversClass(RemoverSubsecaoDaSeccionalUseCase::class)]
#[CoversClass(RemoverSubsecaoDaSeccionalInput::class)]
final class RemoverSubsecaoDaSeccionalUseCaseTest extends TestCase
{
    private SubsecaoOabRepository&MockObject $subsecoes;
    private EscopoDeAuditoriaDePlataforma $escopo;

    protected function setUp(): void
    {
        $this->subsecoes = $this->createMock(SubsecaoOabRepository::class);
        $this->escopo = new EscopoDeAuditoriaDePlataforma();
    }

    #[TestDox('busca pelo id na UF da URL e apaga dentro do escopo de plataforma')]
    public function testRemove(): void
    {
        $subsecao = new SubsecaoOab(new SeccionalOab('CE', 'OAB Seccional Ceará'), 'Sobral', 'Subseção', 'Rua', null, null, new \DateTimeImmutable());
        $this->subsecoes->method('buscarNaSeccional')->with(7, 'CE')->willReturn($subsecao);
        $this->subsecoes->expects($this->once())->method('remover')->with($subsecao, true)
            ->willReturnCallback(fn () => self::assertTrue($this->escopo->estaLigado(), 'apaga dentro do escopo de plataforma'));

        self::assertSame($subsecao, $this->useCase()->executar(new RemoverSubsecaoDaSeccionalInput(null, 'CE', 7)));
        self::assertFalse($this->escopo->estaLigado());
    }

    #[TestDox('id que não existe ou é de outra UF: SubsecaoNaoEncontrada e nada é apagado')]
    public function testNaoEncontrada(): void
    {
        $this->subsecoes->method('buscarNaSeccional')->willReturn(null);
        $this->subsecoes->expects($this->never())->method('remover');

        $this->expectException(SubsecaoNaoEncontrada::class);
        $this->expectExceptionMessage('Subseção não encontrada.');
        $this->useCase()->executar(new RemoverSubsecaoDaSeccionalInput(null, 'CE', 99));
    }

    private function useCase(): RemoverSubsecaoDaSeccionalUseCase
    {
        return new RemoverSubsecaoDaSeccionalUseCase($this->subsecoes, $this->escopo);
    }
}
