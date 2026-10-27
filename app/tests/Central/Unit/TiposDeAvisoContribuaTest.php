<?php

declare(strict_types=1);

namespace App\Tests\Central\Unit;

use App\Central\Service\TiposDeAvisoDaCentral;
use App\Contribua\Service\AvisoAoMaster;
use App\Entity\Notificacao;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(TiposDeAvisoDaCentral::class)]
final class TiposDeAvisoContribuaTest extends TestCase
{
    #[TestDox('Avisos do Contribua são o tipo "Contribua" do desenho: atenção, nível 2, coração vermelho')]
    public function testTipoContribua(): void
    {
        foreach ([AvisoAoMaster::TIPO_SUGESTAO, AvisoAoMaster::TIPO_APOIO, AvisoAoMaster::TIPO_MOTIVO, AvisoAoMaster::TIPO_PEDIDO_DE_ESPACO, AvisoAoMaster::TIPO_APOIO_CONFIRMADO] as $tipo) {
            self::assertSame(
                ['grupo' => 'atencao', 'rotulo' => 'Contribua', 'icone' => 'bi-heart', 'cor' => '#c0392b', 'nivel' => 2],
                (new TiposDeAvisoDaCentral())->para($tipo),
                $tipo,
            );
        }
    }

    #[TestDox('Avisos do Contribua abrem com "Ver sugestão", como no desenho; os demais ficam com o "Abrir" genérico')]
    public function testAcaoVerSugestao(): void
    {
        $tipos = new TiposDeAvisoDaCentral();
        foreach ([AvisoAoMaster::TIPO_SUGESTAO, AvisoAoMaster::TIPO_APOIO, AvisoAoMaster::TIPO_MOTIVO, AvisoAoMaster::TIPO_PEDIDO_DE_ESPACO] as $tipo) {
            self::assertSame('Ver sugestão', $tipos->acao($tipo), $tipo);
        }
        self::assertNull($tipos->acao(Notificacao::TIPO_TAREFA_CRIADA));
        self::assertNull($tipos->acao('tipo_que_nao_existe'));
    }
}
