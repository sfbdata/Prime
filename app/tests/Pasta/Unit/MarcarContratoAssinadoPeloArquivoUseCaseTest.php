<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\UseCase\AlterarSituacaoContratoUseCase;
use App\Pasta\UseCase\MarcarContratoAssinadoPeloArquivoUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(MarcarContratoAssinadoPeloArquivoUseCase::class)]
final class MarcarContratoAssinadoPeloArquivoUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private MarcarContratoAssinadoPeloArquivoUseCase $useCase;
    private User $autor;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new MarcarContratoAssinadoPeloArquivoUseCase(new AlterarSituacaoContratoUseCase($this->em));
        $this->autor   = new User();
    }

    private function documento(Pasta $pasta, string $nome, bool $assinado, string $categoria = PastaDocumento::CATEGORIA_CONTRATO): PastaDocumento
    {
        $doc = new PastaDocumento();
        $doc->setPasta($pasta);
        $doc->setCategoria($categoria);
        $doc->setNomeOriginal($nome);
        $doc->marcarAssinaturaDoUpload($assinado, false, $this->autor);

        return $doc;
    }

    #[TestDox('contrato assinado digitalmente (nome com "contrato") marca a pasta como Assinado')]
    public function testMarcaComoAssinado(): void
    {
        $pasta = new Pasta();
        $this->em->expects($this->once())->method('flush');

        $situacao = $this->useCase->executar($pasta, $this->documento($pasta, 'Contrato de honorários.p7s', true), $this->autor, null);

        self::assertSame('REGULAR', $situacao);
        self::assertSame('REGULAR', $pasta->getSituacaoContrato());
    }

    #[TestDox('o nome vale sem diferenciar maiúscula')]
    public function testNomeSemDiferenciarMaiuscula(): void
    {
        $pasta = new Pasta();

        $this->useCase->executar($pasta, $this->documento($pasta, 'CONTRATO_assinado.pdf', true), $this->autor, null);

        self::assertSame('REGULAR', $pasta->getSituacaoContrato());
    }

    #[TestDox('arquivo assinado cujo nome não é de contrato (recibo) não mexe no contrato')]
    public function testReciboAssinadoNaoMarca(): void
    {
        $pasta = new Pasta();
        $this->em->expects($this->never())->method('flush');

        $situacao = $this->useCase->executar($pasta, $this->documento($pasta, 'Recibo de entrada.pdf', true), $this->autor, null);

        self::assertSame('PENDENTE', $situacao);
    }

    #[TestDox('contrato sem assinatura digital não marca')]
    public function testSemAssinaturaNaoMarca(): void
    {
        $pasta = new Pasta();
        $this->em->expects($this->never())->method('flush');

        $this->useCase->executar($pasta, $this->documento($pasta, 'Contrato.pdf', false), $this->autor, null);

        self::assertSame('PENDENTE', $pasta->getSituacaoContrato());
    }

    #[TestDox('documento de outra categoria não marca')]
    public function testOutraCategoriaNaoMarca(): void
    {
        $pasta = new Pasta();
        $this->em->expects($this->never())->method('flush');

        $this->useCase->executar($pasta, $this->documento($pasta, 'Contrato.pdf', true, 'outro'), $this->autor, null);

        self::assertSame('PENDENTE', $pasta->getSituacaoContrato());
    }

    #[TestDox('documento de outra pasta não marca esta')]
    public function testDocumentoDeOutraPastaNaoMarca(): void
    {
        $pasta = new Pasta();
        $this->em->expects($this->never())->method('flush');

        $this->useCase->executar($pasta, $this->documento(new Pasta(), 'Contrato.p7s', true), $this->autor, null);

        self::assertSame('PENDENTE', $pasta->getSituacaoContrato());
    }

    #[TestDox('pasta já Assinada não muda nem grava de novo')]
    public function testJaAssinadaNaoMuda(): void
    {
        $pasta = new Pasta();
        $pasta->setSituacaoContrato('REGULAR');
        $this->em->expects($this->never())->method('flush');

        $situacao = $this->useCase->executar($pasta, $this->documento($pasta, 'Contrato.p7s', true), $this->autor, null);

        self::assertSame('REGULAR', $situacao);
    }
}
