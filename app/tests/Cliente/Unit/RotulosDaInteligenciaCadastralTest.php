<?php

declare(strict_types=1);

namespace App\Tests\Cliente\Unit;

use App\Cliente\DTO\AlertaDaInteligenciaCadastral;
use App\Cliente\DTO\DocumentoEncontradoNaPasta;
use App\Cliente\DTO\InteligenciaCadastralOutput;
use App\Cliente\Service\DocumentoDoCliente;
use App\Cliente\Service\NomeNormalizado;
use App\Cliente\Service\RotulosDaInteligenciaCadastral;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Inteligência cadastral (F-A): normalização do nome e do documento e os textos "a confirmar" (D1), que nunca falam em
 * IA (regras 1 e 7 de app/src/Inteligencia/CLAUDE.md) nem levam CPF/CNPJ.
 */
#[CoversClass(RotulosDaInteligenciaCadastral::class)]
#[CoversClass(NomeNormalizado::class)]
#[CoversClass(DocumentoDoCliente::class)]
#[CoversClass(InteligenciaCadastralOutput::class)]
#[CoversClass(AlertaDaInteligenciaCadastral::class)]
#[CoversClass(DocumentoEncontradoNaPasta::class)]
final class RotulosDaInteligenciaCadastralTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function nomes(): iterable
    {
        yield 'acento e caixa' => ['José Antônio da CONCEIÇÃO', 'jose antonio da conceicao'];
        yield 'espaços repetidos e nas pontas' => ["  Maria   das\tDores ", 'maria das dores'];
        yield 'pontuação' => ['Gleisson B. Gabriel', 'gleisson b gabriel'];
        yield 'razão social' => ['ACME Comércio & Serviços Ltda.', 'acme comercio servicos ltda'];
        yield 'vazio' => ['', ''];
    }

    #[DataProvider('nomes')]
    #[TestDox('nome normalizado: sem acento, sem caixa, sem pontuação e com espaços únicos')]
    public function testNomeNormalizado(string $nome, string $esperado): void
    {
        self::assertSame($esperado, NomeNormalizado::de($nome));
    }

    #[TestDox('"José da Silva." e "JOSE  DA SILVA" são o mesmo nome; "José da Silva" e "José Silva" não')]
    public function testIguais(): void
    {
        self::assertTrue(NomeNormalizado::iguais('José da Silva.', 'JOSE  DA SILVA'));
        self::assertFalse(NomeNormalizado::iguais('José da Silva', 'José Silva'));
    }

    #[TestDox('documento do cliente: só os dígitos, com ou sem máscara')]
    public function testDigitos(): void
    {
        self::assertSame('00646225170', DocumentoDoCliente::digitos('006.462.251-70'));
        self::assertSame('00646225170', DocumentoDoCliente::digitos('00646225170'));
        self::assertSame('11222333000181', DocumentoDoCliente::digitos('11.222.333/0001-81'));
        self::assertSame('', DocumentoDoCliente::digitos('sem número'));
    }

    #[TestDox('textos do desenho, neutros: subtítulo sem "cadastro em dia", homônimo PF e PJ, mesmo documento')]
    public function testTextos(): void
    {
        self::assertSame('3 documento(s) deste cliente em 2 pasta(s)', RotulosDaInteligenciaCadastral::subtitulo(3, 2));
        self::assertSame(
            ['negrito' => 'Possível homônimo.', 'texto' => '2 outro(s) cadastro(s) com o mesmo nome e CPF diferente. Documentos só com o nome não atualizam este cadastro.'],
            RotulosDaInteligenciaCadastral::homonimo(2, false),
        );
        self::assertSame('1 outro(s) cadastro(s) com a mesma razão social e CNPJ diferente. Documentos só com o nome não atualizam este cadastro.', RotulosDaInteligenciaCadastral::homonimo(1, true)['texto']);
        self::assertSame(
            ['negrito' => 'Mesmo CPF com outro nome.', 'texto' => '"Gleisson B. Gabriel" em Cobranças. Pode ser abreviação ou cadastro duplicado; confira antes de consolidar.'],
            RotulosDaInteligenciaCadastral::mesmoDocumento('Gleisson B. Gabriel', RotulosDaInteligenciaCadastral::ORIGEM_COBRANCAS, false),
        );
        self::assertSame(
            'Há outro cadastro de cliente com outro nome. Pode ser abreviação ou cadastro duplicado; confira antes de consolidar.',
            RotulosDaInteligenciaCadastral::mesmoDocumento(null, RotulosDaInteligenciaCadastral::ORIGEM_OUTRO_CADASTRO, false)['texto']
        );
        self::assertSame('Nome indica uma possibilidade; CPF e outros dados confirmam a identidade. Nada muda no cadastro sem validação, e cada mudança fica no Histórico.', RotulosDaInteligenciaCadastral::nota(false));
    }

    #[TestDox('nenhum rótulo fala em IA nem usa travessão')]
    public function testSemIa(): void
    {
        $textos = array_merge(
            [RotulosDaInteligenciaCadastral::TITULO, RotulosDaInteligenciaCadastral::ENCONTRADOS, RotulosDaInteligenciaCadastral::VINCULAR,
                RotulosDaInteligenciaCadastral::subtitulo(1, 1), RotulosDaInteligenciaCadastral::nota(false), RotulosDaInteligenciaCadastral::nota(true)],
            array_values(RotulosDaInteligenciaCadastral::homonimo(1, false)),
            array_values(RotulosDaInteligenciaCadastral::homonimo(1, true)),
            array_values(RotulosDaInteligenciaCadastral::mesmoDocumento('Fulano', RotulosDaInteligenciaCadastral::ORIGEM_OUTRO_CADASTRO, true)),
            array_values(RotulosDaInteligenciaCadastral::CATEGORIAS),
        );
        foreach ($textos as $texto) {
            self::assertDoesNotMatchRegularExpression('/\bI\.?A\.?\b|intelig[êe]ncia artificial|confian[çc]a/iu', str_replace('Inteligência cadastral', '', $texto), $texto);
            self::assertStringNotContainsString('—', $texto);
            self::assertStringNotContainsString('–', $texto);
        }
    }

    #[TestDox('resposta do contrato: abrirUrl só no alerta que tem o outro cadastro visível; desligado mantém as chaves')]
    public function testResposta(): void
    {
        $saida = new InteligenciaCadastralOutput(true, 'sub', [
            new AlertaDaInteligenciaCadastral('homonimo', 'bi bi-people', 'N', 'T'),
            new AlertaDaInteligenciaCadastral('mesmoDocumento', 'bi bi-intersect', 'N', 'T', 7),
        ], [new DocumentoEncontradoNaPasta(5, 'rg.pdf', 'IDENTIFICACAO', 'Documento de identificação', 'Pasta 1180', '12/03/2024', true)], 'nota');

        $r = $saida->paraResposta(static fn (int $id): string => '/clientes/' . $id . '/cadastro');

        self::assertSame(['on', 'titulo', 'subtitulo', 'alertas', 'encontrados', 'nota'], array_keys($r));
        self::assertArrayNotHasKey('abrirUrl', $r['alertas'][0]);
        self::assertSame('/clientes/7/cadastro', $r['alertas'][1]['abrirUrl']);
        self::assertSame(['id' => 5, 'arquivo' => 'rg.pdf', 'categoria' => 'IDENTIFICACAO', 'categoriaRotulo' => 'Documento de identificação', 'pasta' => 'Pasta 1180', 'data' => '12/03/2024', 'podeVincular' => true], $r['encontrados'][0]);
        self::assertFalse(InteligenciaCadastralOutput::desligado()->paraResposta(static fn (int $id): string => '')['on']);
    }
}
