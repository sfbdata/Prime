<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Exception\JustificativaJaAnalisadaException;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\UseCase\ConfirmarEdicaoDeJustificativaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O caminho de FALHA da gravação da edição — o que o funcional não alcança: a própria leitura
 * travada falhando (conexão caiu, lock timeout, deadlock com o admin).
 *
 * `app/tests/CLAUDE.md` diz para não mockar `EntityManagerInterface`. A exceção é deliberada, como em
 * `SubstituirAnexoDoLoteFalhaTest`: o que se prova é o que o UseCase faz quando a transação falha. O
 * `wrapInTransaction` do dublê segue o do ORM — trabalho, depois flush e COMMIT; se o trabalho lança,
 * não há flush. O funcional `ConfirmarEdicaoDeJustificativaUseCaseTest` prova o resto contra o banco.
 */
#[CoversClass(ConfirmarEdicaoDeJustificativaUseCase::class)]
final class ConfirmarEdicaoDeJustificativaFalhaTest extends TestCase
{
    private int $flushes = 0;

    #[TestDox('Falha do banco ao ler o status travado: a exceção sobe e nada é gravado')]
    public function testFalhaDoBancoNaLeituraNaoGrava(): void
    {
        $repositorio = $this->createMock(JustificativaPontoRepository::class);
        $repositorio->method('statusNoBancoTravadoPorId')->willThrowException(new \RuntimeException('lock timeout'));

        $capturada = null;
        try {
            $this->useCase($repositorio)->executar($this->justificativa(7), $this->tenant(7));
        } catch (\RuntimeException $e) {
            $capturada = $e;
        }

        self::assertSame('lock timeout', $capturada?->getMessage(), 'a exceção original sobe');
        self::assertSame(0, $this->flushes, 'nada do que a rota editou pode ser gravado');
    }

    #[TestDox('Status analisado sob a trava: recusa sem gravar')]
    public function testAnalisadaSobATravaNaoGrava(): void
    {
        $repositorio = $this->createMock(JustificativaPontoRepository::class);
        $repositorio->method('statusNoBancoTravadoPorId')->willReturn('rejeitado');

        $this->expectException(JustificativaJaAnalisadaException::class);

        try {
            $this->useCase($repositorio)->executar($this->justificativa(7), $this->tenant(7));
        } finally {
            self::assertSame(0, $this->flushes);
        }
    }

    #[TestDox('Pendente sob a trava: grava uma vez, lendo pelo id e escritório da justificativa')]
    public function testPendenteSobATravaGravaUmaVez(): void
    {
        $justificativa = $this->justificativa(7);
        $tenant        = $this->tenant(7);

        $repositorio = $this->createMock(JustificativaPontoRepository::class);
        $repositorio->expects(self::once())
            ->method('statusNoBancoTravadoPorId')
            ->with(42, $tenant)
            ->willReturn('pendente');

        $this->useCase($repositorio)->executar($justificativa, $tenant);

        self::assertSame(1, $this->flushes);
    }

    #[TestDox('Outro escritório: LogicException sem abrir transação')]
    public function testOutroEscritorioNaoAbreTransacao(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('wrapInTransaction');

        $useCase = new ConfirmarEdicaoDeJustificativaUseCase($em, $this->createMock(JustificativaPontoRepository::class));

        $this->expectException(\LogicException::class);
        $useCase->executar($this->justificativa(7), $this->tenant(99));
    }

    // ------------------------------------------------------------------ helpers

    private function useCase(JustificativaPontoRepository $repositorio): ConfirmarEdicaoDeJustificativaUseCase
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(function (callable $trabalho) use ($em): mixed {
            $resultado = $trabalho($em);
            ++$this->flushes;

            return $resultado;
        });

        return new ConfirmarEdicaoDeJustificativaUseCase($em, $repositorio);
    }

    private function tenant(int $id): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('T' . $id);
        (new \ReflectionProperty($tenant, 'id'))->setValue($tenant, $id);

        return $tenant;
    }

    private function justificativa(int $tenantId): JustificativaPonto
    {
        $j = new JustificativaPonto();
        $j->setTenant($this->tenant($tenantId));
        $j->setStatus('pendente');
        (new \ReflectionProperty($j, 'id'))->setValue($j, 42);

        return $j;
    }
}
