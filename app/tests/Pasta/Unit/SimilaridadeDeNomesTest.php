<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\Service\SimilaridadeDeNomes;
use App\Pasta\Service\SugestorDeDocumentos;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A similaridade de nomes extraída do Sugestor (L9, DOC-63): a regra de `similar`/`norm` de
 * bj-docsug.js (L9, L62) e a busca de pares ≥ 0,85 sem a comparação de todos contra todos.
 */
#[CoversClass(SimilaridadeDeNomes::class)]
final class SimilaridadeDeNomesTest extends TestCase
{
    /** @return iterable<string, array{string, string, float}> */
    public static function pares(): iterable
    {
        yield 'mesmo nome em caixa e acento diferentes' => ['PROCURAÇÃO', 'Procuração', 1.0];
        yield 'acentos e extensões diferentes' => ['Certidão de Óbito.PDF', 'certidao de obito.docx', 1.0];
        yield 'só a extensão FINAL sai' => ['contrato.social.pdf', 'contrato', 1.0];
        yield 'nenhuma palavra em comum' => ['RG e CPF', 'Documento de identidade', 0.0];
        yield 'palavras de ruído não contam' => ['procuracao_assinada_final_v2.pdf', 'Procuração', 1.0];
        yield 'número puro não conta' => ['peticao 2024 12345.pdf', 'peticao.pdf', 1.0];
        yield 'parcial pelo menor conjunto' => ['Comprovante de residência', 'Comprovante de pagamento / depósito judicial', 0.5];
        yield 'palavra de 2 letras não conta' => ['RG.pdf', 'RG.pdf', 0.0];
        yield 'vazio' => ['', 'Procuração', 0.0];
    }

    #[DataProvider('pares')]
    #[TestDox('similaridade (similar do JS): palavras em comum ÷ menor conjunto')]
    public function testSimilaridade(string $a, string $b, float $esperado): void
    {
        self::assertEqualsWithDelta($esperado, SimilaridadeDeNomes::similaridade($a, $b), 0.0001);
    }

    #[TestDox('o Sugestor continua com a MESMA regra: delega para SimilaridadeDeNomes')]
    public function testSugestorDelega(): void
    {
        foreach (self::pares() as [$a, $b]) {
            self::assertSame(SimilaridadeDeNomes::similaridade($a, $b), SugestorDeDocumentos::similaridade($a, $b));
            self::assertSame(SimilaridadeDeNomes::normalizar($a), SugestorDeDocumentos::normalizar($a));
        }
        self::assertSame('procuracao ad judicia', SimilaridadeDeNomes::normalizar('PROCURAÇÃO AD JUDICIA'));
    }

    #[TestDox('pares ≥ 0,85: casa com acento/extensão diferentes, ignora nome normalizado IGUAL e o que fica abaixo do mínimo')]
    public function testParesParecidos(): void
    {
        $pares = SimilaridadeDeNomes::paresParecidos([
            1 => 'Procuração Gleisson Bruno.pdf',
            2 => 'procuracao_gleisson_bruno_assinada.pdf',   // 100%: "assinada" é ruído
            3 => 'PROCURAÇÃO GLEISSON BRUNO.PDF',            // nome normalizado igual ao 1: não é "parecido", é o mesmo nome
            4 => 'Comprovante de residência.pdf',
            5 => 'Comprovante de pagamento.pdf',              // 50% com o 4: abaixo do mínimo
        ], 0.85);

        self::assertSame([
            ['a' => 1, 'b' => 2, 'similaridade' => 1.0],
            ['a' => 2, 'b' => 3, 'similaridade' => 1.0],
        ], $pares);
    }

    #[TestDox('nenhum par: lista vazia — inclusive quando nenhum nome tem palavra que conte')]
    public function testNenhumPar(): void
    {
        self::assertSame([], SimilaridadeDeNomes::paresParecidos([], 0.85));
        self::assertSame([], SimilaridadeDeNomes::paresParecidos([1 => 'RG.pdf', 2 => 'RG.pdf', 3 => '123.pdf'], 0.85));
        self::assertSame([], SimilaridadeDeNomes::paresParecidos([1 => 'Petição inicial', 2 => 'Procuração'], 0.85));
    }

    #[TestDox('o filtro de prefixo dá o MESMO resultado que comparar todos contra todos (limiar exato incluído)')]
    public function testFiltroIgualAForcaBruta(): void
    {
        $palavras = ['procuracao', 'contrato', 'peticao', 'inicial', 'gleisson', 'bruno', 'certidao', 'obito', 'residencia', 'comprovante', 'laudo', 'medico', 'sentenca'];
        mt_srand(20261006);
        $nomes = [];
        for ($id = 1; $id <= 160; ++$id) {
            $n = mt_rand(1, 8);
            $sel = [];
            for ($i = 0; $i < $n; ++$i) {
                $sel[] = $palavras[mt_rand(0, \count($palavras) - 1)];
            }
            $nomes[$id] = implode(' ', $sel) . (mt_rand(0, 1) === 1 ? '.pdf' : '');
        }

        foreach ([0.85, 0.6, 1.0, 6 / 7] as $minimo) {
            $esperado = [];
            $ids      = array_keys($nomes);
            foreach ($ids as $i => $a) {
                foreach (\array_slice($ids, $i + 1) as $b) {
                    $s = SimilaridadeDeNomes::similaridade($nomes[$a], $nomes[$b]);
                    if ($s >= $minimo && SimilaridadeDeNomes::normalizar($nomes[$a]) !== SimilaridadeDeNomes::normalizar($nomes[$b])) {
                        $esperado[] = ['a' => $a, 'b' => $b, 'similaridade' => $s];
                    }
                }
            }

            self::assertNotEmpty($esperado, 'a amostra precisa ter pares para o teste valer');
            self::assertSame($esperado, SimilaridadeDeNomes::paresParecidos($nomes, $minimo), 'mínimo ' . $minimo);
        }
    }
}
