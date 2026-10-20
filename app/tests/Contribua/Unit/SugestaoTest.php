<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit;

use App\Contribua\Entity\MotivoDeNaoApoiar;
use App\Contribua\Entity\Sugestao;
use App\Contribua\Enum\TipoDeSugestao;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(Sugestao::class)]
#[CoversClass(MotivoDeNaoApoiar::class)]
final class SugestaoTest extends TestCase
{
    #[TestDox('Menos de 8 caracteres é recusado com o texto do desenho')]
    public function testTextoCurto(): void
    {
        $this->expectExceptionMessage('Escreva um pouco mais para entendermos a sua sugestão.');

        new Sugestao(new Tenant(), null, 'Ana', TipoDeSugestao::Sugestao, '  curto  ');
    }

    #[TestDox('Marcar atendida guarda a resposta; reabrir apaga')]
    public function testAtenderEReabrir(): void
    {
        $sugestao = new Sugestao(new Tenant(), null, 'Ana', TipoDeSugestao::Falta, 'Falta exportar a agenda');
        $master = new User();

        $sugestao->alternarAtendimento('  Entrou na versão 2.4  ', $master);
        self::assertTrue($sugestao->isAtendida());
        self::assertSame('Entrou na versão 2.4', $sugestao->getResposta());

        $sugestao->alternarAtendimento(null, $master);
        self::assertFalse($sugestao->isAtendida());
        self::assertNull($sugestao->getResposta());
    }

    #[TestDox('Atender sem resposta não grava resposta vazia')]
    public function testAtenderSemResposta(): void
    {
        $sugestao = new Sugestao(new Tenant(), null, 'Ana', TipoDeSugestao::Problema, 'O filtro some ao voltar');

        $sugestao->alternarAtendimento('   ', new User());

        self::assertTrue($sugestao->isAtendida());
        self::assertNull($sugestao->getResposta());
    }

    #[TestDox('Motivo anônimo: vazio é recusado e o texto livre é cortado em 200')]
    public function testMotivo(): void
    {
        self::assertSame(200, mb_strlen((new MotivoDeNaoApoiar(new Tenant(), str_repeat('á', 300)))->getMotivo()));

        $this->expectException(\DomainException::class);
        new MotivoDeNaoApoiar(new Tenant(), '   ');
    }
}
