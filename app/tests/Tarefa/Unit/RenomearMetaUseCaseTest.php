<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tarefa\Exception\TituloDeMetaInvalidoException;
use App\Tarefa\UseCase\RenomearMetaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(RenomearMetaUseCase::class)]
final class RenomearMetaUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private RenomearMetaUseCase $useCase;
    private Tenant $tenant;
    private Tarefa $tarefa;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new RenomearMetaUseCase($this->em);
        $this->tenant  = new Tenant();

        $pasta = new Pasta();
        $pasta->setTenant($this->tenant);

        $this->tarefa = new Tarefa();
        $this->tarefa->setTitulo('Protocolar recurso');
        $this->tarefa->setDescricao('...');
        $this->tarefa->setPasta($pasta);
        $this->tarefa->setTenant($this->tenant);
    }

    #[TestDox('grava o nome sem espaços sobrando, como digitado (sem caixa alta), e dá flush')]
    public function testRenomeia(): void
    {
        $this->em->expects(self::once())->method('flush');

        self::assertTrue($this->useCase->executar($this->tarefa, $this->tenant, "  Protocolar\t  apelação  "));
        self::assertSame('Protocolar apelação', $this->tarefa->getTitulo());
    }

    #[TestDox('nome igual ao atual: nada a gravar')]
    public function testMesmoNomeNaoGrava(): void
    {
        $this->em->expects(self::never())->method('flush');

        self::assertFalse($this->useCase->executar($this->tarefa, $this->tenant, ' Protocolar recurso '));
    }

    /** @return iterable<string, array{string}> */
    public static function nomesInvalidos(): iterable
    {
        yield 'vazio' => [''];
        yield 'só espaço' => ["   \t "];
        yield 'maior que a coluna' => [str_repeat('a', RenomearMetaUseCase::TAMANHO_MAXIMO + 1)];
    }

    #[DataProvider('nomesInvalidos')]
    #[TestDox('nome inválido ($_dataName) é recusado e o nome antigo fica')]
    public function testNomeInvalido(string $titulo): void
    {
        $this->em->expects(self::never())->method('flush');

        try {
            $this->useCase->executar($this->tarefa, $this->tenant, $titulo);
            self::fail('deveria recusar');
        } catch (TituloDeMetaInvalidoException) {
        }

        self::assertSame('Protocolar recurso', $this->tarefa->getTitulo());
    }

    #[TestDox('nome no limite exato da coluna é aceito')]
    public function testNoLimite(): void
    {
        $this->em->expects(self::once())->method('flush');
        $nome = str_repeat('á', RenomearMetaUseCase::TAMANHO_MAXIMO);

        self::assertTrue($this->useCase->executar($this->tarefa, $this->tenant, $nome));
        self::assertSame($nome, $this->tarefa->getTitulo());
    }

    #[TestDox('meta de outro escritório: recusa sem gravar (segunda trava além do TenantFilter)')]
    public function testOutroEscritorio(): void
    {
        $this->em->expects(self::never())->method('flush');
        $this->expectException(\LogicException::class);

        $this->useCase->executar($this->tarefa, new Tenant(), 'Outro nome');
    }
}
