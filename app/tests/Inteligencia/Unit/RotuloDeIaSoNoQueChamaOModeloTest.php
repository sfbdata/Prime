<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Tests\Support\CatalogoPtBr;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Decisão do dono (24/10/2026, `docs/specs/ia-anthropic-ligar.md` §10): tela que é regra não leva o rótulo de IA.
 * Estes textos são de telas montadas por regra (previsão do Push pela tabela do CPC, relatório de processos);
 * voltam a falar de IA só quando a tela chamar o modelo (pendência no §10.3).
 */
#[CoversNothing]
final class RotuloDeIaSoNoQueChamaOModeloTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function textosDeRegra(): iterable
    {
        yield 'previsão do Push' => ['pasta_push.painel.situacao.rodape'];
        yield 'abertura do relatório de processos' => ['expediente.relatorio.abertura'];
        yield 'nota final do relatório de processos' => ['expediente.relatorio.nota_final.inicio'];
    }

    #[DataProvider('textosDeRegra')]
    #[TestDox('$chave não diz que é IA')]
    public function testTextoDeRegraNaoDizQueEIa(string $chave): void
    {
        $texto = self::texto($chave);

        self::assertDoesNotMatchRegularExpression('/\bI\.?A\b|intelig[eê]ncia artificial/iu', $texto);
        self::assertStringContainsString('regras', $texto, 'o texto diz de onde vem a leitura');
    }

    private static function texto(string $chave): string
    {
        $no = CatalogoPtBr::carregar();
        foreach (explode('.', $chave) as $parte) {
            $no = $no[$parte];
        }

        return $no;
    }
}
