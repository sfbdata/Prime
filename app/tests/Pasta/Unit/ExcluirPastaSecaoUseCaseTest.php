<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\UseCase\ExcluirItensDaPastaUseCase;
use App\Pasta\UseCase\ExcluirPastaSecaoUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Excluir UMA subpasta (`pasta_secao_excluir`) é lixeira desde o L7: a subárvore inteira recebe
 * a lápide com o mesmo carimbo, nada é removido, e as contagens voltam para a rota.
 */
#[CoversClass(ExcluirPastaSecaoUseCase::class)]
final class ExcluirPastaSecaoUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private ExcluirPastaSecaoUseCase $useCase;
    private Tenant $tenant;
    private User $autor;
    private Pasta $pasta;
    private PastaSecao $secao;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new ExcluirPastaSecaoUseCase(new ExcluirItensDaPastaUseCase($this->em, new MockClock('2026-10-07 10:00:00')));

        $this->tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($this->tenant, 7);
        $this->autor  = (new User())->setEmail('autor@test.com');
        $this->pasta  = (new Pasta())->setTenant($this->tenant);

        $this->secao = new PastaSecao();
        $this->secao->setTenant($this->tenant);
        $this->secao->setPasta($this->pasta);
        $this->secao->setNome('Petições');
    }

    #[TestDox('marca a seção, a filha e os documentos com o mesmo carimbo; flush uma vez; remove() nunca')]
    public function testMandaAArvoreParaALixeira(): void
    {
        $filha = (new PastaSecao())->setNome('FILHA')->setPai($this->secao);
        $filha->setTenant($this->tenant);
        $filha->setPasta($this->pasta);
        $doc = (new PastaDocumento())->setTenant($this->tenant)->setPasta($this->pasta)->setSecao($filha);
        $filha->getDocumentos()->add($doc);

        $this->em->expects($this->never())->method('remove');
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar($this->secao, $this->autor, $this->tenant);

        self::assertSame(2, $resultado->subpastasRemovidas, 'a seção selecionada + a filha (a rota tira a própria ao responder)');
        self::assertSame(1, $resultado->arquivosRemovidos);
        self::assertTrue($this->secao->estaNaLixeira());
        self::assertTrue($filha->estaNaLixeira());
        self::assertTrue($doc->estaNaLixeira());
        self::assertSame($this->secao->getExcluidoEm(), $doc->getExcluidoEm());
        self::assertSame($this->autor, $filha->getExcluidoPor());
    }

    #[TestDox('tenant diverge: AccessDenied antes de tocar em qualquer coisa')]
    public function testTenantDivergeLancaAccessDeniedException(): void
    {
        $outraTenant = new Tenant();

        $this->em->expects($this->never())->method('flush');

        $this->expectException(AccessDeniedException::class);

        try {
            $this->useCase->executar($this->secao, $this->autor, $outraTenant);
        } finally {
            self::assertFalse($this->secao->estaNaLixeira());
        }
    }

    #[TestDox('seção sem pasta é pedido inválido')]
    public function testSecaoSemPasta(): void
    {
        $this->secao->setPasta(null);
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        $this->useCase->executar($this->secao, $this->autor, $this->tenant);
    }
}
