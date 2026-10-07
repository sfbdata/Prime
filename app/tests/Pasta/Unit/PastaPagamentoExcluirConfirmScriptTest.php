<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * FPN-008: o `confirm` de excluir lançamento é o texto do desenho (dc 3456):
 * `'Excluir "' + desc + '" (' + brl(v) + ')?'`. Teste de FOLHA — o `confirm` não roda no
 * PHPUnit; o que se trava aqui é a frase e de onde vêm o nome e o valor (a LINHA do
 * lançamento, que o servidor imprime com `data-descricao` e `data-valor`). O smoke confere a
 * caixa de diálogo de verdade.
 */
final class PastaPagamentoExcluirConfirmScriptTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../../templates/pasta/_financeiro_pagamentos_script.html.twig';
    private const CARTAO = __DIR__ . '/../../../templates/pasta/_financeiro_pagamentos.html.twig';

    #[TestDox('a pergunta tem o nome e o valor do lançamento, como no desenho, e a frase antiga sem nome nem valor saiu')]
    public function testFraseDoDesenho(): void
    {
        $js = (string) file_get_contents(self::SCRIPT);

        self::assertStringContainsString("'Excluir \"' + (linhaEx.dataset.descricao || '') + '\" (' + brl(valorEx) + ')?'", $js);
        self::assertStringContainsString("lerValor(linhaEx.dataset.valor || '').numero", $js);
        self::assertStringContainsString("if (!confirm(perguntaEx)) { return; }", $js);
        self::assertStringNotContainsString('Excluir este lançamento? Esta ação não pode ser desfeita.', $js);
    }

    #[TestDox('a linha do card carrega o nome e o valor que a pergunta lê')]
    public function testLinhaCarregaNomeEValor(): void
    {
        $twig = (string) file_get_contents(self::CARTAO);

        self::assertStringContainsString('data-descricao="{{ linha.descricao }}"', $twig);
        self::assertStringContainsString('data-valor="{{ linha.valorEditavel }}"', $twig);
    }
}
