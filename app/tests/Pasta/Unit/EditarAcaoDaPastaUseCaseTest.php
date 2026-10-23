<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use App\Pasta\UseCase\EditarAcaoDaPastaUseCase;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(EditarAcaoDaPastaUseCase::class)]
final class EditarAcaoDaPastaUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private PermissionChecker&MockObject $permissionChecker;
    private EditarAcaoDaPastaUseCase $useCase;
    private Tenant $tenant;
    private User $usuario;
    private Pasta $pasta;

    /** @var list<object> */
    private array $persistidos = [];

    protected function setUp(): void
    {
        $this->em                = $this->createMock(EntityManagerInterface::class);
        $this->permissionChecker = $this->createMock(PermissionChecker::class);
        $this->em->method('persist')->willReturnCallback(function (object $o): void {
            $this->persistidos[] = $o;
        });
        $this->useCase = new EditarAcaoDaPastaUseCase($this->em, $this->permissionChecker, new RegistroDoSistemaNaPasta($this->em));

        $this->tenant  = new Tenant();
        $this->usuario = new User();
        $this->pasta   = new Pasta();
        $this->pasta->setNup('1234');
        $this->pasta->setTenant($this->tenant);
        $this->pasta->setNomeAcao('COBRANÇA');
    }

    private function podeEditar(bool $pode): void
    {
        $this->permissionChecker
            ->expects(self::once())
            ->method('canAccessResource')
            ->with($this->usuario, $this->tenant, AccessRequest::RESOURCE_PASTA, self::anything(), AccessRequest::ACTION_EDIT)
            ->willReturn($pode);
    }

    /** @return list<PastaMensagem> */
    private function cartoes(): array
    {
        return array_values(array_filter($this->persistidos, static fn (object $o): bool => $o instanceof PastaMensagem));
    }

    #[TestDox('Troca a Ação (em maiúsculas, como a entidade grava), dá um flush, devolve true e escreve o cartão "Dados da pasta" no Registro')]
    public function testTrocaERegistra(): void
    {
        $this->podeEditar(true);
        $this->em->expects(self::once())->method('flush');

        self::assertTrue($this->useCase->executar($this->pasta, '  Ação de despejo  ', $this->usuario, $this->tenant));

        self::assertSame('AÇÃO DE DESPEJO', $this->pasta->getNomeAcao());
        $cartoes = $this->cartoes();
        self::assertCount(1, $cartoes);
        self::assertSame('pasta', $cartoes[0]->getSistema());
        self::assertSame($this->usuario, $cartoes[0]->getAutor());
        self::assertSame($this->tenant, $cartoes[0]->getTenant());
        self::assertSame(
            '<p><strong>Dados da pasta:</strong> ação alterada pela aba Processo: &quot;COBRANÇA&quot; → &quot;AÇÃO DE DESPEJO&quot;.</p>',
            $cartoes[0]->getConteudo(),
        );
    }

    #[TestDox('Campo vazio limpa a Ação e o cartão diz "Não informado" no lugar do valor novo')]
    public function testVazioLimpa(): void
    {
        $this->podeEditar(true);
        $this->em->expects(self::once())->method('flush');

        self::assertTrue($this->useCase->executar($this->pasta, '   ', $this->usuario, $this->tenant));

        self::assertNull($this->pasta->getNomeAcao());
        self::assertStringContainsString('&quot;COBRANÇA&quot; → &quot;Não informado&quot;.', $this->cartoes()[0]->getConteudo());
    }

    #[TestDox('Mesma Ação (só muda caixa e espaços) não grava: sem flush, sem cartão, devolve false')]
    public function testSemMudanca(): void
    {
        $this->podeEditar(true);
        $this->em->expects(self::never())->method('flush');

        self::assertFalse($this->useCase->executar($this->pasta, ' cobrança ', $this->usuario, $this->tenant));

        self::assertSame([], $this->cartoes());
    }

    #[TestDox('Pasta de outro escritório: PastaDeOutroEscritorioException, sem nem perguntar a permissão, e nada muda')]
    public function testOutroEscritorio(): void
    {
        $this->permissionChecker->expects(self::never())->method('canAccessResource');
        $this->em->expects(self::never())->method('flush');

        $this->expectException(PastaDeOutroEscritorioException::class);
        try {
            $this->useCase->executar($this->pasta, 'OUTRA', $this->usuario, new Tenant());
        } finally {
            self::assertSame('COBRANÇA', $this->pasta->getNomeAcao());
        }
    }

    #[TestDox('Sem EDIT na pasta (inclui o teto do cadeado): SemPermissaoParaEditarPastaException e nada muda')]
    public function testSemPermissao(): void
    {
        $this->podeEditar(false);
        $this->em->expects(self::never())->method('flush');

        $this->expectException(SemPermissaoParaEditarPastaException::class);
        try {
            $this->useCase->executar($this->pasta, 'OUTRA', $this->usuario, $this->tenant);
        } finally {
            self::assertSame('COBRANÇA', $this->pasta->getNomeAcao());
            self::assertSame([], $this->cartoes());
        }
    }

    #[TestDox('Mais de 255 caracteres: InvalidArgumentException e nada muda')]
    public function testLongaDemais(): void
    {
        $this->podeEditar(true);
        $this->em->expects(self::never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->useCase->executar($this->pasta, str_repeat('Á', 256), $this->usuario, $this->tenant);
        } finally {
            self::assertSame('COBRANÇA', $this->pasta->getNomeAcao());
        }
    }
}
