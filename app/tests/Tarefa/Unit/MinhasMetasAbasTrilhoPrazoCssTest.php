<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * E5c da frente de fidelidade (`docs/specs/fidelidade-designer-1310.md`): Minhas Metas contra
 * `04 - Minhas Metas 1.0.dc.html` (pacote do Claude Designer de 13/10).
 *
 * - Abas (dc L70, JS `abas`): inativa #34505f 13px normal; ativa fundo branco, texto #0a6fa0 negrito,
 *   sombra 0 1px 3px; ícone na cor do texto; contador ativo #e5f1f7/#0a6fa0, inativo #dfe6eb/#4a6373.
 * - Prazo da linha (JS `prazoCor`/`prazoPeso`): #4a6373 normal; atrasada #a3232b negrito.
 * - Trilho (dc L104-118): "X de Y" 34px negrito; barra #1e7b45 com transição .4s; legenda 12.5px;
 *   ícone do "Precisa de atenção" #a3232b; descrição 12px #5b7383.
 *
 * Teste de folha não vê cascata: a comparação na tela é a prova (handoff §4, decisão 8).
 */
final class MinhasMetasAbasTrilhoPrazoCssTest extends TestCase
{
    private const CSS = __DIR__ . '/../../../public/css/minhas-metas.css';

    private function css(): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(self::CSS));
    }

    /** Corpos de todas as regras cuja lista de seletores contém exatamente `$seletor`. */
    private function regras(string $seletor): string
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $this->css(), $m, PREG_SET_ORDER);
        $corpos = [];
        foreach ($m as [, $seletores, $corpo]) {
            $lista = array_map(static fn (string $s): string => trim((string) preg_replace('/\s+/', ' ', $s)), explode(',', $seletores));
            if (in_array($seletor, $lista, true)) {
                $corpos[] = $corpo;
            }
        }
        self::assertNotEmpty($corpos, 'Falta a regra ' . $seletor);

        return implode("\n", $corpos);
    }

    private function token(string $nome): string
    {
        self::assertMatchesRegularExpression('/\.mm-page \{[^}]*' . preg_quote($nome, '/') . ':\s*#[0-9a-f]{3,6};/', $this->css(), 'token ' . $nome . ' no tema claro');
        preg_match('/\.mm-page \{[^}]*' . preg_quote($nome, '/') . ':\s*(#[0-9a-f]{3,6});/', $this->css(), $m);

        return $m[1];
    }

    /** @return iterable<string, array{string, string}> */
    public static function tokens(): iterable
    {
        yield 'aba inativa: texto' => ['--mm-aba-texto', '#34505f'];
        yield 'aba ativa: texto' => ['--mm-aba-ativa-texto', '#0a6fa0'];
        yield 'contador inativo: fundo' => ['--mm-aba-badge-bg', '#dfe6eb'];
        yield 'contador inativo: texto' => ['--mm-aba-badge-texto', '#4a6373'];
        yield 'contador ativo: fundo' => ['--mm-aba-badge-ativa-bg', '#e5f1f7'];
        yield 'prazo: texto normal' => ['--mm-prazo-texto', '#4a6373'];
        yield 'prazo: atrasado' => ['--mm-prazo-atrasado', '#a3232b'];
        yield 'trilho: barra de andamento' => ['--mm-faixa-andamento', '#1e7b45'];
        yield 'trilho: descrição do item de atenção' => ['--mm-atencao-desc', '#5b7383'];
    }

    #[DataProvider('tokens')]
    #[TestDox('token do desenho: $nome = $hex')]
    public function testTokens(string $nome, string $hex): void
    {
        self::assertSame($hex, $this->token($nome));
    }

    #[TestDox('aba inativa: 13px, peso normal, cor do token, sem borda; ícone na cor do texto')]
    public function testAbaInativa(): void
    {
        $aba = $this->regras('.mm-aba');
        self::assertMatchesRegularExpression('/font-size:\s*13px;/', $aba);
        self::assertMatchesRegularExpression('/font-weight:\s*400;/', $aba);
        self::assertMatchesRegularExpression('/color:\s*var\(--mm-aba-texto\);/', $aba);
        self::assertMatchesRegularExpression('/height:\s*34px;/', $aba);
        self::assertMatchesRegularExpression('/border:\s*0;/', $aba);
        self::assertMatchesRegularExpression('/color:\s*inherit;/', $this->regras('.mm-aba .bi'));
    }

    #[TestDox('aba ativa: fundo branco, texto #0a6fa0 negrito, sombra 0 1px 3px; contador ativo azul-claro')]
    public function testAbaAtiva(): void
    {
        $ativa = $this->regras('.mm-aba.is-ativa');
        self::assertMatchesRegularExpression('/font-weight:\s*700;/', $ativa);
        self::assertMatchesRegularExpression('/color:\s*var\(--mm-aba-ativa-texto\);/', $ativa);
        self::assertMatchesRegularExpression('/background:\s*var\(--mm-card\);/', $ativa);
        self::assertMatchesRegularExpression('/box-shadow:\s*0 1px 3px rgba\(18,\s*36,\s*47,\s*\.12\);/', $ativa);

        $badge = $this->regras('.mm-aba-badge');
        self::assertMatchesRegularExpression('/font-size:\s*11px;/', $badge);
        self::assertMatchesRegularExpression('/font-weight:\s*700;/', $badge);
        self::assertMatchesRegularExpression('/background:\s*var\(--mm-aba-badge-bg\);/', $badge);
        self::assertMatchesRegularExpression('/background:\s*var\(--mm-aba-badge-ativa-bg\);/', $this->regras('.mm-aba.is-ativa .mm-aba-badge'));
    }

    #[TestDox('prazo da linha: cinza normal; atrasado vermelho negrito')]
    public function testPrazoDaLinha(): void
    {
        $prazo = $this->regras('.mm-prazo');
        self::assertMatchesRegularExpression('/color:\s*var\(--mm-prazo-texto\);/', $prazo);
        self::assertMatchesRegularExpression('/font-size:\s*12\.5px;/', $prazo);
        $atrasado = $this->regras('.mm-prazo.is-atrasado');
        self::assertMatchesRegularExpression('/color:\s*var\(--mm-prazo-atrasado\);/', $atrasado);
        self::assertMatchesRegularExpression('/font-weight:\s*700;/', $atrasado);
    }

    #[TestDox('trilho: "X de Y" 34px negrito; barra verde do desenho com transição .4s; legenda 12.5px')]
    public function testTrilho(): void
    {
        $numero = $this->regras('.mm-progresso-numero');
        self::assertMatchesRegularExpression('/font-size:\s*34px;/', $numero);
        self::assertMatchesRegularExpression('/font-weight:\s*700;/', $numero);

        $barra = $this->regras('.mm-progresso-barra > span');
        self::assertMatchesRegularExpression('/background:\s*var\(--mm-faixa-andamento\);/', $barra);
        self::assertMatchesRegularExpression('/transition:\s*width \.4s;/', $barra);

        self::assertMatchesRegularExpression('/font-size:\s*12\.5px;/', $this->regras('.mm-fatia'));
        self::assertMatchesRegularExpression('/font-size:\s*14px;/', $this->regras('.mm-trilho .mm-cartao-titulo'));
        self::assertMatchesRegularExpression('/color:\s*var\(--mm-faixa-atencao\);/', $this->regras('.mm-atencao-icone'));
        self::assertMatchesRegularExpression('/color:\s*var\(--mm-atencao-desc\);/', $this->regras('.mm-atencao-meta'));
    }
}
