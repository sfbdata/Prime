<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\Selo;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\EventoDaTrilha;
use App\AssinaturaEletronica\Repository\EventoDaTrilhaRepository;
use App\AssinaturaEletronica\Service\ChaveDaAssinatura;
use App\AssinaturaEletronica\Service\PdfDaAssinatura;
use App\AssinaturaEletronica\Service\PdfNoTemporario;
use App\AssinaturaEletronica\Service\Selo\ClasseDaFalhaNoSelo;
use App\AssinaturaEletronica\Service\Selo\FalhaNoSelo;
use App\AssinaturaEletronica\Service\Selo\HttpSeladorDePdf;
use App\AssinaturaEletronica\Service\Selo\ResultadoDoSelo;
use App\AssinaturaEletronica\Service\Selo\SeladorDePdf;
use App\AssinaturaEletronica\Service\Selo\SeladorDePdfNaoConfigurado;
use App\AssinaturaEletronica\Service\Selo\SeloDoPdfFinal;
use App\AssinaturaEletronica\Service\TrilhaDoDocumento;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\AreaTemporariaPrivada;
use App\Shared\Armazenamento\Exception\FalhaNoTemporario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * O selo no PDF final (spec assinatura-documentos-etapa-8, E8-4), em três partes: {@see SeloDoPdfFinal::aplicar()}
 * sela o PDF no temporário (fora da transação, sem trilha nem o log do "guardado sem o selo"),
 * {@see SeloDoPdfFinal::registrar()} leva o resultado à trilha e {@see SeloDoPdfFinal::avisar()} faz o log. Selado, não
 * configurado, a falha definitiva que fica sem o selo na hora (o PDF acima do teto do motor entre elas, sem ler o
 * arquivo) e as que tentam de novo até a última tentativa. A trilha é a de verdade, sobre o repositório dublado; os
 * eventos gravados ficam em `$gravados`.
 */
#[CoversClass(SeloDoPdfFinal::class)]
#[CoversClass(ResultadoDoSelo::class)]
#[CoversClass(PdfNoTemporario::class)]
final class SeloDoPdfFinalTest extends TestCase
{
    private const PDF = "%PDF-1.7\n(o PDF final da etapa 3)\n%%EOF\n";
    private const SELADO = self::PDF . "\n(o selo)\n%%EOF\n";

    private LogDoSelo $log;
    /** @var list<EventoDaTrilha> */
    private array $gravados = [];
    private DocumentoParaAssinar $documento;
    private AreaTemporariaPrivada $area;

