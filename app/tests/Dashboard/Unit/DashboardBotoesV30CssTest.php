<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Botões do Dashboard num só formato pelo "Guia visual BlueJus" v30 (pedido A7 de 19/11): 32px, 0 14px, 13px/600 e
 * raio 4px; e o realce azul da linha da tabela sem apagar o número-link nem o troféu. A aparência é do smoke do dono.
 */
final class DashboardBotoesV30CssTest extends TestCase
{
    private function css(string $arquivo): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public/css/' . $arquivo);
    }

    /** @return iterable<string, array{string, string}> */
    public static function botoes(): iterable
    {
        yield 'perguntas da I.A.' => ['dashboard-inteligencia.css', '.db-page .db-ia-pilula { display: inline-flex; align-items: center; height: 32px; padding: 0 14px;'];
        yield 'resolver e ignorar' => ['dashboard-inteligencia.css', '.db-page .db-ia-btn-resolver, .db-page .db-ia-btn-ignorar { height: 32px; padding: 0 14px; border: none; border-radius: 4px; font-size: 13px; font-weight: 600;'];
        yield 'criar tarefa' => ['dashboard-inteligencia.css', '.db-page .db-ia-btn-tarefa { height: 32px; padding: 0 14px;'];
        yield 'retorno sim e não' => ['dashboard-inteligencia.css', '.db-page .db-ia-retorno-btn { height: 32px; padding: 0 14px;'];
        yield 'fechar da pasta' => ['dashboard-inteligencia.css', '.db-page .db-ia-pasta-x { width: 32px; height: 32px; border: none; border-radius: 4px;'];
        yield 'zerar: Destrutivo do guia' => ['dashboard-zerar.css', '.db-zerar-btn--perigo { background: #fdeceb; color: #a3232b; }'];
    }

    #[DataProvider('botoes')]
    #[TestDox('Botão no formato do guia: $_dataName')]
    public function testBotaoNoFormatoDoGuia(string $arquivo, string $regra): void
    {
        self::assertStringContainsString($regra, $this->css($arquivo));
    }

    #[TestDox('Linha da tabela: realce #2b7ab0; troféu mantém o dourado; número-link sob o mouse com fundo translúcido')]
    public function testRealceDaLinhaSemApagarNumeroNemTrofeu(): void
    {
        $css = $this->css('dashboard.css');
        self::assertStringContainsString('--db-row-hover:     #2b7ab0;', $css);
        self::assertStringContainsString('td *:not(.db-trofeu):not(.db-trofeu *) { color: #fff !important; }', $css);
        self::assertStringContainsString('.db-page .db-table tbody tr:hover .db-num.db-num-link:hover,', $css);
    }
}
