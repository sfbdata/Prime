<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\UseCase\ExcluirPastaUseCase;
use App\Pasta\UseCase\RestaurarPastaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Excluir pasta é mandar para a lixeira: TODA exclusão vira lápide restaurável, inclusive a da
 * última pasta da sequência (desenho 02 Expedientes, "vai para a lixeira por 30 dias"). Nenhuma
 * linha sai do banco e nenhum arquivo sai do disco: o UseCase não tem mais nem como removê-los
 * (não recebe armazenamento), e o dublê do EM recusa `remove()`.
 *
 * Entidade real (não dublê) de propósito: o que se prova é o estado da pasta depois, e que a
 * restauração desfaz a exclusão.
 */
#[CoversClass(ExcluirPastaUseCase::class)]
final class ExcluirPastaUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private ExcluirPastaUseCase $useCase;
    private Tenant $tenant;
    private User $autor;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->expects($this->never())->method('remove');

        $this->useCase = new ExcluirPastaUseCase($this->em);

        $this->tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($this->tenant, 7);
        $this->autor = (new User())->setEmail('autor@test.com');
    }

    private function criarPasta(string $nup): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup($nup);
        $pasta->setTenant($this->tenant);

        return $pasta;
    }

    #[TestDox('Tenant diverge: recusa antes de tocar em qualquer coisa')]
    public function testTenantDivergeLancaAccessDeniedException(): void
    {
        $pasta = $this->criarPasta('1238');
        $this->em->expects($this->never())->method('flush');

        try {
            $this->useCase->executar($pasta, $this->autor, new Tenant());
            self::fail('Pasta de outro escritório não pode ser excluída.');
        } catch (AccessDeniedException) {
        }

        self::assertFalse($pasta->estaExcluida());
    }

    #[TestDox('Última da sequência (ou qualquer outra): vai para a lixeira, a linha fica e o autor é registrado')]
    public function testExclusaoViraLapide(): void
    {
        $pasta = $this->criarPasta('1240');
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($pasta, $this->autor, $this->tenant);

        self::assertTrue($pasta->estaExcluida());
        self::assertSame($this->autor, $pasta->getExcluidaPor());
        self::assertNotNull($pasta->getExcluidaEm());
        self::assertSame(Pasta::SITUACAO_ARQUIVADA, $pasta->getSituacao());
    }

    #[TestDox('A pasta excluída é restaurável: o RestaurarPastaUseCase a devolve ativa')]
    public function testPastaExcluidaEhRestauravel(): void
    {
        $pasta = $this->criarPasta('1240');

        $this->useCase->executar($pasta, $this->autor, $this->tenant);
        (new RestaurarPastaUseCase($this->em))->executar($pasta, $this->tenant);

        self::assertFalse($pasta->estaExcluida());
        self::assertNull($pasta->getExcluidaEm());
        self::assertNull($pasta->getExcluidaPor());
    }

    #[TestDox('Excluir pasta que já está na lixeira é recusado')]
    public function testPastaJaExcluidaEhRecusada(): void
    {
        $pasta = $this->criarPasta('1238');
        $this->useCase->executar($pasta, $this->autor, $this->tenant);

        $this->expectException(\LogicException::class);

        $this->useCase->executar($pasta, $this->autor, $this->tenant);
    }
}
