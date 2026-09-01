<?php

declare(strict_types=1);

namespace App\Tests\Processo\Unit;

use App\Processo\Service\ValidadorNumeroCnj;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ValidadorNumeroCnj::class)]
final class ValidadorNumeroCnjTest extends TestCase
{
    #[DataProvider('numerosValidosProvider')]
    #[TestDox('DV confere em $_dataName')]
    public function testAceitaNumeroComDigitoVerificadorCorreto(string $numero): void
    {
        self::assertTrue((new ValidadorNumeroCnj())->digitoVerificadorConfere($numero));
    }

    /**
     * Números reais (DV conferido pela Res. CNJ 65/2008, módulo 97 base 10).
     *
     * @return iterable<string, array{string}>
     */
    public static function numerosValidosProvider(): iterable
    {
        // O caso que originou esta validação: número válido que o CNJ não tinha na base.
        yield 'TJDFT mascarado'          => ['0782731-84.2026.8.07.0016'];
        yield 'TJDFT puro (20 dígitos)'  => ['07827318420268070016'];
        // Existe na base pública do CNJ (conferido na investigação).
        yield 'TJDFT vizinho indexado'   => ['0774767-40.2026.8.07.0016'];
        yield 'TJAL mascarado'           => ['0710802-55.2018.8.02.0001'];
        // Sequencial alto: o inteiro concatenado passa de 9,2×10^18 (limite do int de 64 bits),
        // então só um resto incremental acerta — (int) silenciosamente vira float e erra.
        yield 'sequencial alto (overflow)' => ['9999999-14.2026.8.07.0016'];
    }

    #[DataProvider('numerosInvalidosProvider')]
    #[TestDox('DV não confere em $_dataName')]
    public function testRejeitaNumeroComDigitoVerificadorErrado(string $numero): void
    {
        self::assertFalse((new ValidadorNumeroCnj())->digitoVerificadorConfere($numero));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function numerosInvalidosProvider(): iterable
    {
        // Mesmo número do provider válido com o DV trocado de 84 para 85.
        yield 'DV alterado (84 → 85)'   => ['0782731-85.2026.8.07.0016'];
        // Número usado nos testes funcionais antigos: parece CNJ, mas o DV nunca conferiu.
        yield 'DV inventado'            => ['0001234-56.2020.8.26.0100'];
        // Um dígito do sequencial trocado (erro de digitação típico) invalida o DV.
        yield 'sequencial com typo'     => ['0782732-84.2026.8.07.0016'];
        yield 'DV 00 (placeholder)'     => ['00000000020208260000'];
    }

    #[DataProvider('foraDoPadraoProvider')]
    #[TestDox('Fora do padrão de 20 dígitos: $_dataName')]
    public function testRejeitaNumeroForaDoPadraoCnj(string $numero): void
    {
        self::assertFalse((new ValidadorNumeroCnj())->digitoVerificadorConfere($numero));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foraDoPadraoProvider(): iterable
    {
        yield 'vazio'            => [''];
        yield 'só texto'         => ['123-nao-e-cnj'];
        yield '19 dígitos'       => ['0782731842026807001'];
        yield '21 dígitos'       => ['078273184202680700160'];
        yield 'número interno'   => ['PROC-2026-001'];
    }

    #[TestDox('Máscara é irrelevante: pontuação não altera o veredito')]
    public function testMascaraNaoAlteraOVeredito(): void
    {
        $validador = new ValidadorNumeroCnj();

        self::assertTrue($validador->digitoVerificadorConfere('0782731-84.2026.8.07.0016'));
        self::assertTrue($validador->digitoVerificadorConfere('07827318420268070016'));
        self::assertTrue($validador->digitoVerificadorConfere(' 0782731 84 2026 8 07 0016 '));
    }
}
