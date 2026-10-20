<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Service\VisibilidadeDaAnaliseDaPasta;
use App\Pasta\Entity\AbaDaPasta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A regra única de quem lê uma análise de IA da pasta (ocultar aba, revisão de permissão): as abas
 * de onde a análise tirou conteúdo.
 */
#[CoversClass(VisibilidadeDaAnaliseDaPasta::class)]
final class VisibilidadeDaAnaliseDaPastaTest extends TestCase
{
    /** @param array<string, mixed> $resumo */
    private static function analise(TipoDeAnalise $tipo, ?Agente $agente = null, array $resumo = []): AnaliseDeInteligencia
    {
        return new AnaliseDeInteligencia(new Tenant(), null, $tipo, AnaliseDeInteligencia::ALVO_PASTA, 1, 'v1', 'h', $resumo, $agente);
    }

    /** @return iterable<string, array{AnaliseDeInteligencia, list<string>}> */
    public static function casos(): iterable
    {
        yield 'resumo do Push' => [self::analise(TipoDeAnalise::ResumoPush), ['push']];
        yield 'Processual' => [self::analise(TipoDeAnalise::AnalisePasta, Agente::Processual, ['financeiro' => false]), ['push', 'tarefas']];
        yield 'Gestor com Push oculto na solicitação' => [
            self::analise(TipoDeAnalise::AnalisePasta, Agente::Gestor, ['financeiro' => true, 'abasOcultas' => ['push']]),
            ['tarefas', 'dados', 'detalhes', 'documentos', 'financeiro'],
        ];
        yield 'Documental: os contratos entram com o Financeiro visível' => [
            self::analise(TipoDeAnalise::AnalisePasta, Agente::Documental, ['financeiro' => false]),
            ['documentos', 'financeiro'],
        ];
        yield 'Documental com o Financeiro oculto na solicitação' => [
            self::analise(TipoDeAnalise::AnalisePasta, Agente::Documental, ['financeiro' => false, 'abasOcultas' => ['financeiro']]),
            ['documentos'],
        ];
        yield 'pergunta livre (F3): como o Gestor que respondeu, menos o que ficou fora na solicitação' => [
            self::analise(TipoDeAnalise::PerguntaLivre, Agente::Gestor, ['financeiro' => false, 'abasOcultas' => ['push']]),
            ['tarefas', 'dados', 'detalhes', 'documentos', 'financeiro'],
        ];
        yield 'Relatórios sem o financeiro liberado' => [
            self::analise(TipoDeAnalise::AnalisePasta, Agente::Relatorios, ['financeiro' => false]),
            ['push', 'tarefas', 'detalhes'],
        ];
    }

    /** @param list<string> $esperado */
    #[DataProvider('casos')]
    #[TestDox('abas da análise: $_dataName')]
    public function testAbasDaAnalise(AnaliseDeInteligencia $analise, array $esperado): void
    {
        self::assertSame($esperado, array_map(static fn (AbaDaPasta $a): string => $a->value, VisibilidadeDaAnaliseDaPasta::abasDaAnalise($analise)));
    }

    #[TestDox('legível só se nenhuma aba de onde a análise tirou conteúdo está oculta para quem lê')]
    public function testLegivelCom(): void
    {
        $gestor = self::analise(TipoDeAnalise::AnalisePasta, Agente::Gestor, ['financeiro' => true, 'abasOcultas' => ['push']]);

        self::assertTrue(VisibilidadeDaAnaliseDaPasta::legivelCom($gestor, []));
        self::assertTrue(VisibilidadeDaAnaliseDaPasta::legivelCom($gestor, ['push']), 'o Push ficou de fora da análise');
        self::assertFalse(VisibilidadeDaAnaliseDaPasta::legivelCom($gestor, ['financeiro']));
        self::assertFalse(VisibilidadeDaAnaliseDaPasta::legivelCom(self::analise(TipoDeAnalise::ResumoPush), ['push']));
        self::assertFalse(VisibilidadeDaAnaliseDaPasta::legivelCom(self::analise(TipoDeAnalise::PerguntaLivre), ['dados']), 'sem seção declarada: todas as abas');
    }
}
