<?php

declare(strict_types=1);

namespace App\Tests\Indicacao\Unit;

use App\Central\Service\TiposDeAvisoDaCentral;
use App\Indicacao\Service\AvisoDeDisparoAlto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(TiposDeAvisoDaCentral::class)]
final class AlertaDaIndicacaoNaCentralTest extends TestCase
{
    #[TestDox('"Disparo alto de convites" aparece na Central como o tipo alerta do desenho (bj-contas.js L126)')]
    public function testDisparoAltoEhAlerta(): void
    {
        self::assertSame(
            ['grupo' => 'urgente', 'rotulo' => 'Alerta', 'icone' => 'bi-bell-fill', 'cor' => '#e8590c', 'nivel' => 3],
            (new TiposDeAvisoDaCentral())->para(AvisoDeDisparoAlto::TIPO_NOTIFICACAO),
        );
    }
}
