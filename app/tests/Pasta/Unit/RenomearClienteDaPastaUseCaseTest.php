<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Service\ComporNomeDaPastaJudicial;
use App\Cobranca\Service\ResolvedorIdentificadorDaPasta;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\UseCase\RenomearClienteDaPastaUseCase;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(RenomearClienteDaPastaUseCase::class)]
final class RenomearClienteDaPastaUseCaseTest extends TestCase
{
    #[TestDox('grava em caixa alta, sem espaços repetidos, e devolve o nome gravado')]
    public function testGrava(): void
    {
        [$pasta, $tenant] = $this->pasta();

        $nome = $this->useCase(true, null, 1)->executar($pasta, '  ana   souza ', new User(), $tenant);

        self::assertSame('ANA SOUZA', $nome);
        self::assertSame('ANA SOUZA', $pasta->getNomeCliente());
    }

    #[TestDox('nome montado pela cobrança (credor e devedor) não se edita, como na tela da pasta')]
    public function testDerivadoDaCobranca(): void
    {
        [$pasta, $tenant] = $this->pasta();
        $pasta->setNomeCliente('TEXTO ANTIGO');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('montado a partir do credor da carteira');
        try {
            $this->useCase(true, ['fantasia' => 'CONDOMINIO X', 'razaoSocial' => 'CONDOMINIO X LTDA'], 0)->executar($pasta, 'OUTRO', new User(), $tenant);
        } finally {
            self::assertSame('TEXTO ANTIGO', $pasta->getNomeCliente());
        }
    }

    #[TestDox('sem permissão de editar a pasta, recusa')]
    public function testSemPermissao(): void
    {
        [$pasta, $tenant] = $this->pasta();

        $this->expectException(SemPermissaoParaEditarPastaException::class);

        $this->useCase(false, null, 0)->executar($pasta, 'NOME', new User(), $tenant);
    }

    #[TestDox('pasta de outro escritório é recusada')]
    public function testOutroEscritorio(): void
    {
        [$pasta] = $this->pasta();

        $this->expectException(PastaDeOutroEscritorioException::class);

        $this->useCase(true, null, 0)->executar($pasta, 'NOME', new User(), new Tenant());
    }

    #[TestDox('nome vazio é recusado')]
    public function testVazio(): void
    {
        [$pasta, $tenant] = $this->pasta();

        $this->expectException(\DomainException::class);

        $this->useCase(true, null, 0)->executar($pasta, "  \t ", new User(), $tenant);
    }

    /** @return array{0: Pasta, 1: Tenant} */
    private function pasta(): array
    {
        $tenant = new Tenant();
        $pasta  = new Pasta();
        $pasta->setTenant($tenant);
        (new \ReflectionProperty(Pasta::class, 'id'))->setValue($pasta, 77);
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, 5);

        return [$pasta, $tenant];
    }

    /** @param array{fantasia: string, razaoSocial: string}|null $cobranca */
    private function useCase(bool $podeEditar, ?array $cobranca, int $flushes): RenomearClienteDaPastaUseCase
    {
        $checker = $this->createStub(PermissionChecker::class);
        $checker->method('canAccessResource')->willReturn($podeEditar);
        $casos = $this->createStub(CasoCobrancaRepository::class);
        $casos->method('clienteDaCarteiraPorPasta')->willReturn($cobranca === null ? [] : [77 => $cobranca]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->exactly($flushes))->method('flush');

        return new RenomearClienteDaPastaUseCase($checker, $em, new ResolvedorIdentificadorDaPasta($casos, new ComporNomeDaPastaJudicial()));
    }
}
