<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\CarteiraDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use App\Pasta\UseCase\MoverPastaDeCarteiraUseCase;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(MoverPastaDeCarteiraUseCase::class)]
#[CoversClass(CarteiraDaPasta::class)]
final class MoverPastaDeCarteiraUseCaseTest extends TestCase
{
    /** @var list<object> */
    private array $persistidos = [];

    #[TestDox('as seis carteiras do desenho, nesta ordem, com os rótulos do submenu')]
    public function testListaFixaNaOrdemDoDesenho(): void
    {
        self::assertSame(
            ['Contencioso cível', 'Condominial', 'Família e sucessões', 'Trabalhista', 'Previdenciário', 'Empresarial'],
            array_map(static fn (CarteiraDaPasta $c): string => $c->rotulo(), CarteiraDaPasta::cases()),
        );
        self::assertSame('Sem carteira', CarteiraDaPasta::rotuloDe(null));
    }

    #[TestDox('pasta sem carteira vai para Trabalhista e o Registro ganha "Carteira: movida de "Sem carteira" para "Trabalhista"."')]
    public function testMoverPastaSemCarteira(): void
    {
        [$pasta, $tenant, $autor] = $this->cenario();

        self::assertTrue($this->useCase($this->em(1))->executar($pasta, $autor, $tenant, CarteiraDaPasta::Trabalhista));

        self::assertSame(CarteiraDaPasta::Trabalhista, $pasta->getCarteira());
        $cartao = $this->cartao();
        self::assertSame('carteira', $cartao->getSistema());
        self::assertSame($tenant, $cartao->getTenant());
        self::assertSame($autor, $cartao->getAutor());
        self::assertSame($pasta, $cartao->getPasta());
        self::assertSame(
            '<p><strong>Carteira:</strong> movida de &quot;Sem carteira&quot; para &quot;Trabalhista&quot;.</p>',
            $cartao->getConteudo(),
        );
    }

    #[TestDox('de uma carteira para outra: "movida de "Trabalhista" para "Família e sucessões"."')]
    public function testMoverEntreCarteiras(): void
    {
        [$pasta, $tenant, $autor] = $this->cenario();
        $pasta->moverParaCarteira(CarteiraDaPasta::Trabalhista);

        self::assertTrue($this->useCase($this->em(1))->executar($pasta, $autor, $tenant, CarteiraDaPasta::FamiliaESucessoes));

        self::assertSame(CarteiraDaPasta::FamiliaESucessoes, $pasta->getCarteira());
        self::assertSame(
            '<p><strong>Carteira:</strong> movida de &quot;Trabalhista&quot; para &quot;Família e sucessões&quot;.</p>',
            $this->cartao()->getConteudo(),
        );
    }

    #[TestDox('escolher a carteira em que a pasta já está não grava nem escreve cartão')]
    public function testMesmaCarteiraNaoRegistra(): void
    {
        [$pasta, $tenant, $autor] = $this->cenario();
        $pasta->moverParaCarteira(CarteiraDaPasta::Condominial);

        self::assertFalse($this->useCase($this->em(0))->executar($pasta, $autor, $tenant, CarteiraDaPasta::Condominial));

        self::assertSame(CarteiraDaPasta::Condominial, $pasta->getCarteira());
        self::assertSame([], $this->persistidos);
    }

    #[TestDox('sem permissão de editar a pasta, recusa e a carteira não muda')]
    public function testSemPermissao(): void
    {
        [$pasta, $tenant, $autor] = $this->cenario();

        try {
            $this->useCase($this->em(0), false)->executar($pasta, $autor, $tenant, CarteiraDaPasta::Empresarial);
            self::fail('deveria recusar');
        } catch (SemPermissaoParaEditarPastaException) {
        }
        self::assertNull($pasta->getCarteira());
        self::assertSame([], $this->persistidos);
    }

    #[TestDox('pasta de outro escritório não é encontrada e a carteira não muda')]
    public function testOutroEscritorio(): void
    {
        [$pasta, , $autor] = $this->cenario();

        try {
            $this->useCase($this->em(0))->executar($pasta, $autor, new Tenant(), CarteiraDaPasta::Empresarial);
            self::fail('deveria recusar');
        } catch (PastaDeOutroEscritorioException) {
        }
        self::assertNull($pasta->getCarteira());
        self::assertSame([], $this->persistidos);
    }

    #[TestDox('pasta na lixeira não muda de carteira')]
    public function testPastaExcluida(): void
    {
        [$pasta, $tenant, $autor] = $this->cenario();
        $pasta->marcarExcluida($autor, new \DateTimeImmutable());

        try {
            $this->useCase($this->em(0))->executar($pasta, $autor, $tenant, CarteiraDaPasta::Empresarial);
            self::fail('deveria recusar');
        } catch (\DomainException $e) {
            self::assertNotInstanceOf(SemPermissaoParaEditarPastaException::class, $e);
        }
        self::assertNull($pasta->getCarteira());
        self::assertSame([], $this->persistidos);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function useCase(EntityManagerInterface $em, bool $podeEditar = true): MoverPastaDeCarteiraUseCase
    {
        $checker = $this->createStub(PermissionChecker::class);
        $checker->method('canAccessResource')->willReturn($podeEditar);

        return new MoverPastaDeCarteiraUseCase($checker, new RegistroDoSistemaNaPasta($em), $em);
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
