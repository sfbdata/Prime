<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\Service\Cadeia\RegraDaCadeia;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDePdf;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDoAcrescimo;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDoPrefixo;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ResultadoDaConferencia;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\SituacaoDaConferencia;
use App\Tests\AssinaturaEletronica\Unit\ConferenciaDePdf\OficinaDePdfAssinado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A regra antes do validador (E6-6) com o motor de verdade, não com o motor de exemplo: a situação `nao_conferido`
 * que vem do carimbo de documento ou dos bytes depois da última assinatura vai ao validador; o acréscimo
 * `nao_conferido` da assinatura nova recusa antes dele.
 */
#[CoversClass(RegraDaCadeia::class)]
final class RegraDaCadeiaComOMotorTest extends TestCase
{
    private ConferenciaDePdf $motor;

    protected function setUp(): void
    {
        $this->motor = new ConferenciaDePdf();
    }

    #[TestDox('nova seguida do carimbo de documento: o motor diz não conferido (naoverif), e o validador decide só a nova')]
    public function testNovaComCarimboVaiAoValidador(): void
    {
        $paraAssinar = OficinaDePdfAssinado::pdfAssinado('rsa');
        $comNova = self::novaAssinatura($paraAssinar);
        $comCarimbo = OficinaDePdfAssinado::assinar(
            OficinaDePdfAssinado::acrescimoParaAssinar($comNova, '', 'ETSI.RFC3161', [
                1 => '<</Type/Catalog/Pages 2 0 R/AcroForm<</Fields[4 0 R 6 0 R 8 0 R]/SigFlags 3>>>>',
                3 => '<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Annots[4 0 R 6 0 R 8 0 R]>>',
            ], widget: 8),
            OficinaDePdfAssinado::cmsDoOpenssl(OficinaDePdfAssinado::chave('ec'), OficinaDePdfAssinado::certificado(OficinaDePdfAssinado::chave('ec'), 'ACT DE TESTE')),
        );

        $motor = $this->conferir($comCarimbo, $paraAssinar);

        self::assertSame(SituacaoDaConferencia::NaoConferido, $motor->situacao, 'o carimbo deixa o motor em não conferido');
        self::assertSame(2, $motor->assinaturasNovas);
        self::assertNull((new RegraDaCadeia())->antesDoValidador($motor), (string) $motor->motivo);
        $novas = RegraDaCadeia::novas($motor);
        self::assertCount(1, $novas, 'o carimbo não conta como assinatura nova');
        self::assertSame(2, $novas[0]->ordem);
    }

    #[TestDox('nova seguida de bytes sem assinatura (DSS): o motor diz não conferido, e o validador decide')]
    public function testNovaComBytesDepoisVaiAoValidador(): void
    {
        $paraAssinar = OficinaDePdfAssinado::pdfAssinado('rsa');
        $comDss = OficinaDePdfAssinado::acrescimoSemAssinatura(self::novaAssinatura($paraAssinar), [
            1 => '<</Type/Catalog/Pages 2 0 R/AcroForm<</Fields[4 0 R 6 0 R]/SigFlags 3>>/DSS 8 0 R>>',
            8 => '<</Type/DSS/Certs[]>>',
        ]);

        $motor = $this->conferir($comDss, $paraAssinar);

        self::assertSame(SituacaoDaConferencia::NaoConferido, $motor->situacao, 'os bytes depois deixam o motor em não conferido');
        self::assertGreaterThan(0, $motor->assinaturas[1]->bytesDepois);
        self::assertNull((new RegraDaCadeia())->antesDoValidador($motor), (string) $motor->motivo);
        self::assertCount(1, RegraDaCadeia::novas($motor));
    }

    #[TestDox('nova com acréscimo que o motor não lê: recusa antes do validador')]
    public function testAcrescimoNaoConferidoDaNovaRecusa(): void
    {
        $paraAssinar = OficinaDePdfAssinado::pdfAssinado('rsa');
        $quebrada = self::novaAssinatura($paraAssinar, "8 1 obj\n<<>>\nendobj\n");

        $motor = $this->conferir($quebrada, $paraAssinar);

        self::assertSame(ConferenciaDoAcrescimo::NaoConferido, $motor->assinaturas[1]->acrescimo);
        self::assertSame(RegraDaCadeia::ACRESCIMO_NAO_CONFERIDO, (new RegraDaCadeia())->antesDoValidador($motor));
    }

    private function conferir(string $pdf, string $paraAssinar): ResultadoDaConferencia
    {
        $motor = $this->motor->conferir($pdf, $paraAssinar, OficinaDePdfAssinado::CPF);
        self::assertSame(ConferenciaDoPrefixo::Confere, $motor->prefixo, 'o PDF devolvido começa pelo para_assinar');

        return $motor;
    }

    /** A assinatura do signatário: o acréscimo com o campo 6 e o /Sig 7 sobre o `para_assinar`. */
    private static function novaAssinatura(string $paraAssinar, string $antesDoIndice = ''): string
    {
        return OficinaDePdfAssinado::assinar(
            OficinaDePdfAssinado::acrescimoParaAssinar($paraAssinar, antesDoIndice: $antesDoIndice),
            OficinaDePdfAssinado::cmsDoOpenssl(OficinaDePdfAssinado::chave('ec')),
        );
    }
}
