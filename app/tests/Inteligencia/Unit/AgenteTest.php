<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Enum\SecaoDoContexto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(Agente::class)]
#[CoversClass(SecaoDoContexto::class)]
final class AgenteTest extends TestCase
{
    #[TestDox('são os sete agentes do desenho, nesta ordem')]
    public function testOsSeteAgentesDoDesenho(): void
    {
        self::assertSame(
            ['gestor', 'processual', 'documental', 'prazos', 'relatorios', 'cliente', 'juridico'],
            array_map(static fn (Agente $a): string => $a->value, Agente::cases()),
        );
    }

    /** @return iterable<string, array{Agente}> */
    public static function agentes(): iterable
    {
        foreach (Agente::cases() as $agente) {
            yield $agente->value => [$agente];
        }
    }

    #[DataProvider('agentes')]
    #[TestDox('$_dataName tem nome, papel, ícone, pedido e ao menos uma seção')]
    public function testCadaAgenteEstaCompleto(Agente $agente): void
    {
        self::assertStringStartsWith('Agente ', $agente->nome());
        self::assertNotSame('', trim($agente->papel()));
        self::assertStringStartsWith('bi-', $agente->icone());
        self::assertGreaterThan(40, mb_strlen($agente->pedido()), 'o pedido é o comando do desenho, não um rótulo');
        self::assertNotSame([], $agente->secoes());
        self::assertSame($agente, Agente::deRota($agente->value));
    }

    #[TestDox('deRota aceita caixa e espaços e devolve null para agente desconhecido')]
    public function testDeRota(): void
    {
        self::assertSame(Agente::Juridico, Agente::deRota(' JURIDICO '));
        self::assertNull(Agente::deRota('chefe'));
        self::assertNull(Agente::deRota(''));
    }

    #[TestDox('o Gestor lê tudo; Processual e Prazos só movimentações e metas; Documental só documentos e checklist')]
    public function testSecoesPorAgente(): void
    {
        self::assertSame(SecaoDoContexto::cases(), Agente::Gestor->secoes());
        self::assertSame([SecaoDoContexto::Movimentacoes, SecaoDoContexto::Metas], Agente::Processual->secoes());
        self::assertSame([SecaoDoContexto::Movimentacoes, SecaoDoContexto::Metas], Agente::Prazos->secoes());
        self::assertSame([SecaoDoContexto::Documentos, SecaoDoContexto::Checklist], Agente::Documental->secoes());
    }

    #[TestDox('só Gestor, Relatórios e Cliente leem o financeiro')]
    public function testQuemLeFinanceiro(): void
    {
        $leem = array_values(array_filter(Agente::cases(), static fn (Agente $a): bool => $a->leFinanceiro()));

        self::assertSame([Agente::Gestor, Agente::Relatorios, Agente::Cliente], $leem);
    }

    #[TestDox('o Documental avisa no pedido que o conteúdo dos arquivos não foi lido')]
    public function testDocumentalNaoFingeLerArquivos(): void
    {
        self::assertStringContainsString('não foi lido', Agente::Documental->pedido());
        self::assertStringContainsString('conteúdo dos arquivos NÃO foi lido', SecaoDoContexto::Documentos->titulo());
    }

    #[TestDox('a seção Financeiro é a única condicionada à visibilidade')]
    public function testSoFinanceiroExigeVisibilidade(): void
    {
        foreach (SecaoDoContexto::cases() as $secao) {
            self::assertSame($secao === SecaoDoContexto::Financeiro, $secao->exigeVisibilidadeDoFinanceiro(), $secao->value);
            self::assertSame($secao->value, $secao->tag());
            self::assertNotSame('', $secao->rotuloCurto());
        }
    }
}
