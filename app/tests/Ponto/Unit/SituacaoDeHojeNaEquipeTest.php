<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Enum\SituacaoDeHojeNaEquipe;
use App\Ponto\Service\BatidasEscolhidas;
use App\Ponto\Service\HojeDaPessoaNaEquipe;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A situação de hoje, a entrada e o último registro de cada pessoa na aba Equipe (spec ponto-desenho-0811, E5, P54;
 * desenho 16 L777): a regra do desenho sobre as batidas que a folha escolhe para o dia.
 */
#[CoversClass(SituacaoDeHojeNaEquipe::class)]
#[CoversClass(HojeDaPessoaNaEquipe::class)]
#[Group('ponto')]
final class SituacaoDeHojeNaEquipeTest extends TestCase
{
    /** @param array<string, string> $batidas tipo => HH:MM */
    #[DataProvider('dias')]
    #[TestDox('$_dataName')]
    public function testSituacaoDoDia(array $batidas, SituacaoDeHojeNaEquipe $situacao, string $rotulo, ?string $entrada, ?string $ultimo): void
    {
        $hoje = HojeDaPessoaNaEquipe::daEscolha(self::escolha($batidas));

        self::assertSame($situacao, $hoje->situacao);
        self::assertSame($rotulo, $hoje->situacao->rotulo());
        self::assertSame($entrada, $hoje->entrada);
        self::assertSame($ultimo, $hoje->ultimoRegistro);
    }

    /** @return iterable<string, array{array<string, string>, SituacaoDeHojeNaEquipe, string, ?string, ?string}> */
    public static function dias(): iterable
    {
        yield 'Sem entrada: "Sem registro", sem entrada nem último registro' => [[], SituacaoDeHojeNaEquipe::SemRegistro, 'Sem registro', null, null];
        yield 'Com saída: "Encerrada", o último é a saída' => [
            ['entrada' => '08:58', 'repouso' => '12:00', 'retorno' => '13:01', 'saida' => '18:02'],
            SituacaoDeHojeNaEquipe::Encerrada, 'Encerrada', '08:58', '18:02 · Saída',
        ];
        yield 'Repouso sem retorno: "Em intervalo", o último é o repouso' => [
            ['entrada' => '09:00', 'repouso' => '12:05'],
            SituacaoDeHojeNaEquipe::EmIntervalo, 'Em intervalo', '09:00', '12:05 · Repouso',
        ];
        yield 'Só a entrada: "Em jornada"' => [['entrada' => '07:45'], SituacaoDeHojeNaEquipe::EmJornada, 'Em jornada', '07:45', '07:45 · Entrada'];
        yield 'Voltou do repouso: "Em jornada", o último é o retorno' => [
            ['entrada' => '09:00', 'repouso' => '12:00', 'retorno' => '13:00'],
            SituacaoDeHojeNaEquipe::EmJornada, 'Em jornada', '09:00', '13:00 · Retorno',
        ];
        yield 'Saída sem entrada (dia esquecido): "Sem registro"' => [['saida' => '18:00'], SituacaoDeHojeNaEquipe::SemRegistro, 'Sem registro', null, '18:00 · Saída'];
    }

    #[TestDox('O valor da situação é o sufixo da classe do selo (a cor do desenho)')]
    public function testValores(): void
    {
        self::assertSame(['jornada', 'intervalo', 'sem', 'encerrada'], array_map(
            static fn (SituacaoDeHojeNaEquipe $s): string => $s->value,
            SituacaoDeHojeNaEquipe::cases(),
        ));
    }

    /** @param array<string, string> $batidas */
    private static function escolha(array $batidas): BatidasEscolhidas
    {
        $do = static function (string $tipo) use ($batidas): ?RegistroPonto {
            if (!isset($batidas[$tipo])) {
                return null;
            }

            return (new RegistroPonto())->setTipo($tipo)->setDataHora(new \DateTime('2026-11-09 ' . $batidas[$tipo] . ':00'));
        };

        return new BatidasEscolhidas(BatidasEscolhidas::REGRA_UNICA, $do('entrada'), $do('repouso'), $do('retorno'), $do('saida'), [], []);
    }
}
