<?php

declare(strict_types=1);

namespace App\Tests\ChatIa\Unit;

use App\ChatIa\Prompt\PromptDoChatLivre;
use App\ChatIa\Service\FerramentasDoCadastro;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(PromptDoChatLivre::class)]
final class PromptDoCadastroTest extends TestCase
{
    #[TestDox('Cliente e pasta juntos: o sistema manda UMA chamada de propor_pasta com o cliente dentro')]
    public function testOrientaClienteEPastaEmUmaChamada(): void
    {
        $pedido = (new PromptDoChatLivre(new MockClock('2026-10-29')))->montar(
            'Ana',
            [],
            'cadastre este cliente e abra uma pasta',
            [],
            [],
            [],
            2000,
            null,
            [FerramentasDoCadastro::cliente(), FerramentasDoCadastro::pasta()],
        );

        self::assertStringContainsString('UMA só chamada de propor_pasta com o campo cliente preenchido', $pedido->sistema);
        self::assertStringContainsString('Ação a definir', $pedido->sistema);
    }

    #[TestDox('Sem ferramentas, a regra de cadastro não entra no prompt')]
    public function testSemFerramentasSemRegra(): void
    {
        $pedido = (new PromptDoChatLivre(new MockClock('2026-10-29')))->montar('Ana', [], 'oi', [], [], [], 2000, null);

        self::assertStringNotContainsString('propor_pasta', $pedido->sistema);
    }
}
