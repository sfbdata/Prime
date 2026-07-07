<?php

declare(strict_types=1);

namespace App\Tests\Djen\Unit;

use App\Djen\Enum\MotivoFalhaDjen;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MotivoFalhaDjen::class)]
final class MotivoFalhaDjenTest extends TestCase
{
    #[Test]
    #[DataProvider('provedorStatusHttp')]
    public function statusHttpMapeiaParaCodigoEsperado(MotivoFalhaDjen $motivo, int $esperado): void
    {
        self::assertSame($esperado, $motivo->statusHttp());
    }

    /**
     * @return iterable<string, array{MotivoFalhaDjen, int}>
     */
    public static function provedorStatusHttp(): iterable
    {
        yield 'nao_configurado' => [MotivoFalhaDjen::NaoConfigurado, 500];
        yield 'indisponivel' => [MotivoFalhaDjen::Indisponivel, 502];
        yield 'timeout' => [MotivoFalhaDjen::Timeout, 504];
        yield 'limite_excedido' => [MotivoFalhaDjen::LimiteExcedido, 429];
        yield 'sistema_ocupado' => [MotivoFalhaDjen::SistemaOcupado, 502];
        yield 'resposta_invalida' => [MotivoFalhaDjen::RespostaInvalida, 502];
    }

    #[Test]
    #[DataProvider('provedorTransitorio')]
    public function ehTransitorioClassificaCorretamente(MotivoFalhaDjen $motivo, bool $esperado): void
    {
        self::assertSame($esperado, $motivo->ehTransitorio());
    }

    /**
     * @return iterable<string, array{MotivoFalhaDjen, bool}>
     */
    public static function provedorTransitorio(): iterable
    {
        yield 'sistema_ocupado transitorio' => [MotivoFalhaDjen::SistemaOcupado, true];
        yield 'limite transitorio' => [MotivoFalhaDjen::LimiteExcedido, true];
        yield 'timeout transitorio' => [MotivoFalhaDjen::Timeout, true];
        yield 'indisponivel transitorio' => [MotivoFalhaDjen::Indisponivel, true];
        yield 'nao_configurado permanente' => [MotivoFalhaDjen::NaoConfigurado, false];
        yield 'resposta_invalida permanente' => [MotivoFalhaDjen::RespostaInvalida, false];
    }

    #[Test]
    public function todaMensagemNaoEhVaziaETodoStatusEhErro(): void
    {
        foreach (MotivoFalhaDjen::cases() as $motivo) {
            self::assertNotSame('', $motivo->mensagemUsuario(), $motivo->value . ' deveria ter mensagem');
            self::assertGreaterThanOrEqual(400, $motivo->statusHttp(), $motivo->value . ' deveria ser >= 400');
        }
    }
}
