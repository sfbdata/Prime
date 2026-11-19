<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\UseCase\RenomearDocumentoDoFinanceiroUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(RenomearDocumentoDoFinanceiroUseCase::class)]
final class RenomearDocumentoDoFinanceiroUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private RenomearDocumentoDoFinanceiroUseCase $useCase;
    private Tenant $tenant;
    private Pasta $pasta;
    private PastaDocumento $documento;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new RenomearDocumentoDoFinanceiroUseCase($this->em);
        $this->tenant = self::comId(new Tenant(), 1);
        $this->pasta = self::comId((new Pasta())->setTenant($this->tenant), 10);
        $this->documento = $this->contrato($this->pasta, $this->tenant);
    }

    #[TestDox('troca o nome (sem espaços nas pontas) e dá um flush')]
    public function testRenomeia(): void
    {
        $this->em->expects($this->once())->method('flush');

        $titulo = $this->useCase->executar($this->pasta, $this->documento, '  novo.pdf ', $this->tenant);

        self::assertSame('NOVO.PDF', $titulo);
        self::assertSame('NOVO.PDF', $this->documento->getTitulo());
    }

    #[TestDox('nome vazio e nome com mais de 255 caracteres são recusados, com a mensagem da tela, sem gravar')]
    public function testRecusaNomeInvalido(): void
    {
        $this->em->expects($this->never())->method('flush');

        try {
            $this->useCase->executar($this->pasta, $this->documento, '   ', $this->tenant);
            self::fail('deveria recusar');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('O nome não pode ser vazio.', $e->getMessage());
        }
        try {
            $this->useCase->executar($this->pasta, $this->documento, str_repeat('a', 256), $this->tenant);
            self::fail('deveria recusar');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Nome muito longo (máx. 255 caracteres).', $e->getMessage());
        }
        self::assertSame('ANTIGO.PDF', $this->documento->getTitulo());
    }

    #[TestDox('pasta de outro escritório é recusada')]
    public function testPastaDeOutroEscritorio(): void
    {
        $this->em->expects($this->never())->method('flush');

        $this->expectException(PastaDeOutroEscritorioException::class);

        $this->useCase->executar($this->pasta, $this->documento, 'novo.pdf', self::comId(new Tenant(), 2));
    }

    #[TestDox('documento de outra pasta, de outro escritório ou que não é contrato: não encontrado nesta pasta')]
    public function testDocumentoQueNaoServe(): void
    {
        $this->em->expects($this->never())->method('flush');
        $outraPasta = self::comId((new Pasta())->setTenant($this->tenant), 11);
        $demais = $this->contrato($this->pasta, $this->tenant);
        $demais->setCategoria(PastaDocumento::CATEGORIA_DEMAIS);

        $casos = [
            $this->contrato($outraPasta, $this->tenant),
            $demais,
            $this->contrato($this->pasta, self::comId(new Tenant(), 2)),
        ];
        foreach ($casos as $documento) {
            try {
                $this->useCase->executar($this->pasta, $documento, 'novo.pdf', $this->tenant);
                self::fail('deveria recusar');
            } catch (\DomainException $e) {
                self::assertSame('Documento não encontrado nesta pasta.', $e->getMessage());
            }
        }
    }

    private function contrato(Pasta $pasta, Tenant $tenant): PastaDocumento
    {
        $documento = self::comId(new PastaDocumento(), 20);
        $documento->setTenant($tenant);
        $documento->setPasta($pasta);
        $documento->setCategoria(PastaDocumento::CATEGORIA_CONTRATO);
        $documento->setTitulo('antigo.pdf');

        return $documento;
    }

    /**
     * @template T of object
     *
     * @param T $entidade
     *
     * @return T
     */
    private static function comId(object $entidade, int $id): object
    {
        $propriedade = new \ReflectionProperty($entidade, 'id');
        $propriedade->setValue($entidade, $id);

        return $entidade;
    }
}
