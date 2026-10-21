<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Djen\DTO\PublicacaoDjenListaItem;
use App\Pasta\DTO\PastaPushOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\TipoDeApenso;
use App\Processo\Entity\Processo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * P4f na aba Push: os chips por processo (desenho `procFiltros`, N:5683) e o sufixo da fonte da
 * movimentação de apensado (`pushDaPasta`, N:5483).
 */
#[CoversClass(PastaPushOutput::class)]
#[Group('pasta')]
final class PastaPushApensadosTest extends TestCase
{
    private const PRINCIPAL = '07011111111111111111';
    private const EMBARGOS  = '07345120820248070001';
    private const AGRAVO    = '07222222222222222222';

    #[TestDox('Com principal e dois apensados: Todos, Principal e um chip por apensado, cada um com a sua contagem')]
    public function testChipsComContagem(): void
    {
        $pasta = $this->pastaComApensados();
        $push  = PastaPushOutput::montar([
            $this->item(1, self::PRINCIPAL),
            $this->item(2, self::PRINCIPAL),
            $this->item(3, self::EMBARGOS),
        ], [self::PRINCIPAL, self::EMBARGOS, self::AGRAVO], 100);

        $chips = $push->filtrosPorProcesso($pasta);

        self::assertSame(['Todos os processos', 'Principal', 'Embargos', 'Agravo de instrumento'], array_column($chips, 'rotulo'));
        self::assertSame([3, 2, 1, 0], array_column($chips, 'n'));
        self::assertSame(['todos', self::PRINCIPAL, self::EMBARGOS, self::AGRAVO], array_column($chips, 'chave'));
        self::assertNull($chips[0]['numero']);
        self::assertSame(3, $push->total, 'o total do cabeçalho não é filtrado');
    }

    #[TestDox('Sem apensado não há chip: só aparecem com mais de duas opções')]
    public function testSemApensadoSemChip(): void
    {
        $pasta = new Pasta();
        $pasta->vincularProcesso($this->processo(1, self::PRINCIPAL));
        $push = PastaPushOutput::montar([$this->item(1, self::PRINCIPAL)], [self::PRINCIPAL], 100);

        self::assertSame([], $push->filtrosPorProcesso($pasta));
    }

    #[TestDox('Só apensado (sem principal) e um só: duas opções, sem chip')]
    public function testUmApensadoSemPrincipalSemChip(): void
    {
        $pasta = new Pasta();
        $pasta->vincularApensado($this->processo(2, self::EMBARGOS), TipoDeApenso::Embargos);
        $push = PastaPushOutput::montar([], [self::EMBARGOS], 100);

        self::assertSame([], $push->filtrosPorProcesso($pasta));
    }

    #[TestDox('Movimentação de apensado: tipo e número para o sufixo da fonte; do principal, nada')]
    public function testSufixoDaFonte(): void
    {
        $pasta = $this->pastaComApensados();
        $push  = PastaPushOutput::montar([], [self::PRINCIPAL, self::EMBARGOS], 100);

        self::assertSame(
            ['tipo' => 'Embargos', 'numero' => self::EMBARGOS],
            $push->apensadoDaPublicacao($this->item(3, self::EMBARGOS), $pasta),
        );
        self::assertNull($push->apensadoDaPublicacao($this->item(1, self::PRINCIPAL), $pasta));
        self::assertSame(self::EMBARGOS, $push->chaveDoProcesso($this->item(3, self::EMBARGOS)));
    }

    private function pastaComApensados(): Pasta
    {
        $pasta = new Pasta();
        $pasta->vincularProcesso($this->processo(1, self::PRINCIPAL));
        $pasta->vincularApensado($this->processo(2, self::EMBARGOS), TipoDeApenso::Embargos);
        $pasta->vincularApensado($this->processo(3, self::AGRAVO), TipoDeApenso::AgravoDeInstrumento);

        return $pasta;
    }

    private function processo(int $id, string $numero): Processo
    {
        $processo = new Processo();
        $processo->setNumeroProcesso($numero);
        (new \ReflectionProperty(Processo::class, 'id'))->setValue($processo, $id);

        return $processo;
    }

    private function item(int $id, string $numero): PublicacaoDjenListaItem
    {
        return PublicacaoDjenListaItem::fromRow([
            'id' => $id,
            'siglaTribunal' => 'TJDFT',
            'tipoComunicacao' => 'Intimação',
            'numeroProcessoComMascara' => substr($numero, 0, 7) . '-' . substr($numero, 7, 2) . '.' . substr($numero, 9, 4)
                . '.' . substr($numero, 13, 1) . '.' . substr($numero, 14, 2) . '.' . substr($numero, 16, 4),
            'numeroProcesso' => $numero,
            'dataDisponibilizacao' => new \DateTimeImmutable('2026-08-20'),
            'nomeOrgao' => '1ª Vara Cível',
            'lida' => false,
            'processoId' => null,
        ]);
    }
}
