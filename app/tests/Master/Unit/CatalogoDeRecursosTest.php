<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\Service\CatalogoDeRecursos;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Catálogo fixo das telas e recursos (spec perfis-1.2-telas-e-recursos, R1, R3, R8 e §4).
 */
#[CoversClass(CatalogoDeRecursos::class)]
final class CatalogoDeRecursosTest extends TestCase
{
    #[TestDox('19 linhas, 18 chaves (Contribua e o atalho dividem a chave) e 2 essenciais')]
    public function testTamanhoDoCatalogo(): void
    {
        self::assertCount(19, CatalogoDeRecursos::LINHAS);
        self::assertCount(18, CatalogoDeRecursos::chaves());
        self::assertSame(2, count(array_filter(CatalogoDeRecursos::LINHAS, static fn (array $l): bool => $l['base'])));
        self::assertTrue(CatalogoDeRecursos::ehEssencial('lista'));
        self::assertTrue(CatalogoDeRecursos::ehEssencial('meuperfil'));
        self::assertFalse(CatalogoDeRecursos::ehEssencial('dashboard'));
        self::assertSame(['contribua', 'contribua'], array_values(array_map(
            static fn (array $l): string => $l['chave'],
            array_filter(CatalogoDeRecursos::LINHAS, static fn (array $l): bool => str_starts_with($l['t'], 'Contribua')),
        )));
    }

    #[TestDox('grupos e ordem do bj-recursos.js: 10 Telas, 7 Menu do usuário, 2 Funções')]
    public function testGruposEOrdem(): void
    {
        $porGrupo = array_count_values(array_column(CatalogoDeRecursos::LINHAS, 'g'));
        self::assertSame(['Telas' => 10, 'Menu do usuário' => 7, 'Funções' => 2], $porGrupo);
        self::assertSame('dashboard', CatalogoDeRecursos::LINHAS[0]['chave']);
        self::assertSame('assistente', CatalogoDeRecursos::LINHAS[18]['chave']);
        self::assertSame('Contribua', CatalogoDeRecursos::titulo('contribua'), 'título da primeira linha da chave');
        self::assertNull(CatalogoDeRecursos::titulo('inexistente'));
        self::assertFalse(CatalogoDeRecursos::existe('inexistente'));
    }

    #[DataProvider('rotas')]
    #[TestDox('mapa de rotas da §4: $rota => $chave')]
    public function testChaveDaRota(string $rota, ?string $chave): void
    {
        self::assertSame($chave, CatalogoDeRecursos::chaveDaRota($rota));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function rotas(): iterable
    {
        yield 'dashboard' => ['dashboard_index', 'dashboard'];
        yield 'pasta' => ['pasta_show', 'expedientes'];
        yield 'metas' => ['tarefa_minhas', 'metas'];
        yield 'trabalho externo' => ['trabalho_externo_index', 'trabalho'];
        yield 'convite público do trabalho externo fica aberto' => ['trabalho_externo_convite', null];
        yield 'aceite do convite fica aberto' => ['trabalho_externo_convite_aceitar', null];
        yield 'criação de conta pelo convite fica aberta' => ['trabalho_externo_convite_criar_conta', null];
        yield 'reenviar convite (API logada) segue a chave' => ['trabalho_externo_convite_reenviar', 'trabalho'];
        yield 'revogar convite (API logada) segue a chave' => ['trabalho_externo_convite_revogar', 'trabalho'];
        yield 'peticionar' => ['pasta_peticionar_upload', 'peticionar'];
        yield 'novidades' => ['novidades_index', 'novidades'];
        yield 'agentes da pasta' => ['inteligencia_agentes_painel', 'assistente'];
        yield 'lista de pastas (essencial) não é mapeada' => ['expediente_index', null];
        yield 'política de privacidade continua aberta' => ['legal_politica_privacidade', null];
        yield 'painel do Master' => ['master_painel', null];
        yield 'sem rota' => ['', null];
    }
}
