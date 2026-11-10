<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\Pdf\PerfilDoPdf;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\LimitesDaConferencia;
use App\AssinaturaEletronica\Service\PdfDaAssinatura;
use App\Tests\AssinaturaEletronica\Support\OficinaDosUseCasesDaAssinatura;
use App\Tests\AssinaturaEletronica\Unit\Pdf\FolhaDeTeste;
use App\Tests\AssinaturaEletronica\Unit\Pdf\PdfLido;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O perfil do PDF com selo: quem pede o leve recebe a imagem com metade dos pixels; quem não pede nada continua com o
 * desenho. E o teto que decide quando pedir o leve, abaixo do limite do motor.
 */
#[CoversClass(PdfDaAssinatura::class)]
final class PdfDaAssinaturaPerfilTest extends TestCase
{
    use OficinaDosUseCasesDaAssinatura;

    protected function setUp(): void
    {
        $this->montarOficina();
    }

    #[TestDox('O perfil chega ao montador: o leve tem a imagem de 1240 x 1754 px; sem perfil, a do desenho')]
    public function testRepassaOPerfil(): void
    {
        FolhaDeTeste::exigirFontes($this);
        $documento = $this->documentoEnviado();
        $pdf = $this->pdfDaAssinatura();

        $leve = PdfLido::dosPedacos($pdf->pedacos($documento, perfil: PerfilDoPdf::Leve));
        $padrao = PdfLido::dosPedacos($pdf->pedacos($documento));

        // O documento da oficina não tem blocos: o PDF sai só com o manifesto (dc:406).
        self::assertSame(1, $leve->paginas());
        self::assertSame(1, $padrao->paginas());
        self::assertSame([[1240, 1754]], $leve->medidasDasImagens());
        self::assertSame([[2480, 3507]], $padrao->medidasDasImagens());
        self::assertLessThan(\strlen($padrao->bytes) / 2, \strlen($leve->bytes));
    }

    #[TestDox('O teto para o motor fica 1 MiB abaixo do limite de 20 MB da conferência')]
    public function testTetoParaOMotor(): void
    {
        self::assertSame(19 * 1024 * 1024, PdfDaAssinatura::TETO_PARA_O_MOTOR);
        self::assertSame(LimitesDaConferencia::PDF_MAXIMO_EM_BYTES - 1024 * 1024, PdfDaAssinatura::TETO_PARA_O_MOTOR);
    }
}
