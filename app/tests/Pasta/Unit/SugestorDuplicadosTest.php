<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\DTO\SugestaoDeDocumentosOutput;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Service\SugestorDeDocumentos;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * DOC-106: "Possíveis arquivos duplicados" do painel de sugestões — bj-docsug.js L107-109:
 * `s >= .85 && norm(a.nome) !== norm(b.nome)`, todos contra todos, `a` antes de `b`, e o
 * percentual é `Math.round(s * 100)`.
 *
 * A conta (palavras em comum ÷ menor conjunto) é a do `SimilaridadeDeNomes`, já coberta no teste
 * dele; aqui se trava o que o painel faz com ela: o limite, a exclusão do nome idêntico, a ordem,
 * o nome usado e que o par independe do catálogo.
 */
#[CoversClass(SugestorDeDocumentos::class)]
#[CoversClass(SugestaoDeDocumentosOutput::class)]
final class SugestorDuplicadosTest extends TestCase
{
    /** @return array{titulo: string, nomeOriginal: string, categoria: string, data: string} */
    private static function arquivo(string $nomeOriginal, string $titulo = ''): array
    {
        return [
            // O setter grava em MAIÚSCULAS: o título vem dele, como no banco.
            'titulo'       => (new PastaDocumento())->setTitulo($titulo !== '' ? $titulo : $nomeOriginal)->getTitulo(),
            'nomeOriginal' => $nomeOriginal,
            'categoria'    => PastaDocumento::CATEGORIA_DEMAIS,
            'data'         => '07/10/2026',
        ];
    }

    #[TestDox('o par do próprio desenho (EXP_META): procuração com e sem "assinada" é 100% parecido e entra')]
    public function testParDoDesenho(): void
    {
        $pares = SugestorDeDocumentos::duplicados([
            self::arquivo('PROCURAÇÃO_GLEISSON_BRUNO.pdf'),
            self::arquivo('DOCUMENTO_SEM_TITULO.pdf'),
            self::arquivo('procuracao_gleisson_assinada.pdf'),
        ]);

        self::assertSame([
            ['a' => 'PROCURAÇÃO_GLEISSON_BRUNO.pdf', 'b' => 'procuracao_gleisson_assinada.pdf', 'percentual' => 100],
        ], $pares);
    }

    /** @return iterable<string, array{string, string, ?int}> nome A, nome B, percentual esperado (null = não é par) */
    public static function limites(): iterable
    {
        // 6 de 7 palavras em comum = 0,857 → entra (86%).
        yield '6/7 = 0,857 entra'   => ['alfa bravo charlie delta echo foxtrot golf.pdf', 'alfa bravo charlie delta echo foxtrot hotel.pdf', 86];
        // 5 de 6 = 0,833 → abaixo de 0,85, fica fora.
        yield '5/6 = 0,833 fora'    => ['alfa bravo charlie delta echo foxtrot.pdf', 'alfa bravo charlie delta echo hotel.pdf', null];
        // Nome idêntico depois de normalizar (caixa e acento) NÃO é par: é o mesmo nome.
        yield 'idêntico (caixa)'    => ['Contrato de honorários.pdf', 'CONTRATO DE HONORÁRIOS.pdf', null];
        yield 'idêntico (acento)'   => ['procuração.pdf', 'procuracao.pdf', null];
        // Nomes diferentes.
        yield 'diferentes'          => ['Petição inicial.pdf', 'Comprovante de residência.pdf', null];
        // Sem palavra que conte (só números, palavras curtas): similaridade 0.
        yield 'sem palavras'        => ['123.pdf', '456.pdf', null];
        // Contido no outro: palavras de A todas em B → 100%.
        yield 'contido'             => ['RG frente.jpg', 'RG frente e verso digitalizado.jpg', 100];
    }

    #[DataProvider('limites')]
    #[TestDox('a regra do desenho: ≥ 0,85 entra; abaixo, idêntico depois de normalizar ou sem palavras, não')]
    public function testLimites(string $a, string $b, ?int $percentual): void
    {
        $pares = SugestorDeDocumentos::duplicados([self::arquivo($a), self::arquivo($b)]);

        if ($percentual === null) {
            self::assertSame([], $pares);

            return;
        }
        self::assertSame([['a' => $a, 'b' => $b, 'percentual' => $percentual]], $pares);
    }

    #[TestDox('todos contra todos, na ordem dos arquivos (a antes de b), sobre a lista inteira')]
    public function testOrdemETodosContraTodos(): void
    {
        $pares = SugestorDeDocumentos::duplicados([
            self::arquivo('Contrato social da empresa.pdf'),
            self::arquivo('Petição inicial.pdf'),
            self::arquivo('Contrato social da empresa assinado.pdf'),
            self::arquivo('Contrato social empresa versao final.pdf'),
        ]);

        self::assertSame([
            ['a' => 'Contrato social da empresa.pdf', 'b' => 'Contrato social da empresa assinado.pdf', 'percentual' => 100],
            ['a' => 'Contrato social da empresa.pdf', 'b' => 'Contrato social empresa versao final.pdf', 'percentual' => 100],
            ['a' => 'Contrato social da empresa assinado.pdf', 'b' => 'Contrato social empresa versao final.pdf', 'percentual' => 100],
        ], $pares);
    }

    #[TestDox('o nome é o exibido no explorador (nomeOriginal); sem ele, o título')]
    public function testNomeUsado(): void
    {
        $pares = SugestorDeDocumentos::duplicados([
            self::arquivo('', 'Laudo médico ortopedia'),
            self::arquivo('laudo_medico_ortopedia_2026.pdf'),
        ]);

        $titulo = (new PastaDocumento())->setTitulo('Laudo médico ortopedia')->getTitulo();
        self::assertSame([['a' => $titulo, 'b' => 'laudo_medico_ortopedia_2026.pdf', 'percentual' => 100]], $pares);
    }

    #[TestDox('o painel recebe os pares — com catálogo e também sem ele (o duplicado não depende da ação)')]
    public function testSugestaoCarregaOsPares(): void
    {
        $arquivos = [self::arquivo('Procuração ad judicia.pdf'), self::arquivo('procuracao ad judicia assinada.pdf')];
        $esperado = [['a' => 'Procuração ad judicia.pdf', 'b' => 'procuracao ad judicia assinada.pdf', 'percentual' => 100]];

        $comCatalogo = (new SugestorDeDocumentos())->sugerir('Indenização', null, null, $arquivos, []);
        self::assertTrue($comCatalogo->temCatalogo);
        self::assertTrue($comCatalogo->temDuplicados());
        self::assertSame($esperado, $comCatalogo->duplicados);

        $semCatalogo = (new SugestorDeDocumentos())->sugerir(null, null, null, $arquivos, []);
        self::assertFalse($semCatalogo->temCatalogo);
        self::assertTrue($semCatalogo->temDuplicados());
        self::assertSame($esperado, $semCatalogo->duplicados);

        $semPar = (new SugestorDeDocumentos())->sugerir('Indenização', null, null, [self::arquivo('Procuração.pdf')], []);
        self::assertFalse($semPar->temDuplicados(), 'só aparece quando há par');
        self::assertSame([], $semPar->duplicados);
    }
}
