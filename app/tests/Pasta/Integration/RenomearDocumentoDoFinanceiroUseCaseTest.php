<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Integration;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\UseCase\RenomearDocumentoDoFinanceiroUseCase;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Renomear o documento do Financeiro (a tela `pasta_financeiro_documento_renomear` e o conector). Integração, e não
 * unitário com mock: o UseCase recebe o EntityManager direto, e o EntityManager não se mocka (tests/CLAUDE.md).
 */
#[CoversClass(RenomearDocumentoDoFinanceiroUseCase::class)]
final class RenomearDocumentoDoFinanceiroUseCaseTest extends KernelTestCase
{
    private Tenant $escritorio;
    private Pasta $pasta;

    protected function setUp(): void
    {
        $this->escritorio = TenantFactory::createOne()->_real();
        $this->pasta = $this->pasta($this->escritorio);
    }

    #[TestDox('troca o nome (sem espaços nas pontas, em maiúsculas como a tela) e grava')]
    public function testRenomeia(): void
    {
        $documento = $this->documento($this->pasta, $this->escritorio);

        $titulo = $this->useCase()->executar($this->pasta, $documento, '  novo.pdf ', $this->escritorio);

        self::assertSame('NOVO.PDF', $titulo);
        self::assertSame('NOVO.PDF', $this->relido($documento)->getTitulo());
    }

    #[TestDox('nome vazio e nome com mais de 255 caracteres são recusados, com a mensagem da tela, sem gravar')]
    public function testRecusaNomeInvalido(): void
    {
        $documento = $this->documento($this->pasta, $this->escritorio);
        $casos = ['   ' => 'O nome não pode ser vazio.', str_repeat('a', 256) => 'Nome muito longo (máx. 255 caracteres).'];

        foreach ($casos as $nome => $mensagem) {
            try {
                $this->useCase()->executar($this->pasta, $documento, (string) $nome, $this->escritorio);
                self::fail('deveria recusar');
            } catch (\InvalidArgumentException $e) {
                self::assertSame($mensagem, $e->getMessage());
            }
        }
        self::assertSame('ANTIGO.PDF', $this->relido($documento)->getTitulo());
    }

    #[TestDox('pasta de outro escritório é recusada')]
    public function testPastaDeOutroEscritorio(): void
    {
        $documento = $this->documento($this->pasta, $this->escritorio);

        $this->expectException(PastaDeOutroEscritorioException::class);

        $this->useCase()->executar($this->pasta, $documento, 'novo.pdf', TenantFactory::createOne()->_real());
    }

    #[TestDox('documento de outra pasta, de outro escritório ou que não é contrato: não encontrado nesta pasta')]
    public function testDocumentoQueNaoServe(): void
    {
        $outro = TenantFactory::createOne()->_real();
        $casos = [
            $this->documento($this->pasta($this->escritorio), $this->escritorio),
            $this->documento($this->pasta, $this->escritorio, PastaDocumento::CATEGORIA_DEMAIS),
            $this->documento($this->pasta, $outro),
        ];

        foreach ($casos as $documento) {
            try {
                $this->useCase()->executar($this->pasta, $documento, 'novo.pdf', $this->escritorio);
                self::fail('deveria recusar');
            } catch (\DomainException $e) {
                self::assertSame('Documento não encontrado nesta pasta.', $e->getMessage());
            }
        }
    }

    private function useCase(): RenomearDocumentoDoFinanceiroUseCase
    {
        return static::getContainer()->get(RenomearDocumentoDoFinanceiroUseCase::class);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function pasta(Tenant $escritorio): Pasta
    {
        $pasta = (new Pasta())->setNup('REN-' . uniqid());
        $pasta->setTenant($escritorio);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function documento(Pasta $pasta, Tenant $escritorio, string $categoria = PastaDocumento::CATEGORIA_CONTRATO): PastaDocumento
    {
        $documento = new PastaDocumento();
        $documento->setTenant($escritorio);
        $documento->setPasta($pasta);
        $documento->setTitulo('antigo.pdf');
        $documento->setCategoria($categoria);
        $documento->setCaminhoArquivo(uniqid('doc', true) . '.bin');
        $documento->setNomeOriginal('antigo.pdf');
        $documento->setMimeType('application/pdf');
        $documento->setTamanhoBytes(10);
        $this->em()->persist($documento);
        $this->em()->flush();

        return $documento;
    }

    private function relido(PastaDocumento $documento): PastaDocumento
    {
        $id = (int) $documento->getId();
        $this->em()->clear();

        return $this->em()->find(PastaDocumento::class, $id) ?? throw new \LogicException('documento sumiu');
    }
}
