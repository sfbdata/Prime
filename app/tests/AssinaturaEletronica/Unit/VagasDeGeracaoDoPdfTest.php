<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\Exception\MuitosPdfsSendoGerados;
use App\AssinaturaEletronica\Exception\PdfDoDocumentoSendoGerado;
use App\AssinaturaEletronica\Service\VagasDeGeracaoDoPdf;
use App\Tests\AssinaturaEletronica\Support\TravasContadas;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * As vagas de geração do PDF com selo na web: no máximo duas ao mesmo tempo (o FPM tem 5 processos e cada página leva
 * cerca de 1,2 s). A terceira não espera: {@see MuitosPdfsSendoGerados}, que vira 429. Uma trava por documento, sem
 * espera, e a renovação das travas a cada pedaço gerado.
 */
#[CoversClass(VagasDeGeracaoDoPdf::class)]
#[CoversClass(MuitosPdfsSendoGerados::class)]
#[CoversClass(PdfDoDocumentoSendoGerado::class)]
final class VagasDeGeracaoDoPdfTest extends TestCase
{
    #[TestDox('duas gerações ao mesmo tempo; a terceira recebe "Muitos PDFs sendo gerados" sem esperar')]
    public function testDuasVagas(): void
    {
        $vagas = new VagasDeGeracaoDoPdf(new LockFactory(new InMemoryStore()));

        $primeira = $vagas->ocupar();
        $segunda = $vagas->ocupar();

        self::assertSame(2, VagasDeGeracaoDoPdf::VAGAS);
        self::assertTrue($primeira->isAcquired() && $segunda->isAcquired());
        $this->expectException(MuitosPdfsSendoGerados::class);
        $this->expectExceptionMessage('assinatura_eletronica_tela.js.pdf.muitos');
        $vagas->ocupar();
    }

    #[TestDox('a trava que ninguém guarda se solta sozinha: a vaga não fica presa se a geração morrer no caminho')]
    public function testTravaSemDonoSeSolta(): void
    {
        $vagas = new VagasDeGeracaoDoPdf(new LockFactory(new InMemoryStore()));
        $vagas->ocupar();
        $vagas->ocupar();

        self::assertTrue($vagas->ocupar()->isAcquired());
    }

    #[TestDox('a vaga liberada volta a servir, qualquer uma das duas')]
    public function testVagaLiberada(): void
    {
        $vagas = new VagasDeGeracaoDoPdf(new LockFactory(new InMemoryStore()));
        $primeira = $vagas->ocupar();
        $segunda = $vagas->ocupar();

        $primeira->release();
        $terceira = $vagas->ocupar();
        $segunda->release();
        $quarta = $vagas->ocupar();

        self::assertTrue($terceira->isAcquired() && $quarta->isAcquired());
        self::assertFalse($primeira->isAcquired() || $segunda->isAcquired());
    }

    #[TestDox('dois processos (duas fábricas) dividem as mesmas duas vagas pelo armazenamento da trava')]
    public function testVagasDivididasEntreProcessos(): void
    {
        $armazenamento = new InMemoryStore();
        $umProcesso = new VagasDeGeracaoDoPdf(new LockFactory($armazenamento));
        $outroProcesso = new VagasDeGeracaoDoPdf(new LockFactory($armazenamento));

        $deUm = $umProcesso->ocupar();
        $doOutro = $outroProcesso->ocupar();

        self::assertTrue($deUm->isAcquired() && $doOutro->isAcquired());
        $this->expectException(MuitosPdfsSendoGerados::class);
        $umProcesso->ocupar();
    }

    #[TestDox('trava do documento: o segundo pedido do mesmo documento recebe "O PDF deste documento está sendo gerado"; outro documento, não')]
    public function testTravaDoDocumento(): void
    {
        $vagas = new VagasDeGeracaoDoPdf(new LockFactory(new InMemoryStore()));
        $doSete = $vagas->travarDocumento(7);
        $doOito = $vagas->travarDocumento(8);

        self::assertTrue($doSete->isAcquired() && $doOito->isAcquired());
        try {
            $vagas->travarDocumento(7);
            self::fail('o mesmo documento tinha de estar travado');
        } catch (PdfDoDocumentoSendoGerado $e) {
            self::assertSame('assinatura_eletronica_tela.js.pdf.documento_gerando', $e->chave());
        }
        $doSete->release();
        self::assertTrue($vagas->travarDocumento(7)->isAcquired(), 'liberada, outro pedido gera');
        self::assertTrue($vagas->ocupar()->isAcquired(), 'a trava do documento não ocupa vaga');
    }

    #[TestDox('renovar: mais um TTL para cada trava; a que não renova (perdida para outro processo) não para a geração')]
    public function testRenovar(): void
    {
        $loja = new TravasContadas();
        $vagas = new VagasDeGeracaoDoPdf(new LockFactory($loja));
        $vaga = $vagas->ocupar();
        $doDocumento = $vagas->travarDocumento(7);

        VagasDeGeracaoDoPdf::renovar($vaga, $doDocumento);
        VagasDeGeracaoDoPdf::renovar($vaga, null);

        self::assertSame(3, $loja->renovacoesDe('assinatura_pdf_vaga_0'), 'o acquire e as duas renovações');
        self::assertSame(2, $loja->renovacoesDe('assinatura_pdf_documento_7'));
        $loja->falhaAoRenovar = 'assinatura_pdf_documento_7';
        VagasDeGeracaoDoPdf::renovar($doDocumento, $vaga);
        self::assertSame(3, $loja->renovacoesDe('assinatura_pdf_documento_7'), 'tentou e falhou, sem lançar');
        self::assertSame(4, $loja->renovacoesDe('assinatura_pdf_vaga_0'), 'a seguinte renova mesmo assim');
    }
}
