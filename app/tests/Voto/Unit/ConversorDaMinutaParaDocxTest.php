<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Voto\Service\ConversorDaMinutaParaDocx;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** O corpo HTML da minuta em WordprocessingML, com as medidas do desenho (bj-voto-ata.js). */
#[CoversClass(ConversorDaMinutaParaDocx::class)]
final class ConversorDaMinutaParaDocxTest extends TestCase
{
    private function xml(string $html): string
    {
        return (new ConversorDaMinutaParaDocx())->converter($html);
    }

    private function dom(string $xml): \DOMXPath
    {
        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML('<w:body xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">' . $xml . '</w:body>'), 'XML bem formado');
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        return $xp;
    }

    #[TestDox('Título: negrito, centralizado, 13 pt; parágrafo: justificado, 11 pt, Arial')]
    public function testTituloEParagrafo(): void
    {
        $xp = $this->dom($this->xml('<h1>ATA DA X</h1><p>Texto simples.</p>'));

        self::assertSame('center', $xp->evaluate('string(//w:p[1]//w:jc/@w:val)'));
        self::assertSame('26', $xp->evaluate('string(//w:p[1]//w:sz/@w:val)'));
        self::assertSame(1.0, $xp->evaluate('count(//w:p[1]//w:b)'));
        self::assertSame('both', $xp->evaluate('string(//w:p[2]//w:jc/@w:val)'));
        self::assertSame('22', $xp->evaluate('string(//w:p[2]//w:sz/@w:val)'));
        self::assertSame('Arial', $xp->evaluate('string(//w:p[2]//w:rFonts/@w:ascii)'));
    }

    #[TestDox('Campo [[assim]] vira run destacado em amarelo e os espaços ao redor ficam')]
    public function testDestaque(): void
    {
        $xp = $this->dom($this->xml('<p>presididos por <mark>[nome do presidente]</mark> e secretariados</p>'));

        $runs = $xp->query('//w:r');
        self::assertCount(3, $runs);
        self::assertSame('presididos por ', $runs->item(0)?->textContent);
        self::assertSame('[nome do presidente]', $runs->item(1)?->textContent);
        self::assertSame(1.0, $xp->evaluate('count(//w:r[2]//w:highlight[@w:val="yellow"])'));
        self::assertSame(0.0, $xp->evaluate('count(//w:r[1]//w:highlight)'));
        self::assertSame(' e secretariados', $runs->item(2)?->textContent);
    }

    #[TestDox('Tabela: borda cinza, colunas iguais, cabeçalho em negrito e texto escapado')]
    public function testTabela(): void
    {
        $xp = $this->dom($this->xml('<table><tr><th>Opção</th><th>Votos</th></tr><tr><td>1 Sim &amp; Cia</td><td>3</td></tr></table>'));

        self::assertSame(1.0, $xp->evaluate('count(//w:tbl)'));
        self::assertSame('999999', $xp->evaluate('string(//w:tblBorders/w:top/@w:color)'));
        self::assertSame(2.0, $xp->evaluate('count(//w:gridCol)'));
        self::assertSame('4500', $xp->evaluate('string(//w:gridCol[1]/@w:w)'));
        self::assertSame(2.0, $xp->evaluate('count(//w:tr[1]//w:b)'));
        self::assertSame(0.0, $xp->evaluate('count(//w:tr[2]//w:b)'));
        self::assertSame('1 Sim & Cia', $xp->evaluate('string(//w:tr[2]/w:tc[1])'));
    }

    #[TestDox('Assinaturas: uma linha e o nome centralizado, por assinante')]
    public function testAssinaturas(): void
    {
        $xp = $this->dom($this->xml('<div class="assinaturas"><p>Fulano, Presidência</p><p>Beltrano, Membro</p></div>'));

        self::assertSame(2.0, $xp->evaluate('count(//w:p[contains(., "____")])'));
        self::assertSame(1.0, $xp->evaluate('count(//w:p[normalize-space(.)="Fulano, Presidência"])'));
        self::assertSame(1.0, $xp->evaluate('count(//w:p[normalize-space(.)="Beltrano, Membro"])'));
    }

    #[TestDox('Parágrafo pequeno usa 9 pt e o negrito usa o espaçamento curto')]
    public function testClasses(): void
    {
        $xp = $this->dom($this->xml('<p class="pequeno">Aviso</p><p class="negrito">1. Pauta</p>'));

        self::assertSame('18', $xp->evaluate('string(//w:p[1]//w:sz/@w:val)'));
        self::assertSame(1.0, $xp->evaluate('count(//w:p[2]//w:b)'));
        self::assertSame('60', $xp->evaluate('string(//w:p[2]//w:spacing/@w:after)'));
    }

    #[TestDox('HTML mal formado: erro claro, nunca XML quebrado')]
    public function testHtmlMalFormado(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->xml('<p>sem fechar');
    }
}
