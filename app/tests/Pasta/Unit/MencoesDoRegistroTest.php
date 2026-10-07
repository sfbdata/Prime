<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\Service\MencoesDoRegistro;
use App\Tests\Shared\CriaSanitizadorTextoRico;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Item 20b: o formato `@[Nome](user:ID)` — leitura, reescrita canônica e exibição escapada.
 * Usa o sanitizador REAL (mesma config do YAML), porque é ele que codifica `@` como `&#64;`.
 */
#[CoversClass(MencoesDoRegistro::class)]
final class MencoesDoRegistroTest extends TestCase
{
    use CriaSanitizadorTextoRico;

    private MencoesDoRegistro $mencoes;

    protected function setUp(): void
    {
        $this->mencoes = new MencoesDoRegistro($this->criarSanitizadorTextoRico());
    }

    #[TestDox('Lê os ids na ordem em que aparecem, sem repetir — em texto puro e em HTML sanitizado (&#64;)')]
    public function testExtraiIds(): void
    {
        self::assertSame([5, 9], $this->mencoes->extrairIds('Oi @[Ana](user:5) e @[Bia](user:9), de novo @[Ana](user:5)'));
        self::assertSame([7], $this->mencoes->extrairIds('<p>&#64;[Carla](user:7) ok</p>'));
        self::assertSame([], $this->mencoes->extrairIds('e-mail ana@x.com, @Ana sem token, @[](user:3), @[Ana](user:abc)'));
        self::assertSame([], $this->mencoes->extrairIds(null));
    }

    #[TestDox('O rótulo não atravessa tag: um "token" partido por marcação não é menção')]
    public function testTokenNaoAtravessaTag(): void
    {
        self::assertSame([], $this->mencoes->extrairIds('<p>@[An<strong>a</strong>](user:5)</p>'));
    }

    #[TestDox('No máximo 20 pessoas por registro')]
    public function testTetoDeMencoes(): void
    {
        $texto = '';
        for ($i = 1; $i <= 25; ++$i) {
            $texto .= "@[P{$i}](user:{$i}) ";
        }

        self::assertCount(MencoesDoRegistro::MAXIMO_POR_REGISTRO, $this->mencoes->extrairIds($texto));
    }

    #[TestDox('Reescrever: id válido ganha o nome do BANCO; id inexistente ou de outro escritório vira "@rótulo" sem marcação')]
    public function testReescreveValidoEDegradaInvalido(): void
    {
        $saida = $this->mencoes->reescrever(
            'Fala @[Apelido](user:5) e @[Fulano de Outro Escritório](user:99)',
            [5 => 'Ana Paula Souza'],
        );

        self::assertSame('Fala @[Ana Paula Souza](user:5) e @Fulano de Outro Escritório', $saida);
        self::assertSame([5], $this->mencoes->extrairIds($saida));
    }

    #[TestDox('Reescrever em HTML: o nome do banco entra escapado e o conteúdo continua sendo HTML')]
    public function testReescreveEmHtmlEscapaONome(): void
    {
        $saida = $this->mencoes->reescrever('<p>&#64;[x](user:5)</p>', [5 => 'Ana & <script>alert(1)</script>']);

        self::assertStringNotContainsString('<script>', $saida);
        self::assertStringContainsString('@[Ana &amp; script alert 1 /script](user:5)', $saida);
    }

    #[TestDox('Reescrever em texto puro não introduz "<" (o conteúdo não muda de tipo)')]
    public function testReescreveTextoPuroSemMenorQue(): void
    {
        $saida = $this->mencoes->reescrever('Oi @[x](user:5)', [5 => '<b>Ana</b>']);

        self::assertStringNotContainsString('<', $saida);
        self::assertSame('Oi @[b Ana /b](user:5)', $saida);
    }

    #[TestDox('Exibir: o token vira destaque .ps-mencao com o id, em HTML sanitizado e em texto puro legado')]
    public function testExibeDestaque(): void
    {
        self::assertSame(
            '<p>Oi <span class="ps-mencao" data-user-id="5">@Ana Paula</span>!</p>',
            $this->mencoes->exibir('<p>Oi @[Ana Paula](user:5)!</p>'),
        );
        self::assertSame(
            "Oi <span class=\"ps-mencao\" data-user-id=\"5\">@Ana</span><br>\ntchau",
            $this->mencoes->exibir("Oi @[Ana](user:5)\ntchau"),
        );
    }

    #[TestDox('XSS no rótulo: a exibição escapa o nome (texto puro e HTML) — nada vira tag')]
    public function testExibicaoEscapaORotulo(): void
    {
        // Texto puro: o rótulo não pode ter "<" no padrão, mas "&", aspas e entidades sim.
        $puro = $this->mencoes->exibir('@[Ana &lt;img src=x onerror=alert(1)&gt; "x"](user:5)');
        self::assertStringNotContainsString('<img', $puro);
        self::assertStringContainsString('<span class="ps-mencao" data-user-id="5">@Ana &amp;lt;img src=x onerror=alert(1)&amp;gt; &quot;x&quot;</span>', $puro);

        // HTML: o sanitizador entrega o rótulo com entidades; elas são decodificadas e escapadas de novo.
        $html = $this->mencoes->exibir('<p>@[Ana &lt;script&gt;alert(1)&lt;/script&gt;](user:5)</p>');
        self::assertStringNotContainsString('<script', $html);
        self::assertStringContainsString('<span class="ps-mencao" data-user-id="5">@Ana &lt;script&gt;alert(1)&lt;/script&gt;</span>', $html);
    }

    #[TestDox('Exibir não mexe dentro de atributo (só nos trechos de texto)')]
    public function testNaoTrocaDentroDeAtributo(): void
    {
        $saida = $this->mencoes->exibir('<ul><li data-list="@[Ana](user:5)">item</li></ul>');

        self::assertStringNotContainsString('ps-mencao', $saida);
    }

    #[TestDox('Exibir continua sanitizando: script do conteúdo some, o destaque fica')]
    public function testExibirSanitiza(): void
    {
        $saida = $this->mencoes->exibir('<p>@[Ana](user:5)<script>alert(1)</script></p>');

        self::assertStringNotContainsString('<script', $saida);
        self::assertStringContainsString('class="ps-mencao"', $saida);
    }

    #[TestDox('Texto plano (sino): o token vira "@Nome"')]
    public function testTextoPlano(): void
    {
        self::assertSame('Oi @Ana Paula, veja', $this->mencoes->paraTextoPlano('Oi @[Ana Paula](user:5), veja'));
    }

    #[TestDox('Rótulo: tira colchetes, parênteses e sinais de tag; nome vazio vira "colega"')]
    public function testRotulo(): void
    {
        self::assertSame('Ana Maria Souza', MencoesDoRegistro::rotulo('  Ana [Maria] (Souza) '));
        self::assertSame('colega', MencoesDoRegistro::rotulo('[]()'));
    }
}
