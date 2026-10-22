<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A Fase 3 com os valores dos estilos inline do dc (desenhos 04 e 04B de 22/10): trilho (04B L43-44), lateral (04B
 * L79-81), botão do cartão (04 L102), modal (04 L131, menu 04B L93) e devolução (04B L106). Os tokens só existem para
 * o tema escuro e têm, no claro, o valor do desenho.
 */
#[CoversNothing]
#[Group('tarefa')]
final class MetaPassagemCssTest extends TestCase
{
    private const CSS = __DIR__ . '/../../../public/css/meta-passagem.css';

    private function regra(string $seletor): string
    {
        $css = (string) file_get_contents(self::CSS);
        self::assertSame(1, preg_match('/^' . preg_quote($seletor, '/') . '\s*\{([^}]*)\}/m', $css, $m), "regra {$seletor} não encontrada");

        return $m[1];
    }

    #[TestDox('Tokens do tema claro com o valor do desenho; o tema escuro troca')]
    public function testTokens(): void
    {
        $raiz = $this->regra(':root');
        foreach (['--mp-fundo: #fff;', '--mp-borda: #d3dde4;', '--mp-borda-leve: #dfe7ed;', '--mp-divisa: #eef2f5;', '--mp-texto: #34505f;', '--mp-texto-2: #4a6373;', '--mp-suave: #5b7383;', '--mp-mudo: #7a8e9b;', '--mp-apagado: #9aabb7;', '--mp-seg-fundo: #e6edf2;'] as $d) {
            self::assertStringContainsString($d, $raiz);
        }
        self::assertStringContainsString('--mp-fundo:', $this->regra('[data-bs-theme="dark"]'));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function regras(): iterable
    {
        yield 'trilho (04B L42)' => ['.mp-trilho', ['border: 1px solid var(--mp-borda-leve);', 'border-radius: 4px;', 'padding: 12px 14px;', 'gap: 6px;', 'flex-wrap: wrap;']];
        yield 'rótulo do trilho (04B L43)' => ['.mp-trilho > .mp-trilho-rotulo', ['font-size: 11px;', 'letter-spacing: 1.2px;', 'margin-right: 6px;']];
        yield 'etapa do trilho (04B L44)' => ['.mp-trilho > .mp-trilho-etapa', ['height: 28px;', 'padding: 0 10px;', 'border-radius: 3px;', 'font-size: 12px;', 'font-weight: bold;', 'gap: 5px;']];
        yield 'atual (04B L138)' => ['.mp-trilho > .mp-trilho-etapa.is-atual', ['background: #0a6fa0;', 'color: #fff;']];
        yield 'saltada (04B L138)' => ['.mp-trilho > .mp-trilho-etapa.is-saltada', ['color: #c0392b;', 'border: 1px dashed #e3a3a3;', 'text-decoration: line-through;']];
        yield 'concluir da lateral (04B L79)' => ['.mp-lateral > .mp-concluir', ['height: 40px;', 'background: #0a6fa0;', 'font-size: 14.5px;', 'gap: 8px;', 'justify-content: center;']];
        yield 'hover do concluir (04B L79)' => ['.mp-lateral > .mp-concluir:hover', ['background: #0b5f86;']];
        yield 'próximo passo da lateral (04B L80)' => ['.mp-lateral > .mp-lateral-proximo', ['font-size: 12px;', 'line-height: 1.45;', 'padding: 0 2px;']];
        yield 'devolver da lateral (04B L81)' => ['.mp-lateral > .mp-devolver', ['height: 36px;', 'color: #9a5b00;', 'font-size: 13.5px;', 'gap: 7px;']];
        yield 'hover do devolver (04B L81)' => ['.mp-lateral > .mp-devolver:hover', ['border-color: #c08a12;', 'background: #fffaf0;']];
        yield 'botão do cartão (04 L102)' => ['.mp-cartao-concluir', ['height: 30px;', 'padding: 0 10px;', 'border: 1px solid #bfe3cc;', 'color: #1e7b45;', 'font-size: 12px;', 'gap: 5px;']];
        yield 'hover do botão do cartão' => ['.mp-cartao-concluir:hover', ['background: #e9f6ee;']];
        yield 'caixa do modal (04 L131)' => ['.mp-modal-centro > .mp-modal-caixa', ['width: min(600px, 100%);', 'max-height: 94vh;', 'border-radius: 4px;', 'box-shadow: 0 20px 50px rgba(18, 36, 47, .28);']];
        yield 'caixa do modal na meta (04B L92)' => ['.mp-passagem--meta .mp-modal-centro > .mp-modal-caixa', ['width: min(560px, 100%);', 'max-height: none;']];
        yield 'blocos do modal na meta (04B L94)' => ['.mp-passagem--meta .mp-modal-corpo > .mp-com-proxima', ['gap: 12px;']];
        yield 'próximo passo na lista, sem menu (04 L131)' => ['.mp-caixas > .mp-caixa-proxima', ['padding: 10px 12px;', 'border: 1px solid #cfe3f0;', 'background: #f2f8fc;']];
        yield 'próximo passo na meta, com menu (04B L95)' => ['.mp-passagem--meta .mp-caixas > .mp-caixa-proxima', ['position: relative;', 'padding: 10px 40px 10px 12px;']];
        yield 'campos na meta (04B L98)' => ['.mp-passagem--meta .mp-campo > input', ['font-size: 14px;']];
        yield 'seletor na meta (04B L97)' => ['.mp-passagem--meta .mp-campo-resp > .mp-membros', ['max-height: 220px;']];
        yield 'membro sem avatar (04B L97)' => ['.mp-membros > .mp-membro.is-coluna', ['flex-direction: column;', 'align-items: flex-start;']];
        yield 'foto do membro (04 L131)' => ['.mp-membro > .mp-membro-foto', ['width: 28px;', 'border-radius: 50%;', 'background: #0a6fa0;', 'background-size: cover;']];
        yield 'faixa atual (04 L197)' => ['.mp-seq > .mp-seq-etapa.is-atual', ['background: #1e7b45;']];
        yield 'faixa próxima (04 L197)' => ['.mp-seq > .mp-seq-etapa.is-proxima', ['background: #0a6fa0;']];
        yield 'faixa passada (04 L197)' => ['.mp-seq > .mp-seq-etapa.is-passada', ['background: #c9d3da;']];
        yield 'menu (04B L93)' => ['.mp-menu > .mp-menu-caixa', ['width: 240px;', 'box-shadow: 0 10px 26px rgba(18, 36, 47, .18);', 'padding: 4px 0;']];
        yield 'tipo ativo (04 L196)' => ['.mp-tipos > .mp-tipo-botao.is-ativo', ['color: #0a6fa0;', 'box-shadow: 0 1px 3px rgba(18, 36, 47, .12);']];
        yield 'confirmar (04 L131)' => ['.mp-modal-pe > .mp-confirmar', ['height: 36px;', 'padding: 0 16px;', 'background: #1e7b45;', 'font-size: 14px;']];
        yield 'motivo escolhido (04B L166)' => ['.mp-dev-motivos > .mp-dev-motivo.is-ativo', ['border-color: #c08a12;', 'background: #fff8e6;', 'color: #7a5200;']];
        yield 'aviso (04 L131 fim)' => ['.mp-toast', ['bottom: 22px;', 'background: #12242f;', 'font-size: 13.5px;', 'padding: 10px 16px;']];
    }

    /** @param list<string> $declaracoes */
    #[DataProvider('regras')]
    #[TestDox('Valores do dc: $_dataName')]
    public function testValoresDoDesenho(string $seletor, array $declaracoes): void
    {
        $regra = $this->regra($seletor);
        foreach ($declaracoes as $d) {
            self::assertStringContainsString($d, $regra);
        }
    }
}
