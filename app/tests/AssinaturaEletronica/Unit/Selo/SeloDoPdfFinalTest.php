<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\Selo;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\EventoDaTrilha;
use App\AssinaturaEletronica\Repository\EventoDaTrilhaRepository;
use App\AssinaturaEletronica\Service\ChaveDaAssinatura;
use App\AssinaturaEletronica\Service\Selo\ClasseDaFalhaNoSelo;
use App\AssinaturaEletronica\Service\Selo\FalhaNoSelo;
use App\AssinaturaEletronica\Service\Selo\SeladorDePdf;
use App\AssinaturaEletronica\Service\Selo\SeloDoPdfFinal;
use App\AssinaturaEletronica\Service\TrilhaDoDocumento;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * O selo no PDF final (spec assinatura-documentos-etapa-8, E8-4): selado, não configurado, a falha definitiva que
 * guarda sem o selo na hora e as que tentam de novo até a última tentativa. A trilha é a de verdade, sobre o
 * repositório dublado; os eventos gravados ficam em `$gravados`.
 */
#[CoversClass(SeloDoPdfFinal::class)]
final class SeloDoPdfFinalTest extends TestCase
{
    private const PDF = "%PDF-1.7\n(o PDF final da etapa 3)\n%%EOF\n";
    private const SELADO = self::PDF . "\n(o selo)\n%%EOF\n";

    private LogDoSelo $log;
    /** @var list<EventoDaTrilha> */
    private array $gravados = [];
    private DocumentoParaAssinar $documento;

    protected function setUp(): void
    {
        $this->log = new LogDoSelo();
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, 9);
        $this->documento = new DocumentoParaAssinar($tenant, null, new \DateTimeImmutable('2026-11-10 08:00:00'));
        (new \ReflectionProperty(DocumentoParaAssinar::class, 'id'))->setValue($this->documento, 31);
    }

    #[TestDox('selo não configurado: devolve o PDF como veio e não grava nada')]
    public function testNaoConfigurado(): void
    {
        self::assertSame(self::PDF, $this->selo(static fn (): ?string => null)->aplicar($this->documento, self::PDF, false));

        self::assertSame([], $this->gravados);
        self::assertSame([], $this->log->registros);
    }

    #[TestDox('selado: devolve o selado e grava "PDF final selado" na trilha, sem usuário (é o worker)')]
    public function testSelado(): void
    {
        $recebido = null;
        $selo = $this->selo(static function (string $pdf) use (&$recebido): string {
            $recebido = $pdf;

            return self::SELADO;
        });

        self::assertSame(self::SELADO, $selo->aplicar($this->documento, self::PDF, false));

        self::assertSame(self::PDF, $recebido);
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
    #[TestDox('falha definitiva na $_dataName: devolve o PDF como veio, grava "sem o selo" com o código e faz logger->error')]
    public function testDefinitivaGuardaSemOSeloNaHora(bool $ultima): void
    {
        $falha = new FalhaNoSelo('nao_selavel', ClasseDaFalhaNoSelo::Definitiva, 422);

        self::assertSame(self::PDF, $this->falhando($falha)->aplicar($this->documento, self::PDF, $ultima));

        $this->assertGuardadoSemOSelo('nao_selavel');
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
    #[TestDox('$_dataName fora da última tentativa: relança para a mensagem tentar de novo, sem gravar nada')]
    public function testTentaDeNovo(FalhaNoSelo $falha, int $errosNoLog): void
    {
        try {
            $this->falhando($falha)->aplicar($this->documento, self::PDF, false);
            self::fail('Era para relançar a falha.');
        } catch (FalhaNoSelo $relancada) {
            self::assertSame($falha, $relancada);
        }

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
    public function testUltimaTentativaGuardaSemOSelo(FalhaNoSelo $falha): void
    {

        self::assertSame(self::PDF, $this->falhando($falha)->aplicar($this->documento, self::PDF, true));

        $this->assertGuardadoSemOSelo($falha->codigo);
    }

    #[TestDox('configuração: o log de cada tentativa diz o código e o documento, sem o PDF')]
    public function testConfiguracaoAvisaACadaTentativa(): void
    {
        $selo = $this->falhando(new FalhaNoSelo('http_431', ClasseDaFalhaNoSelo::Configuracao, 431));
        for ($tentativa = 0; $tentativa < 3; ++$tentativa) {
            try {
                $selo->aplicar($this->documento, self::PDF, false);
            } catch (FalhaNoSelo) {
            }
        }

        self::assertCount(3, $this->log->erros());
        self::assertSame(['documento' => 31, 'codigo' => 'http_431', 'situacao' => 431], $this->log->registros[2]['contexto']);
        self::assertStringNotContainsString('PDF final da etapa 3', $this->log->tudo());
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
        $eventos = $this->createMock(EventoDaTrilhaRepository::class);
        $eventos->method('ultimoHashTravando')->willReturn(null);
        $eventos->method('salvar')->willReturnCallback(function (EventoDaTrilha $evento): void {
            $this->gravados[] = $evento;
        });

        return new SeloDoPdfFinal($selador, new TrilhaDoDocumento($eventos, new MockClock('2026-11-10 09:00:00')), $this->log);
    }

    /** @return list<string> */
    private function tipos(): array
    {
        return array_map(static fn (EventoDaTrilha $e): string => $e->getTipo()->value, $this->gravados);
    }
}
