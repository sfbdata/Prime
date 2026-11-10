<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Message\GerarPdfFinalDoDocumento;
use App\AssinaturaEletronica\MessageHandler\GerarPdfFinalDoDocumentoHandler;
use App\AssinaturaEletronica\Service\PdfFinalDoDocumento;
use App\AssinaturaEletronica\Service\Selo\CadeiaDoSeloPendente;
use App\AssinaturaEletronica\Service\Selo\ClasseDaFalhaNoSelo;
use App\AssinaturaEletronica\Service\Selo\FalhaNoSelo;
use App\Entity\Tenant\Tenant;
use App\Repository\TenantRepository;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Tests\AssinaturaEletronica\Support\OficinaDosUseCasesDaAssinatura;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * O worker do PDF final (contrato da etapa 3): procura o documento no escritório da mensagem, mesmo na Lixeira, e só
 * garante o PDF do concluído. Escritório ou documento que sumiu, ou que não está concluído: nada a fazer.
 *
 * O selo (etapa 8, E8-4): a 4ª tentativa é a última; antes dela, a falha do selo que tenta de novo volta à fila com a
 * tentativa seguinte e a espera de 1, 5 ou 15 min, com a cadeia do selo marcada, e o handler termina sem erro. Os
 * envelopes despachados ficam em `$envelopes`; a cadeia é a de verdade, sobre uma loja de travas em memória.
 */
#[CoversClass(GerarPdfFinalDoDocumentoHandler::class)]
#[CoversClass(GerarPdfFinalDoDocumento::class)]
#[CoversClass(CadeiaDoSeloPendente::class)]
final class GerarPdfFinalDoDocumentoHandlerTest extends TestCase
{
    use OficinaDosUseCasesDaAssinatura;

    private TenantRepository&MockObject $tenants;
    private PdfFinalDoDocumento&MockObject $pdfFinal;

    /** @var list<Envelope> */
    private array $envelopes = [];

    private CadeiaDoSeloPendente $cadeiaDoSelo;

