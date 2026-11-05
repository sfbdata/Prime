<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Auditoria 5.10: a dica com os nomes dos clientes (pasta sem nome e com dois ou mais clientes) é
 * montada no JS inline de `expediente/_lista.html.twig`. O nome do cliente é texto livre: concatenado
 * numa string HTML e entregue ao Tooltip com `html: true`, um `<img src=...>` no nome virava imagem
 * dentro da dica. A dica agora é feita de nós DOM com `textContent`. O JS é inline, então a
 * conferência é textual.
 */
#[CoversNothing]
final class TooltipDeClientesSemHtmlTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../../templates/expediente/_lista.html.twig';

    private function blocoDosTooltips(): string
    {
        $fonte = (string) file_get_contents(self::TEMPLATE);
        $inicio = strpos($fonte, 'function iniciarTooltipsClientes');
        self::assertNotFalse($inicio, 'premissa: a função dos tooltips de clientes existe');
        $fim = strpos($fonte, "\n    }\n", $inicio);
        self::assertNotFalse($fim);

        return substr($fonte, $inicio, $fim - $inicio);
    }

    #[TestDox('Os nomes não são concatenados em string HTML')]
    public function testNomesNaoViramStringHtml(): void
    {
        $bloco = $this->blocoDosTooltips();

        self::assertDoesNotMatchRegularExpression("/'<div>'\\s*\\+/", $bloco);
        self::assertStringNotContainsString('innerHTML', $bloco);
    }

    #[TestDox('Cada nome entra na dica por textContent, num nó criado pelo DOM')]
    public function testNomesEntramPorTextContent(): void
    {
        $bloco = $this->blocoDosTooltips();

        self::assertStringContainsString("document.createElement('div')", $bloco);
        self::assertStringContainsString('.textContent = ', $bloco);
        self::assertStringContainsString('dataset.clientes', $bloco);
    }
}
