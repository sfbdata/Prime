<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPrazo;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\Repository\PastaPrazoRepository;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use App\Pasta\UseCase\CumprirPrazoDaPastaUseCase;
use App\Pasta\UseCase\SalvarPrazoDaPastaUseCase;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(SalvarPrazoDaPastaUseCase::class)]
#[CoversClass(CumprirPrazoDaPastaUseCase::class)]
#[CoversClass(PastaPrazo::class)]
final class SalvarPrazoDaPastaUseCaseTest extends TestCase
{
    #[TestDox('novo prazo: define os dados, persiste e escreve o cartão "Prazo adicionado"')]
    public function testNovo(): void
    {
        [$pasta, $tenant, $user] = $this->cenario();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->exactly(2))->method('persist');   // o prazo e o cartão do sistema
        $em->expects($this->once())->method('flush');

        $prazo = (new SalvarPrazoDaPastaUseCase($this->createStub(PastaPrazoRepository::class), $this->checker(true), new RegistroDoSistemaNaPasta($em), $em))
            ->executar($pasta, $user, $tenant, null, 'prazo', ' Contestação ', '2030-02-01', '', ' ');

        self::assertSame('Contestação', $prazo->getTitulo());
        self::assertNull($prazo->getHora());
        self::assertNull($prazo->getResponsavel());
        self::assertSame('2030-02-01', $prazo->getData()->format('Y-m-d'));
    }

    #[TestDox('sem permissão de editar a pasta, recusa sem gravar')]
    public function testSemPermissao(): void
    {
        [$pasta, $tenant, $user] = $this->cenario();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $this->expectException(SemPermissaoParaEditarPastaException::class);

        (new SalvarPrazoDaPastaUseCase($this->createStub(PastaPrazoRepository::class), $this->checker(false), new RegistroDoSistemaNaPasta($em), $em))
            ->executar($pasta, $user, $tenant, null, 'prazo', 'X', '2030-02-01', null, null);
    }

    #[TestDox('pasta de outro escritório é recusada')]
    public function testOutroEscritorio(): void
    {
        [$pasta, , $user] = $this->cenario();
        $em = $this->createStub(EntityManagerInterface::class);

        $this->expectException(PastaDeOutroEscritorioException::class);

        (new SalvarPrazoDaPastaUseCase($this->createStub(PastaPrazoRepository::class), $this->checker(true), new RegistroDoSistemaNaPasta($em), $em))
            ->executar($pasta, $user, new Tenant(), null, 'prazo', 'X', '2030-02-01', null, null);
    }

    #[TestDox('data inválida, hora inválida ou tipo desconhecido são recusados')]
    public function testValidacoes(): void
    {
        [$pasta, $tenant, $user] = $this->cenario();
        $em = $this->createStub(EntityManagerInterface::class);
        $uc = new SalvarPrazoDaPastaUseCase($this->createStub(PastaPrazoRepository::class), $this->checker(true), new RegistroDoSistemaNaPasta($em), $em);

        foreach ([['prazo', '31/12/2030', null], ['prazo', '2030-12-31', '25:00'], ['reuniao', '2030-12-31', null]] as [$tipo, $data, $hora]) {
            try {
                $uc->executar($pasta, $user, $tenant, null, $tipo, 'X', $data, $hora, null);
                self::fail('deveria recusar ' . $tipo . ' ' . $data . ' ' . $hora);
            } catch (\DomainException) {
                self::assertTrue(true);
            }
        }
    }

    #[TestDox('cumprir duas vezes é recusado; prazo que não é da pasta não é encontrado')]
    public function testCumprir(): void
    {
        [$pasta, $tenant, $user] = $this->cenario();
        $prazo = new PastaPrazo($tenant, $pasta, $user);
        $prazo->definir('prazo', 'X', new \DateTimeImmutable('2030-01-01'), null, null);
        $repo = $this->createStub(PastaPrazoRepository::class);
        $repo->method('findDaPasta')->willReturnCallback(static fn (int $id) => $id === 1 ? $prazo : null);
        $em = $this->createStub(EntityManagerInterface::class);
        $uc = new CumprirPrazoDaPastaUseCase($repo, $this->checker(true), new RegistroDoSistemaNaPasta($em), $em);

        $uc->executar($pasta, 1, $user, $tenant);
        self::assertTrue($prazo->estaCumprido());

        try {
            $uc->executar($pasta, 1, $user, $tenant);
            self::fail('cumprir de novo');
        } catch (\DomainException $e) {
            self::assertSame('Este prazo já foi cumprido.', $e->getMessage());
        }

        $this->expectExceptionMessage('Prazo não encontrado.');
        $uc->executar($pasta, 2, $user, $tenant);
    }

    /** @return array{0: Pasta, 1: Tenant, 2: User} */
    private function cenario(): array
    {
        $tenant = new Tenant();
        $pasta = new Pasta();
        $pasta->setTenant($tenant);

        return [$pasta, $tenant, (new User())->setEmail('p@test.com')];
    }

    private function checker(bool $pode): PermissionChecker
    {
        $c = $this->createStub(PermissionChecker::class);
        $c->method('canAccessResource')->willReturn($pode);

        return $c;
    }
}
