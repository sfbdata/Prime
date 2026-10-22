<?php

declare(strict_types=1);

namespace App\Tests\Central\Unit;

use App\Central\Service\TiposDeAvisoDaCentral;
use App\Entity\Notificacao;
use App\Tarefa\UseCase\LembrarMetaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(TiposDeAvisoDaCentral::class)]
final class TiposDeAvisoDasMetasTest extends TestCase
{
    #[TestDox('Lembrete da meta (04B L133, tipo "lembrete" sem par no desenho da Central): grupo Metas, sino, âmbar, nível 2')]
    public function testLembrete(): void
    {
        self::assertSame(
            ['grupo' => 'metas', 'rotulo' => 'Lembrete', 'icone' => 'bi-bell', 'cor' => '#c8952a', 'nivel' => 2],
            (new TiposDeAvisoDaCentral())->para(LembrarMetaUseCase::TIPO_NOTIFICACAO),
        );
    }

    #[TestDox('Menção no despacho da meta usa o tipo da menção do Registro: aparece como "Menção" no grupo Menções')]
    public function testMencaoNoDespacho(): void
    {
        self::assertSame('Menção', (new TiposDeAvisoDaCentral())->para(Notificacao::TIPO_PASTA_MENCAO_REGISTRO)['rotulo']);
    }
}
