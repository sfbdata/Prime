<?php

declare(strict_types=1);

namespace App\Tests\Djen\Unit;

use App\Djen\DTO\PublicacaoDjenListaItem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O trecho curto do teor que vira o título do cartão na aba Push da pasta (FPN-012, desenho 1.2.3
 * dc L.2514/5378). O teor chega em HTML e cortado pela consulta; o título tem de sair como TEXTO
 * puro, cortado no fim de uma palavra inteira.
 */
#[CoversClass(PublicacaoDjenListaItem::class)]
#[Group('djen')]
final class PublicacaoDjenListaItemResumoTest extends TestCase
{
    #[TestDox('Sem teor (nulo, vazio ou só marcação) não há resumo: o Twig cai no órgão')]
    public function testSemTextoUtilDevolveNulo(): void
    {
        self::assertNull(PublicacaoDjenListaItem::resumirTexto(null));
        self::assertNull(PublicacaoDjenListaItem::resumirTexto(''));
        self::assertNull(PublicacaoDjenListaItem::resumirTexto('<p> </p><br>&nbsp;'));
    }

    #[TestDox('Texto curto sai inteiro, sem reticências')]
    public function testTextoCurtoSaiInteiro(): void
    {
        self::assertSame(
            'Intimação da parte autora para manifestação.',
            PublicacaoDjenListaItem::resumirTexto('Intimação da parte autora para manifestação.'),
        );
    }

    #[TestDox('As tags viram espaço: o fim de um parágrafo não cola no começo do seguinte')]
    public function testTagsViramEspaco(): void
    {
        self::assertSame(
            'PODER JUDICIÁRIO Intimação da parte autora',
            PublicacaoDjenListaItem::resumirTexto('<p>PODER JUDICIÁRIO</p><p><b>Intimação</b> da parte<br/>autora</p>'),
        );
    }

    #[TestDox('Entidades viram caracteres: o resultado é texto, não HTML')]
    public function testEntidadesDecodificadas(): void
    {
        self::assertSame(
            'Prazo de 15 dias – art. 5º "caput" & parágrafo',
            PublicacaoDjenListaItem::resumirTexto('Prazo&nbsp;de 15 dias &ndash; art. 5&ordm; &quot;caput&quot; &amp; parágrafo'),
        );
    }

    #[TestDox('Conteúdo de <script> e <style> não entra; um &lt;script&gt; escrito no teor fica como texto')]
    public function testScriptForaEEscritoComoTexto(): void
    {
        self::assertSame(
            'Antes depois',
            PublicacaoDjenListaItem::resumirTexto('Antes <script>alert(1)</script><style>p{color:red}</style> depois'),
        );
        // O DTO devolve o caractere; quem escapa é o Twig (ver PastaPushTituloTelaTest).
        self::assertSame(
            'Teor com <script>alert(1)</script> escrito',
            PublicacaoDjenListaItem::resumirTexto('Teor com &lt;script&gt;alert(1)&lt;/script&gt; escrito'),
        );
    }

    #[TestDox('Tag aberta no fim (o teor vem cortado pela consulta) sai inteira')]
    public function testTagCortadaNoFim(): void
    {
        self::assertSame('Decisão interlocutória', PublicacaoDjenListaItem::resumirTexto('Decisão interlocutória <span class="x'));
    }

    #[TestDox('Texto longo: corta no fim da última palavra inteira, até o limite, com reticências')]
    public function testCorteNaPalavra(): void
    {
        $texto = str_repeat('palavra ', 40); // 320 caracteres

        $resumo = PublicacaoDjenListaItem::resumirTexto($texto);

        self::assertNotNull($resumo);
        self::assertStringEndsWith('palavra…', $resumo);
        self::assertLessThanOrEqual(PublicacaoDjenListaItem::LIMITE_RESUMO + 1, mb_strlen($resumo));
        // Nenhuma palavra pela metade antes das reticências.
        foreach (explode(' ', mb_substr($resumo, 0, -1)) as $parte) {
            self::assertSame('palavra', $parte);
        }
    }

    #[TestDox('Corte que cairia no meio de uma palavra recua até o espaço anterior')]
    public function testCorteNoMeioDaPalavraRecua(): void
    {
        self::assertSame('abc def…', PublicacaoDjenListaItem::resumirTexto('abc def ghijkl', 10));
        // Corte exato no fim de uma palavra mantém a palavra.
        self::assertSame('abc def…', PublicacaoDjenListaItem::resumirTexto('abc def ghi', 7));
    }

    #[TestDox('Pontuação solta antes das reticências sai, sem mutilar letra acentuada')]
    public function testPontuacaoAntesDasReticencias(): void
    {
        self::assertSame('prazo…', PublicacaoDjenListaItem::resumirTexto('prazo, seguinte', 6));
        self::assertSame('ação…', PublicacaoDjenListaItem::resumirTexto('ação à vista', 5));
        self::assertSame('É…', PublicacaoDjenListaItem::resumirTexto('É À', 2));
    }

    #[TestDox('fromRow sem `textoInicio` (a lista do MÓDULO) deixa o resumo nulo; com ele, monta o trecho')]
    public function testFromRowComESemTextoInicio(): void
    {
        $base = [
            'id' => 1,
            'siglaTribunal' => 'TJDFT',
            'tipoComunicacao' => 'Intimação',
            'numeroProcessoComMascara' => '0701111-11.1111.1.11.1111',
            'numeroProcesso' => '07011111111111111111',
            'dataDisponibilizacao' => new \DateTimeImmutable('2026-08-20'),
            'nomeOrgao' => '1ª Vara Cível',
            'lida' => false,
            'processoId' => null,
        ];

        $doModulo = PublicacaoDjenListaItem::fromRow($base);
        self::assertNull($doModulo->textoResumo);
        self::assertSame('1ª Vara Cível', $doModulo->nomeOrgao);

        $daPasta = PublicacaoDjenListaItem::fromRow($base + ['textoInicio' => '<p>Fica a parte intimada.</p>']);
        self::assertSame('Fica a parte intimada.', $daPasta->textoResumo);
        self::assertSame('1ª Vara Cível', $daPasta->nomeOrgao, 'o órgão continua no DTO');
    }
}
