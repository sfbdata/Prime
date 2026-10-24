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

    #[TestDox('D8: o DESPACHO entra no Resumo depois de DOCUMENTOS, com os textos, os toasts e o contador de dias úteis do desenho (l.512 a 530, 1391 a 1394)')]
    public function testDespachoNoResumo(): void
    {
        self::assertMatchesRegularExpression("/Nenhum documento anexado\\.' \\}\\)\\);\\s*\\}\\s*if \\(dz\\.despacho\\) \\{\\s*despachoDoResumo\\(alvo, dz\\);/", $this->funcao('abaResumo'));
        $tela = $this->funcao('despachoDoResumo') . $this->funcao('formularioDoDespacho') . $this->funcao('itemDoDespacho');
        foreach ([
            "'DESPACHO'", "' Respostas liberadas'", "' Os despachos são publicados pela Central BlueJus.'",
            "'Responder ao despacho. A Central BlueJus recebe na hora.'", "'Nenhum despacho nesta demanda.'",
            "'O solicitante e o profissional podem responder. Clique para bloquear.'",
            "'Permitir que o solicitante e o profissional respondam aos despachos'", "'Aceitar resposta'", "'Extrato'",
            "'Natureza'", "'Prazo'", "'Escreva o despacho. Ele fica visível para o solicitante e para a equipe.'",
            "' / 5.000'", "'Anexar arquivo'", "'Despachar'", 'maxLength: 5000', 'maxLength: 3000',
        ] as $texto) {
            self::assertStringContainsString($texto, $tela, $texto);
        }
        self::assertStringContainsString("mostrarToast({ tit: 'Escreva o despacho', txt: 'O texto do despacho está vazio.', hora: '' });", $this->funcao('despachar'));
        self::assertStringContainsString("toastDoServidor(r.toast, 'Despacho publicado', 'O solicitante vê o despacho no Resumo e recebe um aviso.');", $this->funcao('despachar'));
        self::assertStringContainsString("if (previa() || t.length < 2) {", $this->funcao('responderAoDespacho'));
        $uteis = $this->funcao('diasUteisDoDespacho');
        self::assertStringContainsString("return '5 dias úteis';", $uteis);
        self::assertStringContainsString("d.getDay() % 6 && feriados.indexOf(d.toLocaleDateString('sv-SE')) < 0", $uteis, 'P5: os feriados do sistema não contam');
        self::assertStringContainsString("(n === 1 ? ' dia útil' : ' dias úteis')", $uteis);

        $css = (string) file_get_contents(__DIR__ . '/../../../public/css/trabalho-externo.css');
        foreach ([
            '.te-dp-cab { margin: 18px -2px 0; border-top: 1px solid #e6edf2; background: #f5f8fa; padding: 12px 12px 2px; }',
            '.te-dp-nat.is-on { border-color: #0a6fa0; background: #0a6fa0; color: #fff; }',
            '.te-dp-item { border: 1px solid #dde5eb; border-left: 3px solid #0a6fa0; border-radius: 2px; background: #fff; padding: 12px 14px; margin-top: 10px; margin-left: 0; }',
            '.te-dp-item.is-resposta { border-left-color: #1e7b45; background: #f6fbf8; margin-left: 24px; }',
            '.te-dp-chave-trilho.is-on { background: #e8703a; }',
            '.te-dp-chave-trilho.is-on > .te-dp-chave-bola { left: 19px; }',
        ] as $regra) {
            self::assertStringContainsString($regra, $css, $regra);
        }
    }
}
