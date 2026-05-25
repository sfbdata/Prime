<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Pasta\Pasta;
use App\Pasta\DTO\EditarPastaDTO;
use App\Pasta\UseCase\EditarPastaUseCase;
use App\Repository\PastaRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(EditarPastaUseCase::class)]
final class EditarPastaUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private PastaRepository&MockObject $pastaRepository;
    private EditarPastaUseCase $useCase;

    protected function setUp(): void
    {
        $this->em              = $this->createMock(EntityManagerInterface::class);
        $this->pastaRepository = $this->createMock(PastaRepository::class);
        $this->useCase         = new EditarPastaUseCase($this->em, $this->pastaRepository);
    }

    #[TestDox('NUP vazio lança InvalidArgumentException')]
    public function testNupVazioLancaExcecao(): void
    {
        $pasta = new Pasta();
        $dto   = new EditarPastaDTO(nup: '   ', situacao: Pasta::SITUACAO_ATIVA);

        $this->pastaRepository->expects($this->never())->method('findOneBy');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('O NUP é obrigatório.');

        $this->useCase->executar($dto, $pasta);
    }

    #[TestDox('NUP duplicado de outra pasta lança InvalidArgumentException')]
    public function testNupDuplicadoDeOutraPastaLancaExcecao(): void
    {
        $pasta    = new Pasta();
        $pasta->setTenant(null);
        // Simula ID da pasta sendo editada
        $refPasta = new \ReflectionClass($pasta);
        $refId    = $refPasta->getProperty('id');
        $refId->setValue($pasta, 42);

        $outra = new Pasta();
        $refId->setValue($outra, 99);

        $dto = new EditarPastaDTO(nup: 'NUP-001', situacao: Pasta::SITUACAO_ATIVA);

        $this->pastaRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['nup' => 'NUP-001'])
            ->willReturn($outra);

        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('O NUP "NUP-001" já está em uso por outra pasta.');

        $this->useCase->executar($dto, $pasta);
    }

    #[TestDox('NUP igual ao da própria pasta não lança exceção e persiste')]
    public function testNupIgualAoProprioPastaPermiteEdicao(): void
    {
        $pasta = new Pasta();
        $ref   = new \ReflectionClass($pasta);
        $refId = $ref->getProperty('id');
        $refId->setValue($pasta, 42);

        $dto = new EditarPastaDTO(
            nup: 'NUP-001',
            nomeCliente: 'Cliente X',
            situacao: Pasta::SITUACAO_ATIVA,
        );

        $this->pastaRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['nup' => 'NUP-001'])
            ->willReturn($pasta);

        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($dto, $pasta);

        self::assertSame('NUP-001', $pasta->getNup());
    }

    #[TestDox('Situação inválida é ignorada, não chama setSituacao')]
    public function testSituacaoInvalidaEhIgnorada(): void
    {
        $pasta = new Pasta();
        $pasta->setSituacao(Pasta::SITUACAO_ATIVA);

        $dto = new EditarPastaDTO(nup: 'NUP-002', situacao: 'xpto');

        $this->pastaRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->willReturn(null);

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

        $this->pastaRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->willReturn(null);

        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($dto, $pasta);

        self::assertSame(Pasta::SITUACAO_ARQUIVADA, $pasta->getSituacao());
    }
}
