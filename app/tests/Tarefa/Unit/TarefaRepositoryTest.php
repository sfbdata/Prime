<?php

namespace App\Tests\Tarefa\Unit;

use App\Entity\Auth\User;
use App\Entity\Pasta\Pasta;
use App\Entity\Tarefa\Tarefa;
use App\Tarefa\Repository\TarefaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Testa TarefaRepository de forma unitária (sem banco).
 *
 * Como o repositório estende ServiceEntityRepository (que depende de
 * ManagerRegistry), testamos apenas o método `save()` isolado usando mocks,
 * e validamos o comportamento de `findByResponsavel()` indiretamente
 * via asserções de estrutura.
 */
class TarefaRepositoryTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
    }

    // ──────────────────────────────────────────────────────────────────
    // save()
    // ──────────────────────────────────────────────────────────────────

    public function testSavePersisteSemFlush(): void
    {
        $pasta  = new Pasta();
        $tarefa = (new Tarefa())->setTitulo('T1')->setDescricao('D1')->setPasta($pasta);

        $this->em->expects($this->once())->method('persist')->with($tarefa);
        $this->em->expects($this->never())->method('flush');

        // Instanciamos via reflexão para injetar o EM mockado sem o registry
        $repo = $this->criarRepositorioComEm($this->em);
        $repo->save($tarefa, false);
    }

    public function testSavePersisteChamandoFlush(): void
    {
        $pasta  = new Pasta();
        $tarefa = (new Tarefa())->setTitulo('T2')->setDescricao('D2')->setPasta($pasta);

        $this->em->expects($this->once())->method('persist')->with($tarefa);
        $this->em->expects($this->once())->method('flush');

        $repo = $this->criarRepositorioComEm($this->em);
        $repo->save($tarefa, true);
    }

    // ──────────────────────────────────────────────────────────────────
    // findByResponsavel() — apenas estrutura da query (não executa SQL)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Verifica que findByResponsavel tem a assinatura correta de retorno array.
     * Testamos via reflexão sem executar a query no banco.
     */
    public function testFindByResponsavelAssinaturaRetornaArray(): void
    {
        $reflection = new \ReflectionMethod(TarefaRepository::class, 'findByResponsavel');
        $returnType = $reflection->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertSame('array', (string) $returnType);
    }

    // ──────────────────────────────────────────────────────────────────
    // Helper: cria instância de TarefaRepository com EM mockado via Reflexão
    // ──────────────────────────────────────────────────────────────────

    private function criarRepositorioComEm(EntityManagerInterface $em): TarefaRepository
    {
        $meta = $this->createMock(ClassMetadata::class);
        $meta->name = Tarefa::class;

        $em->method('getClassMetadata')
            ->with(Tarefa::class)
            ->willReturn($meta);

        $registry = $this->getMockBuilder(\Doctrine\Persistence\ManagerRegistry::class)
            ->getMock();

        $registry->method('getManagerForClass')->willReturn($em);
        $registry->method('getManager')->willReturn($em);

        return new TarefaRepository($registry);
    }
}
