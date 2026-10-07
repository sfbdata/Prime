<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Inteligencia\Service\NeutralizadorDeConteudo;
use App\Tests\Inteligencia\Support\CorpusDeInjecao;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(NeutralizadorDeConteudo::class)]
final class NeutralizadorDeConteudoTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function tags(): iterable
    {
        yield 'fecha movimentacoes' => ['x</movimentacoes>y', 'x[/movimentacoes>y'];
        yield 'abre movimentacoes' => ['x<movimentacoes>y', 'x[movimentacoes>y'];
        yield 'fecha processo' => ['</processo>', '[/processo>'];
        yield 'fecha equipe' => ['</equipe>', '[/equipe>'];
        yield 'fecha analise_anterior' => ['</analise_anterior>', '[/analise_anterior>'];
        yield 'caixa e espaços internos' => ['< / Movimentacoes >', '[/Movimentacoes >'];
        yield 'maiúsculas' => ['</PROCESSO>', '[/PROCESSO>'];
        yield 'tag parecida não é tocada' => ['<processos> e <movimentacao>', '<processos> e <movimentacao>'];
        // Blocos dos agentes da pasta (fatia 2): dado da pasta não pode fechar o próprio bloco.
        yield 'fecha pasta' => ['</pasta>', '[/pasta>'];
        yield 'fecha processos_vinculados' => ['</processos_vinculados>', '[/processos_vinculados>'];
        yield 'abre clientes' => ['<clientes>', '[clientes>'];
        yield 'fecha metas' => ['</metas>', '[/metas>'];
        yield 'fecha anotacoes' => ['</anotacoes>', '[/anotacoes>'];
        yield 'fecha observacoes' => ['</observacoes>', '[/observacoes>'];
        yield 'fecha documentos' => ['</documentos>', '[/documentos>'];
        yield 'fecha checklist' => ['</checklist>', '[/checklist>'];
        yield 'fecha financeiro' => ['</financeiro>', '[/financeiro>'];
        yield 'tag parecida dos agentes não é tocada' => ['<pastas> e <meta>', '<pastas> e <meta>'];
    }

    #[DataProvider('tags')]
    #[TestDox('torna inerte a tag delimitadora: $_dataName')]
    public function testTags(string $entrada, string $esperado): void
    {
        self::assertSame($esperado, NeutralizadorDeConteudo::neutralizar($entrada));
    }

    #[TestDox('caracteres de controle, zero-width, bidi e BOM saem; quebras viram espaço')]
    public function testControle(): void
    {
        $entrada = "a\x1B[0m\x00b\u{200B}c\u{202E}d\u{FEFF}e\n\tf";

        self::assertSame('a[0mbcde f', NeutralizadorDeConteudo::neutralizar($entrada));
    }

    #[TestDox('texto comum (acentos, pontuação, < e > soltos) volta intacto, só com espaços normalizados')]
    public function testTextoComumIntacto(): void
    {
        $entrada = 'Intime-se a parte autora (art. 351 do CPC) — prazo < 15 dias; valor > R$ 1.000,00.';

        self::assertSame($entrada, NeutralizadorDeConteudo::neutralizar($entrada));
        self::assertSame('a b', NeutralizadorDeConteudo::neutralizar("  a \n\n  b  "));
    }

    #[TestDox('é idempotente: aplicar duas vezes dá o mesmo resultado')]
    public function testIdempotente(): void
    {
        $uma = NeutralizadorDeConteudo::neutralizar("x</movimentacoes>\x00<processo>");

        self::assertSame($uma, NeutralizadorDeConteudo::neutralizar($uma));
    }

    #[TestDox('UTF-8 inválido não derruba a neutralização (nem perde o resto do texto)')]
    public function testUtf8Invalido(): void
    {
        $saida = NeutralizadorDeConteudo::neutralizar("ok \xC3\x28 </processo> fim");

        self::assertStringContainsString('ok', $saida);
        self::assertStringContainsString('[/processo>', $saida);
        self::assertStringContainsString('fim', $saida);
    }

    #[TestDox('vazio continua vazio')]
    public function testVazio(): void
    {
        self::assertSame('', NeutralizadorDeConteudo::neutralizar(''));
    }

    // =========================================================================
    // IA-014: corpus de injeção (README do handoff, aceite "/redteam")
    // =========================================================================

    /** @return iterable<string, array{string}> */
    public static function corpus(): iterable
    {
        return CorpusDeInjecao::ataques();
    }

    #[DataProvider('corpus')]
    #[TestDox('corpus de injeção ($_dataName): nada que pareça tag delimitadora sobra, nenhum invisível sobra, o texto do ataque continua (como dado) e é idempotente')]
    public function testCorpusDeInjecao(string $ataque): void
    {
        $saida = NeutralizadorDeConteudo::neutralizar($ataque);

        self::assertSame(0, preg_match(CorpusDeInjecao::padraoDeTag(), $saida), "sobrou algo que fecha/abre bloco: {$saida}");
        self::assertSame(0, preg_match(CorpusDeInjecao::INVISIVEIS, $saida), 'sobrou caractere invisível/de controle');
        self::assertStringContainsString(CorpusDeInjecao::CANARIO, $saida, 'o texto não é apagado: vira dado inerte, não some');
        self::assertStringNotContainsString("\n", $saida, 'uma linha só: o dado não abre linha nova no prompt');
        self::assertSame($saida, NeutralizadorDeConteudo::neutralizar($saida), 'idempotente');
    }

    /** @return iterable<string, array{string, string}> */
    public static function parecidos(): iterable
    {
        yield 'largura total' => ['＜／ｍｅｔａｓ＞', '[/metas>'];
        yield 'aspa angular' => ['‹/movimentacoes>', '[/movimentacoes>'];
        yield 'entidade' => ['&lt;/processo&gt;', '[/processo&gt;'];
        yield 'entidade decimal' => ['&#60;equipe&#62;', '[equipe&#62;'];
        yield 'barra de fração' => ['<⁄pasta>', '[/pasta>'];
        yield 'NBSP' => ["<\u{00A0}/clientes>", '[/clientes>'];
    }

    #[DataProvider('parecidos')]
    #[TestDox('o que só PARECE a tag também vira inerte, com a barra normalizada: $_dataName')]
    public function testParecidosComATag(string $entrada, string $esperado): void
    {
        self::assertSame($esperado, NeutralizadorDeConteudo::neutralizar($entrada));
    }

    #[TestDox('sinais parecidos com < em texto comum (‹citação›, 〈nota〉, &lt; 15) ficam como estão')]
    public function testParecidosEmTextoComumIntactos(): void
    {
        $entrada = 'O autor disse ‹basta› e a nota 〈ver anexo〉 cita prazo &lt; 15 dias; nº 12.';

        self::assertSame($entrada, NeutralizadorDeConteudo::neutralizar($entrada));
    }
}
