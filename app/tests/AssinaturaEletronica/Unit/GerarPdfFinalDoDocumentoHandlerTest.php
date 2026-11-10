<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Message\GerarPdfFinalDoDocumento;
use App\AssinaturaEletronica\MessageHandler\GerarPdfFinalDoDocumentoHandler;
use App\AssinaturaEletronica\Service\PdfFinalDoDocumento;
use App\Entity\Tenant\Tenant;
use App\Repository\TenantRepository;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Tests\AssinaturaEletronica\Support\OficinaDosUseCasesDaAssinatura;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * O worker do PDF final (contrato da etapa 3): procura o documento no escritório da mensagem, mesmo na Lixeira, e só
 * garante o PDF do concluído. Escritório ou documento que sumiu, ou que não está concluído: nada a fazer.
 */
#[CoversClass(GerarPdfFinalDoDocumentoHandler::class)]
#[CoversClass(GerarPdfFinalDoDocumento::class)]
final class GerarPdfFinalDoDocumentoHandlerTest extends TestCase
{
    use OficinaDosUseCasesDaAssinatura;

    private TenantRepository&MockObject $tenants;
    private PdfFinalDoDocumento&MockObject $pdfFinal;

    protected function setUp(): void
    {
        $this->montarOficina();
        $this->tenants = $this->createMock(TenantRepository::class);
        $outro = (new Tenant())->setName('Outro Escritório');
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($outro, 77);
        $this->tenants->method('find')->willReturnCallback(fn (mixed $id): ?Tenant => match ($id) {
            9 => $this->tenant,
            77 => $outro,
            default => null,
        });
        $this->pdfFinal = $this->createMock(PdfFinalDoDocumento::class);
    }

    #[TestDox('documento concluído do escritório da mensagem: garante o PDF final')]
    public function testConcluido(): void
    {
        $documento = $this->concluido();
        $this->pdfFinal->expects(self::once())->method('garantir')->with(self::identicalTo($documento))->willReturn(self::chave());

        $this->handler()(new GerarPdfFinalDoDocumento(1, 9));
    }

    #[TestDox('documento concluído e já na Lixeira: o PDF final ainda é guardado (a prova não depende da Lixeira)')]
    public function testNaLixeira(): void
    {
        $documento = $this->concluido();
        $documento->moverParaLixeira(null, new \DateTimeImmutable('2026-11-09 09:45:00'));
        $this->pdfFinal->expects(self::once())->method('garantir')->with(self::identicalTo($documento))->willReturn(self::chave());

        $this->handler()(new GerarPdfFinalDoDocumento(1, 9));
    }

    #[TestDox('não concluído, documento de outro escritório, escritório ou documento que sumiu: nada a fazer')]
    public function testNadaAFazer(): void
    {
        $this->documentoEnviado();
        $this->pdfFinal->expects(self::never())->method('garantir');

        $this->handler()(new GerarPdfFinalDoDocumento(1, 9));   // aguardando
        $this->handler()(new GerarPdfFinalDoDocumento(1, 77));  // o id do documento, em outro escritório
        $this->handler()(new GerarPdfFinalDoDocumento(1, 404)); // escritório que sumiu
        $this->handler()(new GerarPdfFinalDoDocumento(404, 9)); // documento que sumiu
    }

    private function handler(): GerarPdfFinalDoDocumentoHandler
    {
        return new GerarPdfFinalDoDocumentoHandler($this->tenants, $this->documentos, $this->pdfFinal);
    }

    private static function chave(): ChaveDeArquivo
    {
        return new ChaveDeArquivo(EscopoDeArquivo::deTenant(9), CategoriaDeArquivo::ASSINATURA_DOCUMENTO, 'final.pdf');
    }

    private function concluido(): DocumentoParaAssinar
    {
        $documento = $this->documentoEnviado();
        foreach ($documento->getSignatarios() as $i => $signatario) {
            $documento->assinar($signatario, $signatario->getNome(), '11144477735', 'rubrica-' . $i . '.png', 'simples', null, null, new \DateTimeImmutable('2026-11-09 09:3' . $i . ':00'));
        }

        return $documento;
    }
}
