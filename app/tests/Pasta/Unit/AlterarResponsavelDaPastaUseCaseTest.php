<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use App\Pasta\UseCase\AlterarResponsavelDaPastaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(AlterarResponsavelDaPastaUseCase::class)]
final class AlterarResponsavelDaPastaUseCaseTest extends TestCase
{
    /** @var list<object> */
    private array $persistidos = [];

    #[TestDox('trocar de Ana para Bruno grava e escreve "Responsável: alterado de "Ana" para "Bruno"." com o tenant da pasta e o autor')]
    public function testTroca(): void
    {
        [$pasta, $tenant, $autor] = $this->cenario();
        $ana = $this->usuario('Ana');
        $bruno = $this->usuario('Bruno');
        $pasta->setResponsavel($ana);
        $em = $this->em(1);

        $mudou = $this->useCase($em)->executar($pasta, $bruno, $autor, $tenant);

        self::assertTrue($mudou);
        self::assertSame($bruno, $pasta->getResponsavel());
        $cartao = $this->cartao();
        self::assertSame('resp', $cartao->getSistema());
        self::assertSame($tenant, $cartao->getTenant());
        self::assertSame($autor, $cartao->getAutor());
        self::assertSame('<p><strong>Responsável:</strong> alterado de &quot;Ana&quot; para &quot;Bruno&quot;.</p>', $cartao->getConteudo());
    }

    #[TestDox('o lado vazio aparece como "Sem responsável", nos dois sentidos')]
    public function testLadoVazio(): void
    {
        [$pasta, $tenant, $autor] = $this->cenario();
        $ana = $this->usuario('Ana');

        $this->useCase($this->em(1))->executar($pasta, $ana, $autor, $tenant);
        self::assertStringContainsString('alterado de &quot;Sem responsável&quot; para &quot;Ana&quot;.', (string) $this->cartao()->getConteudo());

        $this->persistidos = [];
        $this->useCase($this->em(1))->executar($pasta, null, $autor, $tenant);
        self::assertNull($pasta->getResponsavel());
        self::assertStringContainsString('alterado de &quot;Ana&quot; para &quot;Sem responsável&quot;.', (string) $this->cartao()->getConteudo());
    }

    #[TestDox('colaborador sem nome aparece como "Usuário sem nome": o e-mail nunca vai para o cartão')]
    public function testSemNomeNaoGravaEmail(): void
    {
        [$pasta, $tenant, $autor] = $this->cenario();
        $semNome = (new User())->setEmail('sigiloso@exemplo.com');

        $this->useCase($this->em(1))->executar($pasta, $semNome, $autor, $tenant);

        $conteudo = $this->cartao()->getConteudo();
        self::assertStringContainsString('para &quot;Usuário sem nome&quot;.', $conteudo);
        self::assertStringNotContainsString('sigiloso@exemplo.com', $conteudo);
    }

    #[TestDox('escolher quem já é o responsável (ou nenhum, quando não há) não grava nem escreve cartão')]
    public function testSemMudanca(): void
    {
        [$pasta, $tenant, $autor] = $this->cenario();
        $ana = $this->usuario('Ana');
        $pasta->setResponsavel($ana);

        self::assertFalse($this->useCase($this->em(0))->executar($pasta, $ana, $autor, $tenant));

        $vazia = $this->cenario()[0];
        self::assertFalse($this->useCase($this->em(0))->executar($vazia, null, $autor, $vazia->getTenant() ?? new Tenant()));
        self::assertSame([], $this->persistidos);
    }

    #[TestDox('pasta de outro escritório é recusada sem mexer no responsável')]
    public function testOutroEscritorio(): void
    {
        [$pasta, , $autor] = $this->cenario();

        try {
            $this->useCase($this->em(0))->executar($pasta, $this->usuario('Ana'), $autor, new Tenant());
            self::fail('deveria recusar');
        } catch (PastaDeOutroEscritorioException) {
        }
        self::assertNull($pasta->getResponsavel());
        self::assertSame([], $this->persistidos);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function useCase(EntityManagerInterface $em): AlterarResponsavelDaPastaUseCase
    {
        return new AlterarResponsavelDaPastaUseCase(new RegistroDoSistemaNaPasta($em), $em);
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

    private function usuario(string $nome): User
    {
        return (new User())->setFullName($nome);
    }

    /** @return array{0: Pasta, 1: Tenant, 2: User} */
    private function cenario(): array
    {
        $tenant = new Tenant();
        $pasta = new Pasta();
        $pasta->setTenant($tenant);

        return [$pasta, $tenant, $this->usuario('Autora')];
    }
}
