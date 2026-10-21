<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use App\Pasta\UseCase\AlterarProBonoDaPastaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(AlterarProBonoDaPastaUseCase::class)]
final class AlterarProBonoDaPastaUseCaseTest extends TestCase
{
    /** @var list<object> */
    private array $persistidos = [];

    #[TestDox('marcar grava e escreve "Pró-bono: pasta marcada como pró-bono." com o tenant da pasta e o autor')]
    public function testMarcar(): void
    {
        [$pasta, $tenant, $autor] = $this->cenario();

        self::assertTrue($this->useCase($this->em(1))->executar($pasta, true, $autor, $tenant));

        self::assertTrue($pasta->isProBono());
        $cartao = $this->cartao();
        self::assertSame('probono', $cartao->getSistema());
        self::assertSame($tenant, $cartao->getTenant());
        self::assertSame($autor, $cartao->getAutor());
        self::assertSame('<p><strong>Pró-bono:</strong> pasta marcada como pró-bono.</p>', $cartao->getConteudo());
    }

    #[TestDox('desmarcar escreve "Pró-bono: marcação de pró-bono retirada."')]
    public function testDesmarcar(): void
    {
        [$pasta, $tenant, $autor] = $this->cenario();
        $pasta->setProBono(true);

        self::assertTrue($this->useCase($this->em(1))->executar($pasta, false, $autor, $tenant));

        self::assertFalse($pasta->isProBono());
        self::assertSame('<p><strong>Pró-bono:</strong> marcação de pró-bono retirada.</p>', $this->cartao()->getConteudo());
    }

    #[TestDox('mandar o valor que a pasta já tem não grava nem escreve cartão')]
    public function testSemMudanca(): void
    {
        [$pasta, $tenant, $autor] = $this->cenario();

        self::assertFalse($this->useCase($this->em(0))->executar($pasta, false, $autor, $tenant));
        self::assertSame([], $this->persistidos);
    }

    #[TestDox('pasta de outro escritório é recusada sem mexer na marcação')]
    public function testOutroEscritorio(): void
    {
        [$pasta, , $autor] = $this->cenario();

        try {
            $this->useCase($this->em(0))->executar($pasta, true, $autor, new Tenant());
            self::fail('deveria recusar');
        } catch (PastaDeOutroEscritorioException) {
        }
        self::assertFalse($pasta->isProBono());
        self::assertSame([], $this->persistidos);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function useCase(EntityManagerInterface $em): AlterarProBonoDaPastaUseCase
    {
        return new AlterarProBonoDaPastaUseCase(new RegistroDoSistemaNaPasta($em), $em);
    }

    private function em(int $flushes): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $o): void {
            $this->persistidos[] = $o;
        });
        $em->expects($this->exactly($flushes))->method('flush');

        return $em;
    }

    private function cartao(): PastaMensagem
    {
        self::assertCount(1, $this->persistidos);
        self::assertInstanceOf(PastaMensagem::class, $this->persistidos[0]);

        return $this->persistidos[0];
    }

    /** @return array{0: Pasta, 1: Tenant, 2: User} */
    private function cenario(): array
    {
        $tenant = new Tenant();
        $pasta = new Pasta();
        $pasta->setTenant($tenant);

        return [$pasta, $tenant, (new User())->setFullName('Autora')];
    }
}