    /** A falha do barramento dublado, quando a fila está fora do ar. */
    private ?\Throwable $filaCaida = null;

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
        $this->cadeiaDoSelo = new CadeiaDoSeloPendente(new LockFactory(new InMemoryStore()));
    }

    #[TestDox('documento concluído do escritório da mensagem: garante o PDF final, sem ser a última tentativa do selo')]
    public function testConcluido(): void
    {
        $documento = $this->concluido();
        $this->pdfFinal->expects(self::once())->method('garantir')->with(self::identicalTo($documento), null, false)->willReturn(self::chave());

        $this->handler()(new GerarPdfFinalDoDocumento(1, 9));

        self::assertSame([], $this->envelopes);
        self::assertFalse($this->cadeiaDoSelo->estaPendente(1), 'sem falha do selo, nada marcado');
    }

    /** @return iterable<string, array{int, int}> a tentativa que falha e a espera até a seguinte, em milissegundos */
    public static function tentativasQueVoltamAFila(): iterable
    {
        yield '1ª tentativa: a 2ª em 1 min' => [1, 60_000];
        yield '2ª tentativa: a 3ª em 5 min' => [2, 300_000];
        yield '3ª tentativa: a 4ª em 15 min' => [3, 900_000];
    }

    #[DataProvider('tentativasQueVoltamAFila')]
    #[TestDox('falha do selo na $_dataName, com a cadeia do selo marcada, e o handler termina sem erro')]
    public function testFalhaNoSeloVoltaAFila(int $tentativa, int $espera): void
    {
        $documento = $this->concluido();
        $this->pdfFinal->expects(self::once())->method('garantir')->with(self::identicalTo($documento), null, false)
            ->willThrowException(new FalhaNoSelo('ocupado', ClasseDaFalhaNoSelo::Passageira, 503));

        $this->handler()(new GerarPdfFinalDoDocumento(1, 9, $tentativa));

        self::assertCount(1, $this->envelopes);
        self::assertEquals(new GerarPdfFinalDoDocumento(1, 9, $tentativa + 1), $this->envelopes[0]->getMessage());
        $atrasos = $this->envelopes[0]->all(DelayStamp::class);
        self::assertCount(1, $atrasos);
        self::assertInstanceOf(DelayStamp::class, $atrasos[0]);
        self::assertSame($espera, $atrasos[0]->getDelay());
        self::assertTrue($this->cadeiaDoSelo->estaPendente(1), 'a web não gera de novo enquanto o worker tenta');
        self::assertFalse($this->cadeiaDoSelo->estaPendente(2));
    }

    #[TestDox('cadeia já marcada (pela web, ou numa tentativa anterior): a mensagem volta à fila do mesmo jeito')]
    public function testCadeiaJaMarcadaVoltaAFila(): void
    {
        $this->concluido();
        self::assertNotNull($this->cadeiaDoSelo->marcar(1));
        $this->pdfFinal->method('garantir')->willThrowException(new FalhaNoSelo('ocupado', ClasseDaFalhaNoSelo::Passageira, 503));

        $this->handler()(new GerarPdfFinalDoDocumento(1, 9, 2));

        self::assertCount(1, $this->envelopes);
        self::assertEquals(new GerarPdfFinalDoDocumento(1, 9, 3), $this->envelopes[0]->getMessage());
        self::assertTrue($this->cadeiaDoSelo->estaPendente(1));
    }

    #[TestDox('fila fora do ar ao voltar à fila: a falha sobe para o retry do Messenger (que entrega esta mesma mensagem de novo) e a marca fica')]
    public function testFilaCaidaAoVoltarAFila(): void
    {
        $this->concluido();
        $this->pdfFinal->method('garantir')->willThrowException(new FalhaNoSelo('ocupado', ClasseDaFalhaNoSelo::Passageira, 503));
        $this->filaCaida = new \RuntimeException('fila fora do ar');

        try {
            $this->handler()(new GerarPdfFinalDoDocumento(1, 9, 2));
            self::fail('a falha da fila deveria subir');
        } catch (\RuntimeException $e) {
            self::assertSame($this->filaCaida, $e);
        }

        self::assertSame([], $this->envelopes);
        self::assertTrue($this->cadeiaDoSelo->estaPendente(1), 'a cadeia continua no worker, pelo retry da mesma mensagem');
    }

    #[TestDox('4ª tentativa: garante como a última (sem o selo, se falhar) e nada volta à fila')]
    public function testQuartaTentativaEAUltima(): void
    {
        $documento = $this->concluido();
        $this->pdfFinal->expects(self::once())->method('garantir')->with(self::identicalTo($documento), null, true)->willReturn(self::chave());

        $this->handler()(new GerarPdfFinalDoDocumento(1, 9, 4));

        self::assertSame([], $this->envelopes);
    }

    #[TestDox('falha do selo na 4ª tentativa (não devia acontecer): sobe para o Messenger, sem voltar à fila')]
    public function testFalhaNoSeloNaUltimaSobe(): void
    {
        $this->concluido();
        $falha = new FalhaNoSelo('ocupado', ClasseDaFalhaNoSelo::Passageira, 503);
        $this->pdfFinal->method('garantir')->willThrowException($falha);

        try {
            $this->handler()(new GerarPdfFinalDoDocumento(1, 9, 4));
            self::fail('a falha deveria subir');
        } catch (FalhaNoSelo $e) {
            self::assertSame($falha, $e);
        }

        self::assertSame([], $this->envelopes);
        self::assertFalse($this->cadeiaDoSelo->estaPendente(1));
    }

    #[TestDox('outra falha da geração sobe para o retry do Messenger, sem voltar à fila pelo handler')]
    public function testOutraFalhaSobe(): void
    {
        $this->concluido();
        $falha = new \RuntimeException('o Ghostscript caiu');
        $this->pdfFinal->method('garantir')->willThrowException($falha);

        try {
            $this->handler()(new GerarPdfFinalDoDocumento(1, 9, 2));
            self::fail('a falha deveria subir');
        } catch (\RuntimeException $e) {
            self::assertSame($falha, $e);
        }

        self::assertSame([], $this->envelopes);
        self::assertFalse($this->cadeiaDoSelo->estaPendente(1));
    }

    #[TestDox('quem despacha hoje não muda: a mensagem começa na 1ª tentativa')]
    public function testMensagemComecaNaPrimeiraTentativa(): void
    {
        self::assertSame(1, (new GerarPdfFinalDoDocumento(1, 9))->tentativa);
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
        $guardar = function (Envelope $envelope): void {
            if ($this->filaCaida !== null) {
                throw $this->filaCaida;
            }
            $this->envelopes[] = $envelope;
        };
        $mensagens = new class ($guardar) implements MessageBusInterface {
            public function __construct(private readonly \Closure $guardar)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $envelope = Envelope::wrap($message, $stamps);
                ($this->guardar)($envelope);

                return $envelope;
            }
        };

        return new GerarPdfFinalDoDocumentoHandler($this->tenants, $this->documentos, $this->pdfFinal, $mensagens, $this->cadeiaDoSelo);
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
