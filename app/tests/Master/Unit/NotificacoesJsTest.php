<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Regras da tela da Central de Notificações lidas no texto do script (a aparência é do smoke do dono). */
final class NotificacoesJsTest extends TestCase
{
    #[TestDox('"Abrir Indicações" só no alerta do Indicar o BlueJus, não em todo alerta (consumo, exclusão de conta)')]
    public function testLinkDeIndicacoesSoNaOrigemDaIndicacao(): void
    {
        $js = (string) file_get_contents(__DIR__ . '/../../../public/js/master/notificacoes.js');

        self::assertStringContainsString("\$('[data-nf-ir-ind]').hidden = m.origem !== 'alerta_indicacao';", $js);
        self::assertStringNotContainsString("\$('[data-nf-ir-ind]').hidden = m.tipo !== 'alerta';", $js);
    }
}
