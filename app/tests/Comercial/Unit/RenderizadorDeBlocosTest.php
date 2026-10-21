<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\Campanha\RenderizadorDeBlocos;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Cada bloco contra o estilo inline do desenho 05 (dc L.488-494), o simples (L.484-485, L.499-500), o escape, as URLs
 * recusadas, o `{nome}` só onde o dc troca e a chave `validar` virando `app` (spec §8.3, §8.7).
 */
#[CoversClass(RenderizadorDeBlocos::class)]
final class RenderizadorDeBlocosTest extends TestCase
{
    private function renderizador(): RenderizadorDeBlocos
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $rota, array $p = [], int $tipo = UrlGeneratorInterface::ABSOLUTE_PATH): string => match ([$rota, $tipo]) {
            ['app_login', UrlGeneratorInterface::ABSOLUTE_URL] => 'https://app.bluejus.test/login',
            ['app_login', UrlGeneratorInterface::ABSOLUTE_PATH] => '/login',
            ['auth_cadastro', UrlGeneratorInterface::ABSOLUTE_URL] => 'https://app.bluejus.test/cadastro',
            default => '/' . $rota,
        });

        return new RenderizadorDeBlocos($urls);
    }

    /** @param array{t: string, a?: string, b?: string} $bloco */
    private function um(array $bloco): string
    {
        $html = $this->renderizador()->blocos([$bloco]);
        self::assertStringEndsWith('<tr><td style="height:14px;"></td></tr>', $html, 'a linha espaçadora de 14 px (dc L.499)');

        return substr($html, 0, -\strlen('<tr><td style="height:14px;"></td></tr>'));
    }

    /** @return iterable<string, array{array{t: string, a?: string, b?: string}, string}> */
    public static function blocosDoDesenho(): iterable
    {
        yield 'titulo (L.488)' => [['t' => 'titulo', 'a' => 'Destaques'], '<tr><td style="padding:22px 30px 4px;font:bold 20px/1.3 Arial,Helvetica,sans-serif;color:#0b3550;">Destaques</td></tr>'];
        yield 'texto (L.489)' => [['t' => 'texto', 'a' => "Linha 1\nLinha 2"], '<tr><td style="padding:8px 30px;font:15px/1.6 Arial,Helvetica,sans-serif;color:#34505f;">Linha 1<br>Linha 2</td></tr>'];
        yield 'imagem (L.490)' => [['t' => 'imagem', 'a' => 'https://img.test/a.png', 'b' => 'Logo'], '<tr><td style="padding:12px 30px;"><img src="https://img.test/a.png" alt="Logo" width="540" style="display:block;width:100%;max-width:540px;height:auto;border-radius:6px;border:0;"></td></tr>'];
        yield 'botao (L.491)' => [['t' => 'botao', 'a' => 'Ver', 'b' => 'https://exemplo.com/x'], '<tr><td align="center" style="padding:14px 30px 20px;"><a href="https://exemplo.com/x" style="display:inline-block;background:#0a6fa0;color:#fff;font:bold 15px Arial,Helvetica,sans-serif;text-decoration:none;padding:13px 24px;border-radius:4px;">Ver</a></td></tr>'];
        yield 'divisor (L.492)' => [['t' => 'divisor'], '<tr><td style="padding:10px 30px;"><div style="height:1px;background:#e6edf2;"></div></td></tr>'];
        yield 'noticias (L.493)' => [['t' => 'noticias', 'a' => "Um|Resumo um\nDois"], '<tr><td style="padding:8px 30px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 10px;border:1px solid #e6edf2;border-radius:6px;"><tr><td style="padding:12px 14px;"><div style="font:bold 14.5px Arial,Helvetica,sans-serif;color:#0b3550;">Um</div><div style="font:13.5px/1.5 Arial,Helvetica,sans-serif;color:#5b7383;margin-top:3px;">Resumo um</div></td></tr></table><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 10px;border:1px solid #e6edf2;border-radius:6px;"><tr><td style="padding:12px 14px;"><div style="font:bold 14.5px Arial,Helvetica,sans-serif;color:#0b3550;">Dois</div></td></tr></table></td></tr>'];
        yield 'colunas (L.494)' => [['t' => 'colunas', 'a' => "E\n1", 'b' => 'D'], '<tr><td style="padding:8px 30px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td width="50%" valign="top" style="padding-right:8px;font:14px/1.55 Arial,Helvetica,sans-serif;color:#34505f;">E<br>1</td><td width="50%" valign="top" style="padding-left:8px;font:14px/1.55 Arial,Helvetica,sans-serif;color:#34505f;">D</td></tr></table></td></tr>'];
    }

    /** @param array{t: string, a?: string, b?: string} $bloco */
    #[DataProvider('blocosDoDesenho')]
    #[TestDox('Bloco $_dataName: o HTML e o estilo inline do desenho, 1:1')]
    public function testBlocoComOEstiloDoDesenho(array $bloco, string $esperado): void
    {
        self::assertSame($esperado, $this->um($bloco));
    }

    #[TestDox('O texto é escapado como o e() do dc (& < > ") e nenhuma marcação passa')]
    public function testEscape(): void
    {
        $html = $this->um(['t' => 'texto', 'a' => '<script>alert("x")</script> & cia']);

        self::assertStringContainsString('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; cia', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    #[TestDox('{nome} fica para o montador no título, no texto e nas colunas; nas notícias, no botão e na imagem, não (como o dc)')]
    public function testNomeSoOndeODcTroca(): void
    {
        foreach (['titulo', 'texto', 'colunas'] as $tipo) {
            self::assertStringContainsString('{nome}', $this->um(['t' => $tipo, 'a' => 'Olá, {nome}']), $tipo);
        }
        foreach ([['t' => 'noticias', 'a' => '{nome}|{nome}'], ['t' => 'botao', 'a' => '{nome}', 'b' => 'app'], ['t' => 'imagem', 'a' => 'https://i.test/{nome}.png', 'b' => '{nome}']] as $bloco) {
            $html = $this->um($bloco);
            self::assertStringNotContainsString('{nome}', $html, $bloco['t']);
            self::assertStringContainsString('&#123;nome}', $html, $bloco['t']);
        }
    }

    #[TestDox('Link do botão: as chaves do sistema, validar vira app, http(s) passa e o resto sai sem link')]
    public function testLinksDoBotao(): void
    {
        $r = $this->renderizador();

        self::assertSame('https://app.bluejus.test/login', $r->link('app'));
        self::assertSame('https://app.bluejus.test/login', $r->link('validar'), 'sem token por destinatário numa campanha');
        self::assertSame('https://app.bluejus.test/cadastro', $r->link('cadastro'));
        self::assertSame('https://app.bluejus.test/vagas-juridicas', $r->link('vagas'));
        self::assertSame('https://app.bluejus.test/contribua#sug', $r->link('contribua'));
        self::assertSame('https://app.bluejus.test/contribua#espaco', $r->link('espaco'));
        self::assertSame('http://exemplo.com/a', $r->link('http://exemplo.com/a'));
        self::assertNull($r->link('javascript:alert(1)'));
        self::assertNull($r->link('exemplo.com'));
        self::assertStringNotContainsString('href', $this->um(['t' => 'botao', 'a' => 'X', 'b' => 'javascript:alert(1)']));
    }

    #[TestDox('Imagem só com https: http ou javascript somem como a imagem sem endereço do dc')]
    public function testImagemSoHttps(): void
    {
        self::assertSame('', $this->um(['t' => 'imagem', 'a' => 'http://img.test/a.png']));
        self::assertSame('', $this->um(['t' => 'imagem', 'a' => 'javascript:alert(1)']));
        self::assertSame('', $this->um(['t' => 'imagem', 'a' => '']));
    }

    #[TestDox('Simples (dc L.484-485, L.499-500): parágrafos, itens com o visto e o botão com o link da chave')]
    public function testSimples(): void
    {
        $html = $this->renderizador()->simples("Olá, {nome}!\n\nSegundo\nparágrafo", ['Item {nome}'], 'Ver vagas', 'vagas');

        self::assertSame(
            '<tr><td style="padding:28px 30px 8px;">'
            . '<p style="margin:0 0 14px;font:15px/1.6 Arial,Helvetica,sans-serif;color:#34505f;">Olá, {nome}!</p>'
            . '<p style="margin:0 0 14px;font:15px/1.6 Arial,Helvetica,sans-serif;color:#34505f;">Segundo<br>parágrafo</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:4px 0 18px;"><tr><td style="padding:6px 0;font:14px/1.5 Arial,Helvetica,sans-serif;color:#243845;"><span style="color:#0a6fa0;font-weight:bold;">&#10003;</span>&nbsp; Item &#123;nome}</td></tr></table>'
            . '</td></tr>'
            . '<tr><td align="center" style="padding:6px 30px 30px;"><a href="https://app.bluejus.test/vagas-juridicas" style="display:inline-block;background:#0a6fa0;color:#ffffff;font:bold 15px Arial,Helvetica,sans-serif;text-decoration:none;padding:14px 26px;border-radius:4px;">Ver vagas</a></td></tr>',
            $html,
        );
        self::assertStringNotContainsString('padding:6px 30px 30px', $this->renderizador()->simples('x', [], '', 'app'), 'sem botão quando não há texto do botão');
    }
}
