<?php

declare(strict_types=1);

namespace App\Tests\Central\Unit;

use App\Central\Service\TiposDeAvisoDaCentral;
use App\Entity\Notificacao;
use App\Profile\Service\SilencioDoSininho;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(TiposDeAvisoDaCentral::class)]
final class TiposDeAvisoDaPastaAcompanhadaTest extends TestCase
{
    #[TestDox('Pasta acompanhada ("Acompanhar alterações", desenho 02): grupo Mensagens, rótulo próprio, sino, azul, nível 1')]
    public function testPastaAcompanhada(): void
    {
        self::assertSame(
            ['grupo' => 'mensagens', 'rotulo' => 'Pasta acompanhada', 'icone' => 'bi-bell', 'cor' => '#0a7aad', 'nivel' => 1],
            (new TiposDeAvisoDaCentral())->para(Notificacao::TIPO_PASTA_ACOMPANHADA),
        );
    }

    #[TestDox('Aparece na Caixa da Central (o "Chat I.A" do desenho) e respeita o silêncio do sininho')]
    public function testEntraNaCaixaENaoFuraOSilencio(): void
    {
        self::assertNotContains(Notificacao::TIPO_PASTA_ACOMPANHADA, TiposDeAvisoDaCentral::FORA_DA_CAIXA);
        self::assertNotContains(Notificacao::TIPO_PASTA_ACOMPANHADA, SilencioDoSininho::SEMPRE_AVISA);
    }
}
