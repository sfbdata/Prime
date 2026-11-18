<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Ferramenta\CatalogoFechadoDoConector;
use App\Conector\Ferramenta\LerDocumentosEModelosFerramenta;
use App\Conector\Ferramenta\TabelaDeAbaEChave;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O contrato de entrada de `ler_documentos_e_modelos` (spec mcp-remoto-oauth 6.4 e 6.5): as cinco ações, a pasta
 * obrigatória em todas, os campos de cada ação e o pedido recusado antes da guarda. As regras que dependem do banco
 * estão no teste de integração.
 */
#[CoversClass(LerDocumentosEModelosFerramenta::class)]
final class LerDocumentosEModelosFerramentaTest extends TestCase
{
    #[TestDox('o esquema traz as cinco ações, exige ação e pasta e fecha os campos')]
    public function testEsquema(): void
    {
        $esquema = $this->ferramenta()->esquemaDeEntrada();

        self::assertSame('ler_documentos_e_modelos', $this->ferramenta()->nome());
        self::assertSame(CatalogoFechadoDoConector::TITULOS['ler_documentos_e_modelos'], $this->ferramenta()->titulo());
        self::assertSame(['listar', 'ler', 'lixeira', 'modelos', 'modelo'], $esquema['properties']['acao']['enum']);
        self::assertSame(['acao', 'pasta_id'], $esquema['required']);
        self::assertFalse($esquema['additionalProperties']);
        self::assertSame('integer', $esquema['properties']['documento_id']['type']);
        self::assertSame('integer', $esquema['properties']['modelo_id']['type']);
    }

    #[TestDox('as ações do esquema são as da tabela de aba e chave (a aba Documentos para listar, ler e lixeira; Detalhes para os modelos)')]
    public function testAcoesNaTabela(): void
    {
        $linhas = TabelaDeAbaEChave::LINHAS['ler_documentos_e_modelos'];

        self::assertEqualsCanonicalizing(array_keys($linhas), LerDocumentosEModelosFerramenta::ACOES);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function pedidosInvalidos(): iterable
    {
        yield 'sem ação' => [['pasta_id' => 1]];
        yield 'ação fora da lista' => [['acao' => 'apagar', 'pasta_id' => 1]];
        yield 'ação que não é texto' => [['acao' => ['listar'], 'pasta_id' => 1]];
        yield 'sem pasta' => [['acao' => 'listar']];
        yield 'pasta zero' => [['acao' => 'listar', 'pasta_id' => 0]];
        yield 'pasta texto' => [['acao' => 'listar', 'pasta_id' => 'abc']];
        yield 'ler sem documento' => [['acao' => 'ler', 'pasta_id' => 1]];
        yield 'ler com documento zero' => [['acao' => 'ler', 'pasta_id' => 1, 'documento_id' => 0]];
        yield 'ler com página' => [['acao' => 'ler', 'pasta_id' => 1, 'documento_id' => 3, 'pagina' => 2]];
        yield 'listar com documento' => [['acao' => 'listar', 'pasta_id' => 1, 'documento_id' => 3]];
        yield 'lixeira com busca' => [['acao' => 'lixeira', 'pasta_id' => 1, 'busca' => 'x']];
        yield 'modelo sem modelo' => [['acao' => 'modelo', 'pasta_id' => 1]];
        yield 'modelo com documento' => [['acao' => 'modelo', 'pasta_id' => 1, 'modelo_id' => 2, 'documento_id' => 3]];
        yield 'modelos com modelo_id' => [['acao' => 'modelos', 'pasta_id' => 1, 'modelo_id' => 2]];
        yield 'modelos com busca que não é texto' => [['acao' => 'modelos', 'pasta_id' => 1, 'busca' => 12]];
        yield 'modelos com busca enorme' => [['acao' => 'modelos', 'pasta_id' => 1, 'busca' => str_repeat('a', 101)]];
        yield 'página zero' => [['acao' => 'listar', 'pasta_id' => 1, 'pagina' => 0]];
        yield 'página acima do teto' => [['acao' => 'listar', 'pasta_id' => 1, 'pagina' => 10_001]];
        yield 'campo fora do esquema' => [['acao' => 'listar', 'pasta_id' => 1, 'caminho' => '/etc/passwd']];
    }

    /** @param array<string, mixed> $argumentos */
    #[DataProvider('pedidosInvalidos')]
    #[TestDox('pedido inválido antes da guarda: $_dataName')]
    public function testPedidoInvalido(array $argumentos): void
    {
        try {
            $this->ferramenta()->executar($argumentos, $this->chamada());
            self::fail('deveria recusar o pedido');
        } catch (ErroDaFerramenta $e) {
            self::assertSame(ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA, $e->motivo);
        }
    }

    #[TestDox('as mensagens fixas de tipo, áudio e vídeo e limite não trazem travessão nem dado do banco')]
    public function testMensagensFixas(): void
    {
        foreach ([
            LerDocumentosEModelosFerramenta::TIPO_AINDA_NAO_LIDO,
            LerDocumentosEModelosFerramenta::AUDIO_E_VIDEO,
            LerDocumentosEModelosFerramenta::ILEGIVEL,
            LerDocumentosEModelosFerramenta::LIMITE,
            LerDocumentosEModelosFerramenta::INDISPONIVEL,
        ] as $mensagem) {
            self::assertStringNotContainsString("\u{2014}", $mensagem);
            self::assertStringNotContainsString("\u{2013}", $mensagem);
            self::assertStringNotContainsString('%', $mensagem);
        }
        self::assertStringContainsString('ainda não', LerDocumentosEModelosFerramenta::TIPO_AINDA_NAO_LIDO);
    }

    private function ferramenta(): LerDocumentosEModelosFerramenta
    {
        return (new \ReflectionClass(LerDocumentosEModelosFerramenta::class))->newInstanceWithoutConstructor();
    }

    private function chamada(): ChamadaDoConector
    {
        return (new \ReflectionClass(ChamadaDoConector::class))->newInstanceWithoutConstructor();
    }
}
