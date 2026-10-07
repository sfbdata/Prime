<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Smoke no Chromium (06/10): com nada selecionado, o 1º clique mostrava a barra de seleção
 * (`#pexSelecao`, 38px + 6px de margem), que entrava no fluxo e empurrava a lista ~44px; o
 * 2º clique do duplo clique caía na linha de baixo e abria o arquivo VIZINHO.
 *
 * Teste de FOLHA (precedente: PastaExploradorInteracaoTest). Não há harness de navegador; o
 * que dá para travar é o `dblclick` abrindo a linha do 1º clique quando o alvo diverge em menos
 * de 400 ms. A barra continua empurrando a lista, como no desenho (dc L.2194): o desenho manda.
 */
final class PastaExploradorDuploCliqueTest extends TestCase
{
    private const JS  = __DIR__ . '/../../../public/js/pasta-explorador.js';

    #[TestDox('o dblclick abre a linha do 1º clique quando o 2º caiu em outra linha em menos de 400 ms, e devolve a seleção a ela')]
    public function testDuploCliqueUsaOAlvoDoPrimeiroClique(): void
    {
        $js = (string) file_get_contents(self::JS);

        self::assertStringContainsString('const DUPLO_CLIQUE_MS = 400;', $js);
        // O clique da lista registra a linha (ou o vazio) ANTES de decidir seleção.
        self::assertMatchesRegularExpression(
            "/registrarClique\(item \? chaveDoElemento\(item\) : null\);\s*if \(!item\) \{ limparSelecao\(\); return; \}/",
            $js
        );
        self::assertStringContainsString('if (a && u && a.chave && a.chave !== chaveDoAlvo && u.t - a.t < DUPLO_CLIQUE_MS) return a.chave;', $js);

        $ini = strpos($js, "el.lista.addEventListener('dblclick'");
        self::assertNotFalse($ini);
        $handler = substr($js, $ini, (int) strpos($js, "\n    });", $ini) - $ini);
        self::assertStringContainsString('const chave = primeiroNaCaixa ? null : chaveDoDuploClique(chaveDoAlvo);', $handler);
        self::assertStringContainsString('if (chave !== chaveDoAlvo) selecionar(chave);', $handler);
        self::assertStringContainsString('abrirItem(itemPorChave(chave));', $handler);
        // O 2º clique no vazio (a linha "sumiu" de baixo do ponteiro) ainda abre a do 1º.
        self::assertStringNotContainsString('if (!item || item.dataset.pexTemp !== undefined) return;', $handler);
        // Uma vez consumido, o par não serve para o próximo duplo clique.
        self::assertStringContainsString('cliqueAnterior = ultimoClique = null;', $handler);
    }
}
