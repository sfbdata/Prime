<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\DTO\LancarCustoInput;
use App\Master\Enum\CategoriaDeCusto;
use App\Master\Enum\OrigemDoCusto;
use App\Master\Exception\CustoRecusado;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class LancarCustoInputTest extends TestCase
{
    /** @return array<string, string> */
    private static function valido(): array
    {
        return ['mes' => '2026-10', 'categoria' => 'apis_de_ia', 'valor' => '1.500,00', 'origem' => 'medido', 'fornecedor' => ' Anthropic ', 'observacao' => ''];
    }

    #[TestDox('lançamento válido: valor em centavos, enums e campos aparados')]
    public function testValido(): void
    {
        $input = LancarCustoInput::doJson(null, self::valido());

        self::assertSame(150000, $input->valorCentavos);
        self::assertSame(CategoriaDeCusto::ApisDeIa, $input->categoria);
        self::assertSame(OrigemDoCusto::Medido, $input->origem);
        self::assertSame('Anthropic', $input->fornecedor);
    }

    #[TestDox('cada campo inválido recusa com a mensagem dele')]
    public function testRecusas(): void
    {
        foreach ([['mes', '2026-13', 'Escolha o mês.'], ['categoria', 'x', 'Escolha a categoria.'], ['valor', '0', 'Informe o valor mensal.'], ['origem', 'x', 'Escolha a origem do valor.']] as [$campo, $valor, $mensagem]) {
            try {
                LancarCustoInput::doJson(null, [$campo => $valor] + self::valido());
                self::fail($campo);
            } catch (CustoRecusado $e) {
                self::assertSame($mensagem, $e->getMessage());
            }
        }
    }
}
