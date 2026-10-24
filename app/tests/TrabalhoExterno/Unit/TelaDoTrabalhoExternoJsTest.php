<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O `public/js/trabalho-externo.js` lido como FOLHA (não há navegador no PHPUnit): as decisões do dono
 * sobre o desenho 06 de 24/10 que só existem no JS. Aparência e movimento são smoke do dono.
 */
final class TelaDoTrabalhoExternoJsTest extends TestCase
{
    private const JS = __DIR__ . '/../../../public/js/trabalho-externo.js';

    private function js(): string
    {
        return (string) file_get_contents(self::JS);
    }

    /** O corpo de uma função de primeiro nível do IIFE (4 espaços de recuo), até o fechamento dela. */
    private function funcao(string $nome): string
    {
        $js = $this->js();
        $inicio = strpos($js, '    function ' . $nome . '(');
        self::assertNotFalse($inicio, $nome);
        $fim = strpos($js, "\n    }\n", $inicio);
        self::assertNotFalse($fim, $nome);

        return substr($js, $inicio, $fim - $inicio);
    }

    #[TestDox('P1: a aba "Meu currículo" tem só o cartão compacto e a prévia; "Minhas candidaturas" saiu, com o código dele')]
    public function testSemMinhasCandidaturas(): void
    {
        $meu = $this->funcao('renderMeuCv');
        self::assertSame(2, substr_count($meu, 'alvo.appendChild('), 'o cartão "Meu currículo" e a "Prévia do currículo"');
        self::assertStringContainsString('alvo.appendChild(cartaoPreviaCv(m));', $meu);

        $js = $this->js();
        foreach (['cartaoMinhas', 'loteDasMinhas', 'linhaMinha', 'enviarLoteMinhas', 'marcarMinhaVista', 'minhasFiltradas', 'MC_FILTROS', 'mcSel', 'mcFiltro', "'mcselpor'", "text: 'Minhas candidaturas'", 'te-cand-mc-'] as $saiu) {
            self::assertStringNotContainsString($saiu, $js, $saiu);
        }
        self::assertStringContainsString('function minhas()', $js, 'a lista continua: o convite de vaga não reaparece para quem já se candidatou');
    }

    #[TestDox('P1: o toast da candidatura não manda mais a pessoa a "Minhas candidaturas"')]
    public function testToastDaCandidatura(): void
    {
        self::assertStringNotContainsString('Acompanhe em', $this->js());
        self::assertStringContainsString("toastDoServidor(r.toast, 'Candidatura enviada', '');", $this->js());
    }

    #[TestDox('P2: o mapa da animação de envio é o iframe do Google Maps do desenho (l.740 e 1419), com a cidade e a UF')]
    public function testMapaDoEnvio(): void
    {
        self::assertStringContainsString(
            "return 'https://maps.google.com/maps?q=' + encodeURIComponent((fx.cid || 'Brasília') + ' ' + (fx.uf || '') + ' Brasil') + '&z=12&output=embed';",
            $this->funcao('urlDoMapa'),
        );
        $render = $this->funcao('renderEnvio');
        self::assertStringContainsString("el('iframe', { class: 'te-env-mapa', attrs: { src: src, title: 'Mapa' } })", $render);
        self::assertStringContainsString("mapa.getAttribute('src') === src", $render, 'o mapa não recarrega a cada fase');

        $css = (string) file_get_contents(__DIR__ . '/../../../public/css/trabalho-externo.css');
        self::assertStringContainsString('.te-env-mapa { position: absolute; left: -60px; top: -170px; width: calc(100% + 120px); height: calc(100% + 340px); border: 0; filter: saturate(.85); pointer-events: none; }', $css);
    }

    #[TestDox('D10: um som só, com o lançador da Central na página o toque do aviso é dele')]
    public function testUmSomSo(): void
    {
        self::assertStringContainsString("return document.querySelector('[data-central-flutuante]') !== null;", $this->funcao('centralTocaOsAvisos'));
        self::assertMatchesRegularExpression('/if \(!centralTocaOsAvisos\(\)\) \{\s*tocarAlerta\(\);\s*\}/', $this->funcao('conferirAndamentos'));
        self::assertSame(1, substr_count($this->js(), 'tocarAlerta();'), 'nenhum outro toque do andamento');
    }
}
