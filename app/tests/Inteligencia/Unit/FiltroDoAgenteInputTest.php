<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Inteligencia\DTO\FiltroDoAgenteInput;
use App\Inteligencia\Exception\FiltroDoAgenteInvalidoException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Fase 1 do Agente de Pastas (`docs/specs/designer-central-ia.md` §3): o filtro que o agente manda
 * vira EXATAMENTE as chaves que a Lista do Expediente já usa no `PastaRepository`. Valor que o
 * repositório ignoraria em silêncio (situação inexistente, data torta) é recusado aqui — para o agente
 * não receber "todas as pastas" achando que filtrou.
 */
#[CoversClass(FiltroDoAgenteInput::class)]
final class FiltroDoAgenteInputTest extends TestCase
{
    #[TestDox('Sem nenhum filtro: página 1 e nenhuma chave para o repositório')]
    public function testVazio(): void
    {
        $filtro = FiltroDoAgenteInput::daQuery([]);

        self::assertSame(1, $filtro->pagina);
        self::assertNull($filtro->marcadorId);
        self::assertSame([], $filtro->nups);
        self::assertSame([], array_filter($filtro->paraRepositorio(), static fn (string $v): bool => $v !== ''));
    }

    #[TestDox('Os filtros do agente viram as chaves da Lista do Expediente')]
    public function testTraduzParaAsChavesDaLista(): void
    {
        $filtro = FiltroDoAgenteInput::daQuery([
            'busca'       => '  Maria  ',
            'responsavel' => '42',
            'situacao'    => 'arquivado',
            'prioridade'  => 'urgente',
            'desde'       => '2026-01-01',
            'ate'         => '2026-06-30',
            'marcador'    => '7',
            'pagina'      => '3',
        ]);

        self::assertSame([
            'busca'       => 'Maria',
            'responsavel' => '42',
            'status'      => 'arquivado',
            'prioridade'  => 'urgente',
            'data_de'     => '2026-01-01',
            'data_ate'    => '2026-06-30',
        ], $filtro->paraRepositorio());
        self::assertSame(7, $filtro->marcadorId);
        self::assertSame(3, $filtro->pagina);
        self::assertFalse($filtro->contar);
    }

    #[TestDox('Lista de NUPs: separa por vírgula, apara, tira vazios e repetidos')]
    public function testNups(): void
    {
        $filtro = FiltroDoAgenteInput::daQuery(['nups' => ' 101, 102 ,,101,  ']);

        self::assertSame(['101', '102'], $filtro->nups);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidos(): iterable
    {
        yield 'situação inexistente'           => [['situacao' => 'suspenso'], 'situacao'];
        yield 'prioridade inexistente'         => [['prioridade' => 'altissima'], 'prioridade'];
        yield 'responsável não numérico'       => [['responsavel' => 'Mariana'], 'responsavel'];
        yield 'responsável "sem" (chega com a E8)' => [['responsavel' => 'sem'], 'responsavel'];
        yield 'marcador não numérico'          => [['marcador' => 'Despachar'], 'marcador'];
        yield 'data em formato brasileiro'     => [['desde' => '01/01/2026'], 'desde'];
        yield 'data impossível'                => [['ate' => '2026-02-31'], 'ate'];
        yield 'período invertido'              => [['desde' => '2026-05-01', 'ate' => '2026-04-01'], 'ate'];
        yield 'página zero'                    => [['pagina' => '0'], 'pagina'];
        yield 'página não numérica'            => [['pagina' => 'dois'], 'pagina'];
        yield 'NUPs junto com outro filtro'    => [['nups' => '101', 'busca' => 'Maria'], 'nups'];
        yield 'filtro que o agente não conhece' => [['semMetas' => '1'], 'semMetas'];
        yield 'cliente (a Lista usa busca)'    => [['cliente' => 'Maria'], 'cliente'];
        yield 'acao (a Lista usa busca)'       => [['acao' => 'cobrança'], 'acao'];
        yield 'NUPs em forma de lista'         => [['nups' => ['101', '102']], 'nups'];
        yield 'responsável em forma de lista'  => [['responsavel' => ['7']], 'responsavel'];
        yield 'página acima do teto'           => [['pagina' => '99999999999999999999'], 'pagina'];
        yield 'id gigante'                     => [['marcador' => '99999999999999999999'], 'marcador'];
        yield 'contar com outro valor'         => [['contar' => 'sim'], 'contar'];
    }

    /** @param array<string, mixed> $query */
    #[DataProvider('invalidos')]
    #[TestDox('Recusa $_dataName, apontando o campo')]
    public function testRecusa(array $query, string $campo): void
    {
        try {
            FiltroDoAgenteInput::daQuery($query);
            self::fail('Deveria recusar o filtro.');
        } catch (FiltroDoAgenteInvalidoException $e) {
            self::assertSame($campo, $e->campo);
        }
    }

    #[TestDox('Acima de 500 NUPs é recusado (teto de lote da especificação)')]
    public function testTetoDeNups(): void
    {
        $this->expectException(FiltroDoAgenteInvalidoException::class);

        FiltroDoAgenteInput::daQuery(['nups' => implode(',', range(1, 501))]);
    }

    #[TestDox('Os parâmetros de controle (contar) não contam como filtro desconhecido')]
    public function testIgnoraParametrosDeControle(): void
    {
        $filtro = FiltroDoAgenteInput::daQuery(['contar' => '1']);

        self::assertSame(1, $filtro->pagina);
        self::assertTrue($filtro->contar);
    }
}
