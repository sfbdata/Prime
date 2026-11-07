<?php

declare(strict_types=1);

namespace App\Tests\ChatIa\Unit;

use App\ChatIa\Prompt\PromptDoChatLivre;
use App\ChatIa\Service\FerramentasDaConsulta;
use App\ChatIa\Service\FerramentasDoCadastro;
use App\Inteligencia\DTO\FerramentaDeLinguagem;
use App\Inteligencia\DTO\PedidoDeLinguagem;
use App\Inteligencia\Service\NeutralizadorDeConteudo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** O prompt do chat com a ferramenta de consulta de prazos e metas (spec `chat-ia-prazos-e-metas`, `chat-livre-v5`). */
#[CoversClass(PromptDoChatLivre::class)]
#[CoversClass(FerramentasDaConsulta::class)]
final class PromptDaConsultaDePrazosTest extends TestCase
{
    /** @param list<FerramentaDeLinguagem> $ferramentas */
    private static function montar(array $ferramentas, string $pergunta = 'tem prazo hoje?'): PedidoDeLinguagem
    {
        return (new PromptDoChatLivre(new MockClock('2026-11-07 12:00:00')))->montar('Ana', [['papel' => 'usuario', 'conteudo' => 'oi'], ['papel' => 'assistente', 'conteudo' => 'Olá!']], $pergunta, [], [], [], 2000, null, $ferramentas);
    }

    #[TestDox('Versão v5 e o bloco <prazos_e_metas> é dado não confiável e delimitador do neutralizador')]
    public function testVersaoEDelimitador(): void
    {
        self::assertSame('chat-livre-v5', PromptDoChatLivre::VERSAO);
        self::assertSame('chat_livre/chat-livre-v5', self::montar([])->rotuloDeUso);
        self::assertStringContainsString('<prazos_e_metas>', self::montar([])->sistema);
        self::assertContains('prazos_e_metas', NeutralizadorDeConteudo::DELIMITADORES);
    }

    #[TestDox('Só com a ferramenta de consulta: entra o parágrafo CONSULTA e não o de CADASTRO')]
    public function testSoConsulta(): void
    {
        $pedido = self::montar([FerramentasDaConsulta::prazosEMetas()]);

        self::assertStringContainsString('CONSULTA DE PRAZOS E METAS:', $pedido->sistema);
        self::assertStringNotContainsString('CADASTRO:', $pedido->sistema);
        self::assertSame([FerramentasDaConsulta::PRAZOS_E_METAS], array_map(static fn (FerramentaDeLinguagem $f): string => $f->nome, $pedido->ferramentas));
    }

    #[TestDox('Só com as de cadastro: entra CADASTRO e não CONSULTA; sem ferramenta, nenhum dos dois')]
    public function testSoCadastroOuNada(): void
    {
        $cadastro = self::montar([FerramentasDoCadastro::cliente()]);
        $nada = self::montar([]);

        self::assertStringContainsString('CADASTRO:', $cadastro->sistema);
        self::assertStringNotContainsString('CONSULTA DE PRAZOS E METAS:', $cadastro->sistema);
        self::assertStringNotContainsString('CADASTRO:', $nada->sistema);
        self::assertStringNotContainsString('CONSULTA DE PRAZOS E METAS:', $nada->sistema);
    }

    #[TestDox('A ferramenta só aceita período e papel, com o período obrigatório e nada além')]
    public function testEsquemaDaFerramenta(): void
    {
        $ferramenta = FerramentasDaConsulta::prazosEMetas();
        $esquema = $ferramenta->esquemaDaEntrada;

        self::assertSame('consultar_prazos_e_metas', $ferramenta->nome);
        self::assertSame(['periodo'], $esquema['required']);
        self::assertFalse($esquema['additionalProperties']);
        self::assertSame(['periodo', 'papel'], array_keys($esquema['properties']));
        self::assertSame(['hoje', 'proximos_7_dias', 'atrasadas'], $esquema['properties']['periodo']['enum']);
        self::assertSame(['responsavel', 'criei', 'acompanhando', 'todas'], $esquema['properties']['papel']['enum']);
    }

    #[TestDox('A segunda chamada: blocos antes da pergunta, CONSULTA FEITA no sistema, histórico igual e sem ferramentas')]
    public function testComResultadoDaConsulta(): void
    {
        $prompt = new PromptDoChatLivre(new MockClock('2026-11-07 12:00:00'));
        $primeiro = self::montar([FerramentasDaConsulta::prazosEMetas(), FerramentasDoCadastro::cliente()]);

        $segundo = $prompt->comResultadoDaConsulta($primeiro, ["PRAZOS E METAS DA PESSOA:\n<prazos_e_metas>\n• A\n</prazos_e_metas>", "PRAZOS E METAS DA PESSOA:\n<prazos_e_metas>\n• B\n</prazos_e_metas>"]);

        self::assertSame([], $segundo->ferramentas);
        self::assertSame($primeiro->sistema . "\n", substr($segundo->sistema, 0, \strlen($primeiro->sistema) + 1));
        self::assertStringContainsString('CONSULTA FEITA:', $segundo->sistema);
        self::assertStringContainsString('Nesta resposta não há ferramentas.', $segundo->sistema);
        self::assertSame(\array_slice($primeiro->mensagens, 0, -1), \array_slice($segundo->mensagens, 0, -1), 'o histórico não muda');
        $ultima = $segundo->mensagens[\count($segundo->mensagens) - 1]['conteudo'];
        self::assertStringStartsWith("PRAZOS E METAS DA PESSOA:\n<prazos_e_metas>\n• A", $ultima);
        self::assertStringEndsWith("</prazos_e_metas>\n\nMENSAGEM DA PESSOA:\ntem prazo hoje?", $ultima);
        self::assertSame(1, substr_count($ultima, 'MENSAGEM DA PESSOA:'), 'o rótulo não se repete');
        self::assertSame([$primeiro->maxTokens, $primeiro->rotuloDeUso, $primeiro->modelo], [$segundo->maxTokens, $segundo->rotuloDeUso, $segundo->modelo]);
    }

    #[TestDox('Nenhum texto do sistema novo usa travessão')]
    public function testSemTravessao(): void
    {
        $segundo = (new PromptDoChatLivre(new MockClock('2026-11-07')))->comResultadoDaConsulta(self::montar([FerramentasDaConsulta::prazosEMetas()]), []);

        self::assertDoesNotMatchRegularExpression('/[\x{2014}\x{2013}]/u', $segundo->sistema . FerramentasDaConsulta::prazosEMetas()->descricao);
    }
}
