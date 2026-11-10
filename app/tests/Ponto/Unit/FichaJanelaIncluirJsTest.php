<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A janela "Incluir batida" da ficha do colaborador (E10) abre pelo `abrirModalAddBatida`, no script de
 * `tenant/edit_user_role.html.twig`. O PHPUnit não executa script: este teste trava o que a janela faz ao abrir
 * (o smoke no navegador é do dono).
 */
#[Group('ponto')]
#[CoversNothing]
final class FichaJanelaIncluirJsTest extends TestCase
{
    #[TestDox('Incluir batida: abre com o dia e o tipo do "+" e com a hora e o motivo vazios, a cada abertura')]
    public function testMotivoComecaVazio(): void
    {
        $ficha = self::ficha();
        $abrir = self::trecho($ficha, 'window.abrirModalAddBatida = function (tipo, chaveDia) {', 'modal.show();');

        foreach (['modalAddData', 'modalAddTipo', 'modalAddHora', 'modalAddMotivo'] as $id) {
            self::assertSame(1, substr_count($ficha, 'id="' . $id . '"'), 'sem o campo ' . $id . ' a janela não abre (getElementById devolve null)');
        }
        self::assertStringContainsString("document.getElementById('modalAddData').value = chaveDia;", $abrir);
        self::assertStringContainsString("document.getElementById('modalAddTipo').value = tipo;", $abrir);
        self::assertStringContainsString("document.getElementById('modalAddHora').value = '';", $abrir, 'a hora de uma janela cancelada levaria a incluir no horário errado');
        self::assertStringContainsString("document.getElementById('modalAddMotivo').value = '';", $abrir, 'o motivo da inclusão anterior não pode reaparecer na próxima');
    }

    private static function ficha(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../templates/tenant/edit_user_role.html.twig');
    }

    private static function trecho(string $fonte, string $inicio, string $fim): string
    {
        $de  = strpos($fonte, $inicio);
        self::assertIsInt($de, $inicio);
        $ate = strpos($fonte, $fim, $de);
        self::assertIsInt($ate, $fim);

        return substr($fonte, $de, $ate - $de);
    }
}
