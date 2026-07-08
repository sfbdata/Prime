<?php

declare(strict_types=1);

namespace App\Tests\Processo\Unit;

use App\Processo\Enum\MotivoFalhaDatajud;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(MotivoFalhaDatajud::class)]
final class MotivoFalhaDatajudTest extends TestCase
{
    #[DataProvider('statusProvider')]
    #[TestDox('Motivo $motivo->name mapeia para o status HTTP $status')]
    public function testStatusHttp(MotivoFalhaDatajud $motivo, int $status): void
    {
        self::assertSame($status, $motivo->statusHttp());
    }

    /**
     * @return iterable<string, array{MotivoFalhaDatajud, int}>
     */
    public static function statusProvider(): iterable
    {
        yield 'nao configurado -> 500'  => [MotivoFalhaDatajud::NaoConfigurado, 500];
        yield 'indisponivel -> 502'     => [MotivoFalhaDatajud::Indisponivel, 502];
        yield 'timeout -> 504'          => [MotivoFalhaDatajud::Timeout, 504];
        yield 'limite -> 429'           => [MotivoFalhaDatajud::LimiteExcedido, 429];
        yield 'sem base publica -> 422' => [MotivoFalhaDatajud::TribunalSemBasePublica, 422];
        yield 'resposta invalida -> 502' => [MotivoFalhaDatajud::RespostaInvalida, 502];
    }

    #[TestDox('Toda mensagem é amigável (não-vazia) e cada motivo tem um status HTTP de erro')]
    public function testTodoMotivoTemMensagemEStatus(): void
    {
        foreach (MotivoFalhaDatajud::cases() as $motivo) {
            self::assertNotSame('', $motivo->mensagemUsuario(), $motivo->name . ' deve ter mensagem');
            self::assertGreaterThanOrEqual(400, $motivo->statusHttp(), $motivo->name . ' deve ser status de erro');
        }
    }

    #[TestDox('Tribunal sem base pública cita a sigla quando informada')]
    public function testTribunalSemBaseCitaSigla(): void
    {
        $comSigla = MotivoFalhaDatajud::TribunalSemBasePublica->mensagemUsuario('TJMSP');
        self::assertStringContainsString('TJMSP', $comSigla);

        $semSigla = MotivoFalhaDatajud::TribunalSemBasePublica->mensagemUsuario(null);
        self::assertStringNotContainsString('TJMSP', $semSigla);
        self::assertStringContainsString('tribunal', mb_strtolower($semSigla));
    }

    #[TestDox('Sigla não polui mensagens que não a usam (ex.: timeout)')]
    public function testSiglaNaoAfetaMensagensGenericas(): void
    {
        self::assertSame(
            MotivoFalhaDatajud::Timeout->mensagemUsuario(),
            MotivoFalhaDatajud::Timeout->mensagemUsuario('TJSP'),
        );
    }
}
