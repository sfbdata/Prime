<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Central\Service\ItensEncaminhaveisDaPasta;
use App\Dashboard\UseCase\ObterRelatorioDeRegistrosDoColaboradorUseCase;
use App\Pasta\Service\LeitorDeDatas;
use App\Pasta\Service\MencoesDoRegistro;
use App\Tests\Shared\CriaSanitizadorTextoRico;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Botão "Inserir tabela" do editor rico (pedido do Samuel, 04/11/2026): a tabela passa pelo sanitizador e aparece
 * no registro; onde o texto vira texto puro (sino, cartão encaminhado, relatório, leitor de prazos), cada célula
 * fica separada da vizinha, em vez de "PrazoValor".
 */
#[CoversClass(MencoesDoRegistro::class)]
#[CoversClass(ItensEncaminhaveisDaPasta::class)]
#[CoversClass(ObterRelatorioDeRegistrosDoColaboradorUseCase::class)]
#[CoversClass(LeitorDeDatas::class)]
final class TabelaDoEditorRicoNosTextosTest extends TestCase
{
    use CriaSanitizadorTextoRico;

    private const TABELA = '<table><tbody><tr><td data-row="row-1">Prazo</td><td data-row="row-1">05/11/2026</td></tr>'
        . '<tr><td data-row="row-2">Valor</td><td data-row="row-2">R$ 10</td></tr></tbody></table>';

    #[TestDox('o registro exibe a tabela inteira, com as células')]
    public function testRegistroExibeATabela(): void
    {
        $html = (string) (new MencoesDoRegistro($this->criarSanitizadorTextoRico()))->exibir(self::TABELA);

        self::assertStringContainsString('<table><tbody><tr><td data-row="row-1">Prazo</td>', $html);
    }

    #[TestDox('o sino separa as células')]
    public function testSinoSeparaAsCelulas(): void
    {
        self::assertSame('Prazo 05/11/2026 Valor R$ 10', (new MencoesDoRegistro($this->criarSanitizadorTextoRico()))->textoDoSino(self::TABELA));
    }

    #[TestDox('o cartão encaminhado e o relatório separam as células')]
    public function testResumosSeparamAsCelulas(): void
    {
        self::assertSame('Prazo 05/11/2026 Valor R$ 10', ItensEncaminhaveisDaPasta::textoLimpo(self::TABELA));
        self::assertSame('Prazo 05/11/2026 Valor R$ 10', ObterRelatorioDeRegistrosDoColaboradorUseCase::trecho(self::TABELA));
    }

    #[TestDox('o leitor de prazos recebe cada célula numa linha, com a data solta')]
    public function testLeitorDeDatasSeparaAsCelulas(): void
    {
        self::assertStringContainsString("Prazo\n05/11/2026\n", LeitorDeDatas::textoDoHtml(self::TABELA));
    }
}
