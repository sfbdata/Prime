<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** O contrato do comercial-descadastrados.js com a 5d (eventos) e os textos do desenho (L694 e L697). */
#[CoversNothing]
final class ComercialDescadastradosJsTest extends TestCase
{
    private const JS = __DIR__ . '/../../../public/js/master/comercial-descadastrados.js';

    #[TestDox('ouve mc:descadastrados-abrir, dispara mc:resumo com {campanhas, naFila, base, descadastrados} e o KPI usa o mesmo evento')]
    public function testEventosCombinados(): void
    {
        $js = (string) file_get_contents(self::JS);

        self::assertStringContainsString("document.addEventListener('mc:descadastrados-abrir'", $js);
        self::assertStringContainsString("new CustomEvent('mc:resumo', { detail: { campanhas: r.campanhas, naFila: r.naFila, base: r.base, descadastrados: r.descadastrados } })", $js);
        self::assertStringContainsString("kpiAbrir.addEventListener('click', () => document.dispatchEvent(new CustomEvent('mc:descadastrados-abrir')))", $js);
    }

    #[TestDox('os textos do confirm do Excluir (LGPD) e do prompt do Registrar são os do desenho; as linhas saem por textContent')]
    public function testTextosDoDesenhoESemInnerHtml(): void
    {
        $js = (string) file_get_contents(self::JS);

        self::assertStringContainsString("'Excluir ' + item.email + ' da lista de descadastrados? Ele volta a poder receber e-mails. Faça isso só se o próprio contato pediu (LGPD).'", $js);
        self::assertStringContainsString("'E-mail que pediu para não receber (por telefone, WhatsApp etc.):'", $js);
        self::assertStringNotContainsString('innerHTML', $js);
        self::assertStringContainsString("'X-CSRF-Token': TOKEN", $js);
    }
}
