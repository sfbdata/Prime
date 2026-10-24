<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use App\Pasta\UseCase\AlterarSituacaoContratoUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(AlterarSituacaoContratoUseCase::class)]
final class AlterarSituacaoContratoUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private AlterarSituacaoContratoUseCase $useCase;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new AlterarSituacaoContratoUseCase($this->em);
    }

    public function testAlterarParaRegularPersiste(): void
    {
        $pasta = new Pasta();

        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($pasta, 'REGULAR');

        self::assertSame('REGULAR', $pasta->getSituacaoContrato());
    }

    public function testAlterarParaPendentePersiste(): void
    {
        $pasta = new Pasta();
        $pasta->setSituacaoContrato('REGULAR');

        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($pasta, 'PENDENTE');

        self::assertSame('PENDENTE', $pasta->getSituacaoContrato());
    }

    public function testSituacaoInvalidaLancaExcecao(): void
    {
        $pasta = new Pasta();

        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($pasta, 'INVALIDO');
    }

    #[TestDox('pelo selo: regular escreve "Contrato: marcado como assinado.", pendente "Contrato: reaberto como pendente."; repetir não escreve')]
    public function testCartaoDoContrato(): void
    {
        $tenant = new Tenant();
        $pasta = new Pasta();
        $pasta->setTenant($tenant);
        $autor = (new User())->setFullName('Autora');
        $persistidos = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $o) use (&$persistidos): void {
            $persistidos[] = $o;
        });
        $uc = new AlterarSituacaoContratoUseCase($em, null, new RegistroDoSistemaNaPasta($em));

        $uc->executar($pasta, 'REGULAR', $autor, $tenant);
        $uc->executar($pasta, 'REGULAR', $autor, $tenant);
        $uc->executar($pasta, 'PENDENTE', $autor, $tenant);

        self::assertCount(2, $persistidos);
        $conteudos = [];
        foreach ($persistidos as $cartao) {
            self::assertInstanceOf(PastaMensagem::class, $cartao);
            self::assertSame('contrato', $cartao->getSistema());
            self::assertSame($tenant, $cartao->getTenant());
            self::assertSame($autor, $cartao->getAutor());
            $conteudos[] = $cartao->getConteudo();
        }
        self::assertSame([
            '<p><strong>Contrato:</strong> marcado como assinado.</p>',
            '<p><strong>Contrato:</strong> reaberto como pendente.</p>',
        ], $conteudos);
    }
}