    protected function setUp(): void
    {
        $this->log = new LogDoSelo();
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, 9);
        $this->documento = new DocumentoParaAssinar($tenant, null, new \DateTimeImmutable('2026-11-10 08:00:00'));
        (new \ReflectionProperty(DocumentoParaAssinar::class, 'id'))->setValue($this->documento, 31);
        $this->area = AreaTemporariaPrivada::criar('testeselo');
    }

    protected function tearDown(): void
    {
        $this->area->liberar();
    }

    #[TestDox('o validador responde "não configurado": o PDF fica como veio, e o registro não grava nada')]
    public function testNaoConfiguradoNoValidador(): void
    {
        $caminho = $this->pdfNoTemporario();
        $selo = $this->selo(static fn (): ?string => null);

        $resultado = $selo->aplicar($this->documento, $this->area, $caminho, false);
        $this->registrarEAvisar($selo, $resultado);

        self::assertFalse($resultado->selou);
        self::assertNull($resultado->falha);
        self::assertSame(self::PDF, file_get_contents($caminho));
        self::assertSame([], $this->gravados);
        self::assertSame([], $this->log->registros);
    }

    #[TestDox('selador não configurado no PHP: nem mede nem lê o PDF (o maior que o teto também não vira falha)')]
    public function testSeladorNaoConfiguradoNemLeOPdf(): void
    {
        $selo = new SeloDoPdfFinal(new SeladorDePdfNaoConfigurado(), $this->trilha(), $this->log);

        $resultado = $selo->aplicar($this->documento, $this->area, $this->area->caminho() . '/nao-existe.pdf', true);
        $this->registrarEAvisar($selo, $resultado);

        self::assertFalse($resultado->selou);
        self::assertNull($resultado->falha);
        self::assertSame([], $this->gravados);
        self::assertSame([], $this->log->registros);
    }

    #[TestDox('selado: regrava o selado por cima do mesmo caminho, sem trilha; o registro grava "PDF final selado", sem usuário')]
    public function testSelado(): void
    {
        $caminho = $this->pdfNoTemporario();
        $recebido = null;
        $selo = $this->selo(static function (string $pdf) use (&$recebido): string {
            $recebido = $pdf;

            return self::SELADO;
        });

        $resultado = $selo->aplicar($this->documento, $this->area, $caminho, false);

        self::assertSame(self::PDF, $recebido);
        self::assertTrue($resultado->selou);
        self::assertNull($resultado->falha);
        self::assertSame(self::SELADO, file_get_contents($caminho));
        self::assertSame([], $this->gravados, 'aplicar não grava trilha: ela é do registro, na transação de quem guarda');

        $this->registrarEAvisar($selo, $resultado);

        self::assertSame(['pdf_selado'], $this->tipos());
        self::assertSame(['chave' => ChaveDaAssinatura::VERSAO], $this->gravados[0]->getDados());
        self::assertNull($this->gravados[0]->getUsuario());
        self::assertSame($this->documento, $this->gravados[0]->getDocumento());
        self::assertSame([], $this->log->registros);
    }

    /** @return iterable<string, array{bool}> */
    public static function tentativas(): iterable
    {
        yield 'primeira tentativa' => [false];
        yield 'última tentativa' => [true];
    }

    #[DataProvider('tentativas')]
    #[TestDox('falha definitiva na $_dataName: o PDF fica como veio e a falha volta; o registro grava "sem o selo" com o código e faz logger->error')]
    public function testDefinitivaFicaSemOSeloNaHora(bool $ultima): void
    {
        $caminho = $this->pdfNoTemporario();
        $falha = new FalhaNoSelo('nao_selavel', ClasseDaFalhaNoSelo::Definitiva, 422);
        $selo = $this->falhando($falha);

        $resultado = $selo->aplicar($this->documento, $this->area, $caminho, $ultima);

        self::assertFalse($resultado->selou);
        self::assertSame($falha, $resultado->falha);
        self::assertSame(self::PDF, file_get_contents($caminho));
        self::assertSame([], $this->gravados);
        self::assertSame([], $this->log->registros, 'o log do "guardado sem o selo" é do registro');

        $this->registrarEAvisar($selo, $resultado);

        $this->assertGuardadoSemOSelo('nao_selavel');
    }

    #[TestDox('o registro só grava a trilha e o aviso só faz o log (o aviso sai depois do COMMIT de quem guarda)')]
    public function testRegistroSemLogEAvisoSemTrilha(): void
    {
        $selo = $this->falhando(new FalhaNoSelo('nao_selavel', ClasseDaFalhaNoSelo::Definitiva, 422));
        $resultado = $selo->aplicar($this->documento, $this->area, $this->pdfNoTemporario(), false);

        $selo->registrar($this->documento, $resultado);
        self::assertSame(['selo_nao_aplicado'], $this->tipos());
        self::assertSame([], $this->log->registros, 'o registro não loga');

        $selo->avisar($this->documento, $resultado);
        self::assertCount(1, $this->gravados, 'o aviso não grava trilha');
        self::assertCount(1, $this->log->erros());

        $selo->avisar($this->documento, ResultadoDoSelo::selado());
        $selo->avisar($this->documento, ResultadoDoSelo::naoConfigurado());
        self::assertCount(1, $this->log->erros(), 'selado e não configurado não avisam');
    }

    /** @return iterable<string, array{\Closure(AreaTemporariaPrivada, AreaTemporariaPrivada): string}> */
    public static function caminhosForaDaArea(): iterable
    {
        yield 'arquivo de outra área' => [static fn (AreaTemporariaPrivada $area, AreaTemporariaPrivada $outra): string => $outra->gravar(self::PDF, 'pdf')];
        yield 'subida de diretório' => [static fn (AreaTemporariaPrivada $area): string => $area->caminho() . '/..'];
        yield 'subdiretório da área' => [static function (AreaTemporariaPrivada $area): string {
            mkdir($area->caminho() . '/sub');

            return $area->caminho() . '/sub/x.pdf';
        }];
        yield 'link na área' => [static function (AreaTemporariaPrivada $area, AreaTemporariaPrivada $outra): string {
            symlink($outra->gravar(self::PDF, 'pdf'), $area->caminho() . '/link.pdf');

            return $area->caminho() . '/link.pdf';
        }];
    }

    /** @param \Closure(AreaTemporariaPrivada, AreaTemporariaPrivada): string $caminho */
    #[DataProvider('caminhosForaDaArea')]
    #[TestDox('$_dataName: FalhaNoTemporario, sem ler nem pedir o selo')]
    public function testCaminhoForaDaArea(\Closure $caminho): void
    {
        $outra = AreaTemporariaPrivada::criar('testeselo');
        try {
            $selo = $this->selo(static fn (): never => self::fail('fora da área, nenhum pedido sai'));

            $this->expectException(FalhaNoTemporario::class);
            $selo->aplicar($this->documento, $this->area, $caminho($this->area, $outra), false);
        } finally {
            $outra->liberar();
        }
    }

    #[TestDox('PDF acima do teto do motor (19 MiB): falha definitiva pdf_grande_demais pelo tamanho, sem ler o arquivo nem pedir')]
    public function testAcimaDoTetoSemLerOArquivo(): void
    {
        // O teto do selo é o do motor, abaixo dos 20 MiB do validador: o selado (de 10 a 20 KB a mais) passa no Validar.
        self::assertLessThan(HttpSeladorDePdf::PDF_MAXIMO_EM_BYTES - 20 * 1024, PdfDaAssinatura::TETO_PARA_O_MOTOR);
        $caminho = $this->pdfEsparso(PdfDaAssinatura::TETO_PARA_O_MOTOR + 1);
        $selo = $this->selo(static fn (): never => self::fail('acima do teto, nenhum pedido sai'));

        memory_reset_peak_usage();
        $antes = memory_get_usage();
        $resultado = $selo->aplicar($this->documento, $this->area, $caminho, false);
        $pico = memory_get_peak_usage() - $antes;

        self::assertLessThan(1024 * 1024, $pico, 'os 19 MiB não passaram pela memória');
        self::assertSame(HttpSeladorDePdf::PDF_GRANDE_DEMAIS, $resultado->falha?->codigo);
        self::assertSame(ClasseDaFalhaNoSelo::Definitiva, $resultado->falha->classe);
        self::assertSame(PdfDaAssinatura::TETO_PARA_O_MOTOR + 1, filesize($caminho));

        $this->registrarEAvisar($selo, $resultado);

        $this->assertGuardadoSemOSelo(HttpSeladorDePdf::PDF_GRANDE_DEMAIS);
    }

    #[TestDox('PDF com o tamanho exato do teto do motor: é lido inteiro e vai ao validador')]
    public function testNoTetoVaiAoValidador(): void
    {
        $caminho = $this->pdfEsparso(PdfDaAssinatura::TETO_PARA_O_MOTOR);
        $enviados = null;
        $selo = $this->selo(static function (string $pdf) use (&$enviados): ?string {
            $enviados = \strlen($pdf);

            return null;
        });

        $resultado = $selo->aplicar($this->documento, $this->area, $caminho, false);

        self::assertSame(PdfDaAssinatura::TETO_PARA_O_MOTOR, $enviados);
        self::assertNull($resultado->falha);
    }

    /** @return iterable<string, array{FalhaNoSelo, int}> a falha e quantos `logger->error` antes da última tentativa */
    public static function falhasQueTentamDeNovo(): iterable
    {
        yield 'passageira (503 ocupado)' => [new FalhaNoSelo('ocupado', ClasseDaFalhaNoSelo::Passageira, 503), 0];
        yield 'passageira (erro de transporte)' => [new FalhaNoSelo('falha_de_transporte', ClasseDaFalhaNoSelo::Passageira), 0];
        yield 'configuração (401)' => [new FalhaNoSelo('nao_autorizado', ClasseDaFalhaNoSelo::Configuracao, 401), 1];
        yield 'selo_certificado_invalido' => [new FalhaNoSelo('selo_certificado_invalido', ClasseDaFalhaNoSelo::CertificadoInvalido, 503), 0];
    }

    #[DataProvider('falhasQueTentamDeNovo')]
    #[TestDox('$_dataName fora da última tentativa: relança para tentar de novo, com o PDF como veio e sem gravar nada')]
    public function testTentaDeNovo(FalhaNoSelo $falha, int $errosNoLog): void
    {
        $caminho = $this->pdfNoTemporario();
        try {
            $this->falhando($falha)->aplicar($this->documento, $this->area, $caminho, false);
            self::fail('Era para relançar a falha.');
        } catch (FalhaNoSelo $relancada) {
            self::assertSame($falha, $relancada);
        }

        self::assertSame(self::PDF, file_get_contents($caminho));
        self::assertSame([], $this->gravados);
        self::assertCount($errosNoLog, $this->log->erros(), 'só a de configuração avisa a cada tentativa');
    }

    /** @return iterable<string, array{FalhaNoSelo}> */
    public static function falhasNaUltimaTentativa(): iterable
    {
        foreach (self::falhasQueTentamDeNovo() as $nome => [$falha]) {
            yield $nome => [$falha];
        }
    }

    #[DataProvider('falhasNaUltimaTentativa')]
    #[TestDox('$_dataName na última tentativa: cai como a definitiva, com um erro só no log')]
    public function testUltimaTentativaFicaSemOSelo(FalhaNoSelo $falha): void
    {
        $caminho = $this->pdfNoTemporario();
        $selo = $this->falhando($falha);

        $resultado = $selo->aplicar($this->documento, $this->area, $caminho, true);
        $this->registrarEAvisar($selo, $resultado);

        self::assertSame($falha, $resultado->falha);
        self::assertSame(self::PDF, file_get_contents($caminho));
        $this->assertGuardadoSemOSelo($falha->codigo);
    }

    #[TestDox('configuração: o log de cada tentativa diz o código e o documento, sem o PDF')]
    public function testConfiguracaoAvisaACadaTentativa(): void
    {
        $caminho = $this->pdfNoTemporario();
        $selo = $this->falhando(new FalhaNoSelo('http_431', ClasseDaFalhaNoSelo::Configuracao, 431));
        for ($tentativa = 0; $tentativa < 3; ++$tentativa) {
            try {
                $selo->aplicar($this->documento, $this->area, $caminho, false);
            } catch (FalhaNoSelo) {
            }
        }

        self::assertCount(3, $this->log->erros());
        self::assertSame(['documento' => 31, 'codigo' => 'http_431', 'situacao' => 431], $this->log->registros[2]['contexto']);
        self::assertStringNotContainsString('PDF final da etapa 3', $this->log->tudo());
    }

    private function registrarEAvisar(SeloDoPdfFinal $selo, ResultadoDoSelo $resultado): void
    {
        $selo->registrar($this->documento, $resultado);
        $selo->avisar($this->documento, $resultado);
    }

    private function assertGuardadoSemOSelo(string $codigo): void
    {
        self::assertSame(['selo_nao_aplicado'], $this->tipos());
        self::assertSame(['motivo' => $codigo, 'chave' => ChaveDaAssinatura::VERSAO], $this->gravados[0]->getDados());
        self::assertNull($this->gravados[0]->getUsuario());
        self::assertCount(1, $this->log->erros());
        self::assertSame(31, $this->log->registros[0]['contexto']['documento'] ?? null);
        self::assertSame($codigo, $this->log->registros[0]['contexto']['codigo'] ?? null);
        self::assertStringNotContainsString('PDF final da etapa 3', $this->log->tudo());
    }

    private function pdfNoTemporario(): string
    {
        return $this->area->gravar(self::PDF, 'pdf');
    }

    /** Um arquivo esparso do tamanho pedido: não ocupa o disco nem passa pela memória para ser criado. */
    private function pdfEsparso(int $bytes): string
    {
        $caminho = $this->area->caminho() . '/esparso.pdf';
        $arquivo = fopen($caminho, 'xb');
        self::assertNotFalse($arquivo);
        self::assertTrue(ftruncate($arquivo, $bytes));
        fclose($arquivo);

        return $caminho;
    }

    private function falhando(FalhaNoSelo $falha): SeloDoPdfFinal
    {
        return $this->selo(static fn (): never => throw $falha);
    }

    /** @param \Closure(string): ?string $selar */
    private function selo(\Closure $selar): SeloDoPdfFinal
    {
        $selador = new class ($selar) implements SeladorDePdf {
            public function __construct(private readonly \Closure $selar)
            {
            }

            public function selar(string $pdf): ?string
            {
                return ($this->selar)($pdf);
            }
        };

        return new SeloDoPdfFinal($selador, $this->trilha(), $this->log);
    }

    private function trilha(): TrilhaDoDocumento
    {
        $eventos = $this->createMock(EventoDaTrilhaRepository::class);
        $eventos->method('ultimoHashTravando')->willReturn(null);
        $eventos->method('salvar')->willReturnCallback(function (EventoDaTrilha $evento): void {
            $this->gravados[] = $evento;
        });

        return new TrilhaDoDocumento($eventos, new MockClock('2026-11-10 09:00:00'));
    }

    /** @return list<string> */
    private function tipos(): array
    {
        return array_map(static fn (EventoDaTrilha $e): string => $e->getTipo()->value, $this->gravados);
    }
}
