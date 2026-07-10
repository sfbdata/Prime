<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\Entity\Pasta;
use App\Pasta\DTO\EditarPastaDTO;
use App\Pasta\UseCase\EditarPastaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(EditarPastaUseCase::class)]
final class EditarPastaUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private EditarPastaUseCase $useCase;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new EditarPastaUseCase($this->em);
    }

    #[TestDox('NUP vazio lança InvalidArgumentException e não persiste')]
    public function testNupVazioLancaExcecao(): void
    {
        $pasta = new Pasta();
        $dto   = new EditarPastaDTO(nup: '   ', situacao: Pasta::SITUACAO_ATIVA);

        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('O NUP é obrigatório.');

        $this->useCase->executar($dto, $pasta);
    }

    #[TestDox('NUP repetido agora é permitido: persiste sem lançar')]
    public function testNupRepetidoEhPermitido(): void
    {
        $pasta = new Pasta();
        $dto   = new EditarPastaDTO(nup: 'NUP-001', nomeCliente: 'Cliente X', situacao: Pasta::SITUACAO_ATIVA);

        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($dto, $pasta);

        self::assertSame('NUP-001', $pasta->getNup());
    }

    #[TestDox('Situação inválida é ignorada')]
    public function testSituacaoInvalidaEhIgnorada(): void
    {
        $pasta = new Pasta();
        $pasta->setSituacao(Pasta::SITUACAO_ATIVA);
        $dto = new EditarPastaDTO(nup: 'NUP-002', situacao: 'xpto');

        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($dto, $pasta);

        self::assertSame(Pasta::SITUACAO_ATIVA, $pasta->getSituacao());
    }

    #[TestDox('Situação válida ARQUIVADA é setada')]
    public function testSituacaoValidaEhSetada(): void
    {
        $pasta = new Pasta();
        $pasta->setSituacao(Pasta::SITUACAO_ATIVA);
        $dto = new EditarPastaDTO(nup: 'NUP-003', situacao: Pasta::SITUACAO_ARQUIVADA);

        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($dto, $pasta);

        self::assertSame(Pasta::SITUACAO_ARQUIVADA, $pasta->getSituacao());
    }
}
