<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Lote L7 (cabeçalho, CSS da pasta e aba Processo), lido como FOLHA: não há navegador no
 * PHPUnit, então o que se prova é o contrato do código-fonte com o desenho 1.2.3. O efeito na
 * tela (posição, cor, tempo) é smoke do dono.
 */
#[CoversNothing]
final class PastaCabecalhoL7FolhaTest extends TestCase
{
    private const CSS        = __DIR__ . '/../../../public/css/pasta-show.css';
    private const PASTA_JS   = __DIR__ . '/../../../public/js/pasta-show.js';
    private const CABECALHO  = __DIR__ . '/../../../templates/pasta/_cabecalho.html.twig';

    private static function ler(string $arquivo): string
    {
        $conteudo = file_get_contents($arquivo);
        self::assertIsString($conteudo);

        return $conteudo;
    }

    /** Trecho da fonte entre dois marcadores (o segundo é opcional: sem ele, vai até o fim). */
    private static function trecho(string $fonte, string $inicio, ?string $fim = null): string
    {
        $a = strpos($fonte, $inicio);
        self::assertNotFalse($a, "marcador {$inicio} existe");
        if ($fim === null) {
            return substr($fonte, $a);
        }
        $b = strpos($fonte, $fim, $a + strlen($inicio));
        self::assertNotFalse($b, "marcador {$fim} existe depois de {$inicio}");

        return substr($fonte, $a, $b - $a);
    }

    #[TestDox('EDT-007: "Copiar link" troca bi-link por bi-check2 só quando copiou, e fecha em 900 ms')]
    public function testCopiarLinkTrocaIconeEFechaEm900ms(): void
    {
        $bloco = self::trecho(self::ler(self::PASTA_JS), '"Copiar link": real', 'Atalhos do menu');

        self::assertStringContainsString("classList.replace('bi-link', 'bi-check2')", $bloco);
        self::assertStringContainsString("classList.replace('bi-check2', 'bi-link')", $bloco, 'o ícone volta ao fechar');
        self::assertStringContainsString('}, 900);', $bloco);
        self::assertStringNotContainsString('1600', $bloco);
        self::assertStringContainsString("avisar('Link copiado', true)", $bloco);
        self::assertStringContainsString("avisar('Não foi possível copiar', false)", $bloco, 'na falha não há check');
        self::assertStringContainsString('if (iconeCopiar && copiou)', $bloco);

        self::assertStringContainsString('<i class="bi bi-link"></i><span class="js-copiar-link-rotulo">', self::ler(self::CABECALHO));
    }
}
