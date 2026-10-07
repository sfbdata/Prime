<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit;

use App\Dashboard\DTO\PreferenciasDoDashboardOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O estilo completo que vai para o `.db-page`: padrão quando nada foi gravado, classes por escolha,
 * e linha antiga/estragada no banco caindo no padrão em vez de quebrar a tela.
 */
#[CoversClass(PreferenciasDoDashboardOutput::class)]
final class PreferenciasDoDashboardOutputTest extends TestCase
{
    #[TestDox('Sem nada gravado: o padrão, e NENHUMA classe no .db-page (a tela de antes do menu)')]
    public function testPadraoSemClasse(): void
    {
        $prefs = PreferenciasDoDashboardOutput::padrao();

        self::assertSame('', $prefs->classesCss());
        self::assertSame([
            'dashboard.densidade'       => 'compacta',
            'dashboard.animacoes'       => 'ligadas',
            'dashboard.setas'           => 'ligadas',
            'dashboard.colunas_ocultas' => [],
            'dashboard.sons'            => 'ligados',
            'dashboard.colunas_extras'  => [],
        ], $prefs->paraArray());
    }

    #[TestDox('Cada escolha vira a sua classe no .db-page')]
    public function testClassesPorEscolha(): void
    {
        $prefs = PreferenciasDoDashboardOutput::deValores([
            'dashboard.densidade'       => 'confortavel',
            'dashboard.animacoes'       => 'reduzidas',
            'dashboard.setas'           => 'desligadas',
            'dashboard.colunas_ocultas' => ['cargo', 'prazos'],
            'dashboard.sons'            => 'desligados',
        ]);

        self::assertSame(
            'db-page--confortavel db-page--sem-anim db-page--sem-setas db-page--sem-som db-oculta--cargo db-oculta--prazos',
            $prefs->classesCss(),
        );
    }

    #[TestDox('Valor gravado que saiu do catálogo cai no padrão daquela chave, sem derrubar as outras')]
    public function testValorEstragadoCaiNoPadrao(): void
    {
        $prefs = PreferenciasDoDashboardOutput::deValores([
            'dashboard.densidade'       => 'gigante',
            'dashboard.animacoes'       => 'reduzidas',
            'dashboard.colunas_ocultas' => ['salario'],
            'dashboard.sons'            => true,
            'dashboard.zerar'           => true,
        ]);

        self::assertSame('compacta', $prefs->densidade);
        self::assertSame('reduzidas', $prefs->animacoes);
        self::assertSame([], $prefs->colunasOcultas);
        self::assertSame('ligados', $prefs->sons, 'o booleano do protótipo cai no padrão (ligados)');
        self::assertArrayNotHasKey('dashboard.zerar', $prefs->paraArray());
        self::assertSame('db-page--sem-anim', $prefs->classesCss());
    }

    #[TestDox('Colunas extras gravadas voltam na ordem do usuário e não viram classe (o servidor desenha a coluna)')]
    public function testColunasExtrasNaOrdemSemClasse(): void
    {
        $prefs = PreferenciasDoDashboardOutput::deValores([
            'dashboard.colunas_extras' => ['tempo_medio', 'metas_concluidas'],
        ]);

        self::assertSame(['tempo_medio', 'metas_concluidas'], $prefs->colunasExtras);
        self::assertSame(['tempo_medio', 'metas_concluidas'], $prefs->paraArray()['dashboard.colunas_extras']);
        self::assertSame('', $prefs->classesCss());
    }

    #[TestDox('Lista de extras gravada com coluna que saiu do catálogo (ex.: "Pastas concluídas") cai no padrão: nenhuma extra')]
    public function testColunasExtrasEstragadasCaemNoPadrao(): void
    {
        $prefs = PreferenciasDoDashboardOutput::deValores([
            'dashboard.colunas_extras' => ['metas_concluidas', 'pastas_concluidas'],
        ]);

        self::assertSame([], $prefs->colunasExtras);
    }
}
