<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Voto\DTO\LinhaDeUnidade;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Service\LeitorDaListaDeUnidades;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A lista de unidades colada pelo gestor (spec voto-assembleia-condominio, seção 1.4; regras de importação do desenho,
 * N97): colunas, cabeçalho, linhas inválidas com o motivo, duplicadas e fração com vírgula.
 */
#[CoversClass(LeitorDaListaDeUnidades::class)]
#[CoversClass(LinhaDeUnidade::class)]
final class LeitorDaListaDeUnidadesTest extends TestCase
{
    #[TestDox('Lê as seis colunas, pula o cabeçalho e as linhas vazias e normaliza a fração com vírgula')]
    public function testLeColunasECabecalho(): void
    {
        $texto = "Unidade; Bloco; Situação; Fração; Responsável; E-mail\n"
            . "101; A; quite; 0,025; Ana Souza; ana@exemplo.com\n"
            . "\n"
            . "102;A;;0.0310;;\n";

        $lidas = (new LeitorDaListaDeUnidades())->ler($texto, PesoDoVoto::Fracao);

        self::assertSame([], $lidas['invalidas']);
        self::assertSame([], $lidas['duplicadas']);
        self::assertCount(2, $lidas['validas']);
        [$primeira, $segunda] = $lidas['validas'];
        self::assertSame(2, $primeira->linha);
        self::assertSame('101', $primeira->identificacao);
        self::assertSame('A', $primeira->bloco);
        self::assertSame('quite', $primeira->situacao);
        self::assertSame('0.025000', $primeira->fracao);
        self::assertSame('Ana Souza', $primeira->responsavel);
        self::assertSame('ana@exemplo.com', $primeira->email);
        self::assertSame(4, $segunda->linha);
        self::assertNull($segunda->situacao);
        self::assertSame('0.031000', $segunda->fracao);
        self::assertNull($segunda->responsavel);
        self::assertNull($segunda->email);
    }

    #[TestDox('Aceita a lista colada de uma planilha, separada por tabulação')]
    public function testSeparadoPorTabulacao(): void
    {
        $lidas = (new LeitorDaListaDeUnidades())->ler("201\tB\t\t\tCarlos\t", PesoDoVoto::Unidade);

        self::assertCount(1, $lidas['validas']);
        self::assertSame('B', $lidas['validas'][0]->bloco);
        self::assertNull($lidas['validas'][0]->fracao, 'um voto por unidade: fração vazia é aceita');
        self::assertSame('Carlos', $lidas['validas'][0]->responsavel);
    }

    /** @return iterable<string, array{string, PesoDoVoto, string}> */
    public static function linhasInvalidas(): iterable
    {
        yield 'sem a unidade' => ['; A; ; 0,02', PesoDoVoto::Fracao, 'Linha sem a unidade.'];
        yield 'sem fração na assembleia por fração ideal' => ['101; A', PesoDoVoto::Fracao, 'Informe a fração ideal.'];
        yield 'fração com letra' => ['101; A; ; 0,0x', PesoDoVoto::Fracao, 'Fração inválida.'];
        yield 'fração negativa' => ['101; A; ; -1', PesoDoVoto::Unidade, 'Fração inválida.'];
        yield 'fração zero' => ['101; A; ; 0,000', PesoDoVoto::Fracao, 'A fração precisa ser maior que zero.'];
        yield 'e-mail inválido' => ['101; A; ; 0,02; Ana; ana@', PesoDoVoto::Fracao, 'E-mail inválido.'];
    }

    #[DataProvider('linhasInvalidas')]
    #[TestDox('Linha inválida entra no relatório com o número da linha e o motivo: $_dataName')]
    public function testLinhaInvalida(string $linha, PesoDoVoto $peso, string $motivo): void
    {
        $lidas = (new LeitorDaListaDeUnidades())->ler("102; A; ; 0,02\n" . $linha, $peso);

        self::assertCount(1, $lidas['validas']);
        self::assertSame([['linha' => 2, 'motivo' => $motivo]], $lidas['invalidas']);
    }

    #[TestDox('A mesma unidade no mesmo bloco repetida é duplicada (sem diferença de caixa); em outro bloco não é')]
    public function testDuplicadas(): void
    {
        $texto = "101; A; ; 0,02\n101; B; ; 0,02\n101; a; ; 0,03\n";

        $lidas = (new LeitorDaListaDeUnidades())->ler($texto, PesoDoVoto::Fracao);

        self::assertCount(2, $lidas['validas']);
        self::assertSame([3], $lidas['duplicadas']);
    }

    #[TestDox('O cabeçalho só é pulado na primeira linha com conteúdo')]
    public function testCabecalhoSoNaPrimeiraLinha(): void
    {
        $lidas = (new LeitorDaListaDeUnidades())->ler("101; A\nnumero; A\n", PesoDoVoto::Unidade);

        self::assertCount(2, $lidas['validas']);
        self::assertSame('numero', $lidas['validas'][1]->identificacao);
    }

    #[TestDox('O cabeçalho do modelo de cada cenário (Matrícula, CPF, Número de inscrição...) também é pulado')]
    public function testCabecalhoDeCadaCenario(): void
    {
        foreach (\App\Voto\Enum\CenarioDaAssembleia::cases() as $cenario) {
            $lidas = (new LeitorDaListaDeUnidades())->ler($cenario->identificacao() . "; " . $cenario->grupo() . "\n77; X\n", PesoDoVoto::Unidade);

            self::assertCount(1, $lidas['validas'], $cenario->value);
            self::assertSame('77', $lidas['validas'][0]->identificacao);
        }
    }

    #[TestDox('Mais de 3000 linhas de uma vez são recusadas')]
    public function testLimiteDeLinhas(): void
    {
        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $this->expectExceptionMessage('Cole no máximo 3000 linhas por vez.');

        (new LeitorDaListaDeUnidades())->ler(str_repeat("1; A\n", LeitorDaListaDeUnidades::LIMITE_DE_LINHAS), PesoDoVoto::Unidade);
    }

    #[TestDox('A coluna 9 CPF aceita com ou sem pontuação e guarda só os dígitos; ausente fica nula')]
    public function testCpfValidoAusente(): void
    {
        $texto = "101; A; ; ; Ana; ana@exemplo.com; ; ; 529.982.247-25\n102; A; ; ; Beto; ; ; ; 11144477735\n103; A\n";

        $lidas = (new LeitorDaListaDeUnidades())->ler($texto, PesoDoVoto::Unidade);

        self::assertSame([], $lidas['invalidas']);
        self::assertSame(['52998224725', '11144477735', null], array_map(static fn (LinhaDeUnidade $l): ?string => $l->cpf, $lidas['validas']));
    }

    #[TestDox('CPF com dígito verificador errado, curto ou repetido vira erro da linha; as outras linhas entram')]
    public function testCpfInvalidoViraErroDeLinha(): void
    {
        $texto = "101; A; ; ; Ana; ; ; ; 529.982.247-26\n102; A; ; ; Beto; ; ; ; 123\n103; A; ; ; Caio; ; ; ; 111.111.111-11\n104; A; ; ; Dora; ; ; ; 529.982.247-25\n";

        $lidas = (new LeitorDaListaDeUnidades())->ler($texto, PesoDoVoto::Unidade);

        self::assertSame([['linha' => 1, 'motivo' => 'CPF inválido.'], ['linha' => 2, 'motivo' => 'CPF inválido.'], ['linha' => 3, 'motivo' => 'CPF inválido.']], $lidas['invalidas']);
        self::assertCount(1, $lidas['validas']);
        self::assertSame('104', $lidas['validas'][0]->identificacao);
    }
}
