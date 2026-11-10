<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\DTO\PdfParaEntregar;
use App\AssinaturaEletronica\Service\EntregaDoPdfDaAssinatura;
use App\Shared\Armazenamento\MaterializadorDeArquivo;
use App\Shared\Http\EntregaDeArquivo;
use App\Tests\Shared\Doubles\LoggerEmMemoria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * O PDF gerado na hora, em fluxo: a falha no primeiro pedaço vira erro antes da resposta; a falha num pedaço seguinte
 * (com o 200 já enviado) vai para o log e a escrita para sem o `%%EOF`, que a tela confere antes de salvar. Nos três
 * casos a vaga de geração é liberada.
 */
#[CoversClass(EntregaDoPdfDaAssinatura::class)]
#[CoversClass(PdfParaEntregar::class)]
final class EntregaDoPdfDaAssinaturaTest extends TestCase
{
    private LoggerEmMemoria $logger;
    private LockFactory $travas;
    private LockInterface $vaga;

    protected function setUp(): void
    {
        $this->logger = new LoggerEmMemoria();
        $this->travas = new LockFactory(new InMemoryStore());
        $this->vaga = $this->travas->createLock('assinatura_pdf_vaga_0');
        $this->vaga->acquire(false);
    }

    #[TestDox('PDF inteiro: sai em pedaços como download, termina em %%EOF e libera a vaga')]
    public function testInteiro(): void
    {
        $resposta = $this->entrega()->resposta(PdfParaEntregar::naHora(self::pedacos(['%PDF-1.7 ', 'pagina ', "%%EOF\n"]), 'assinado_x.pdf', $this->vaga));

        self::assertTrue($this->vaga->isAcquired(), 'a vaga segue ocupada até o último pedaço sair');
        self::assertSame("%PDF-1.7 pagina %%EOF\n", self::enviar($resposta));
        self::assertSame('application/pdf', $resposta->headers->get('Content-Type'));
        self::assertStringStartsWith('attachment;', (string) $resposta->headers->get('Content-Disposition'));
        $this->assertVagaLivre();
        self::assertSame([], $this->logger->doNivel('error'));
    }

    #[TestDox('falha depois do início: log, a escrita para sem o %%EOF e a vaga é liberada')]
    public function testCortadoNoMeio(): void
    {
        $pedacos = (static function (): \Generator {
            yield '%PDF-1.7 ';
            yield 'pagina 1 ';
            throw new \RuntimeException('ghostscript caiu na página 2');
        })();

        $saida = self::enviar($this->entrega()->resposta(PdfParaEntregar::naHora($pedacos, 'assinado_x.pdf', $this->vaga)));

        self::assertSame('%PDF-1.7 pagina 1 ', $saida);
        self::assertStringNotContainsString('%%EOF', $saida);
        $erros = $this->logger->doNivel('error');
        self::assertCount(1, $erros);
        self::assertStringContainsString('PDF com selo cortado', $erros[0]['mensagem']);
        self::assertSame('ghostscript caiu na página 2', $erros[0]['contexto']['erro'] ?? null);
        $this->assertVagaLivre();
    }

    #[TestDox('falha no primeiro pedaço: o erro sobe antes da resposta e a vaga é liberada')]
    public function testFalhaNoPrimeiroPedaco(): void
    {
        $pedacos = (static function (): \Generator {
            throw new \RuntimeException('diagramação falhou');
            yield '';
        })();

        try {
            $this->entrega()->resposta(PdfParaEntregar::naHora($pedacos, 'assinado_x.pdf', $this->vaga));
            self::fail('a falha do primeiro pedaço tinha de subir');
        } catch (\RuntimeException $e) {
            self::assertSame('diagramação falhou', $e->getMessage());
        }
        $this->assertVagaLivre();
    }

    private function entrega(): EntregaDoPdfDaAssinatura
    {
        return new EntregaDoPdfDaAssinatura(new EntregaDeArquivo($this->createStub(MaterializadorDeArquivo::class)), $this->logger);
    }

    private function assertVagaLivre(): void
    {
        self::assertFalse($this->vaga->isAcquired());
        self::assertTrue($this->travas->createLock('assinatura_pdf_vaga_0')->acquire(false), 'outra geração ocupa a vaga');
    }

    /** @param list<string> $partes */
    private static function pedacos(array $partes): \Generator
    {
        yield from $partes;
    }

    private static function enviar(Response $resposta): string
    {
        self::assertInstanceOf(StreamedResponse::class, $resposta);
        ob_start();
        try {
            $resposta->sendContent();
        } finally {
            $saida = (string) ob_get_clean();
        }

        return $saida;
    }
}
