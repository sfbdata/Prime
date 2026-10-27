<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\Entity\LancamentoDeCusto;
use App\Master\Enum\CategoriaDeCusto;
use App\Master\Enum\OrigemDoCusto;
use App\Master\Service\ApuracaoDeCustos;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class ApuracaoDeCustosTest extends TestCase
{
    private const GB = 1073741824;

    #[TestDox('sem lançamento e sem conta: tudo zerado, custo por GB nulo e as 9 categorias aparecem')]
    public function testVazio(): void
    {
        $r = ApuracaoDeCustos::apurar('2026-10', [], [], []);

        self::assertSame(0, $r['kpis']['custoCentavos']);
        self::assertNull($r['kpis']['custoPorGbCentavos']);
        self::assertNull($r['kpis']['margemPercentual']);
        self::assertCount(9, $r['categorias']);
        self::assertSame(0, $r['categorias'][0]['totalCentavos']);
        self::assertSame([3, 6, 12], array_column($r['projecao'], 'meses'));
    }

    #[TestDox('receita: preço do plano das contas ativas mais o convênio dif ou sem; suspensa e quarentena ficam de fora')]
    public function testReceita(): void
    {
        $contas = [
            $this->conta('essencial', 10 * self::GB, 2),
            $this->conta('essencial', 0, 1, 'dif', 15.5),
            $this->conta('essencial', 0, 1, 'sem', 20.0),
            $this->conta('essencial', 0, 1, 'livre', 99.0),
            $this->conta('essencial', 0, 1, 'dif', 50.0, 'Suspensa'),
            $this->conta('essencial', 0, 1, 'dif', 50.0, 'Em quarentena'),
        ];

        $r = ApuracaoDeCustos::apurar('2026-10', [], $contas, [$this->plano('essencial', 20, 1990)]);

        self::assertSame(4 * 1990 + 1550 + 2000, $r['kpis']['receitaCentavos']);
        self::assertSame(4, $r['kpis']['contas']);
        self::assertSame(5, $r['kpis']['usuarios']);
    }

    #[TestDox('custo por GB usa só Armazenamento e Backups; custo e margem do plano saem da cota')]
    public function testCustoPorGbEMargemPorPlano(): void
    {
        $lancamentos = [
            $this->lancamento(CategoriaDeCusto::Armazenamento, 8000),
            $this->lancamento(CategoriaDeCusto::Backups, 2000),
            $this->lancamento(CategoriaDeCusto::ApisDeIa, 5000),
        ];
        $contas = [$this->conta('essencial', 10 * self::GB, 1)];

        $r = ApuracaoDeCustos::apurar('2026-10', $lancamentos, $contas, [$this->plano('essencial', 20, 1990), $this->plano('pro', 0, 3990)]);

        self::assertSame(15000, $r['kpis']['custoCentavos']);
        self::assertSame(1000, $r['kpis']['custoPorGbCentavos']);
        self::assertSame(10.0, $r['kpis']['gbArmazenados']);
        self::assertSame(20000, $r['planos'][0]['custoCentavos']);
        self::assertSame(1990 - 20000, $r['planos'][0]['margemCentavos']);
        self::assertNull($r['planos'][1]['custoCentavos'], 'plano sem cota não tem custo');
        self::assertSame(53, $r['categorias'][0]['percentual']);
    }

    #[TestDox('margem negativa, percentual da receita e projeção de 3, 6 e 12 meses')]
    public function testMargemEProjecao(): void
    {
        $r = ApuracaoDeCustos::apurar(
            '2026-10',
            [$this->lancamento(CategoriaDeCusto::Hospedagem, 3000)],
            [$this->conta('essencial', 0, 1)],
            [$this->plano('essencial', 20, 1000)],
        );

        self::assertSame(-2000, $r['kpis']['margemCentavos']);
        self::assertSame(-200, $r['kpis']['margemPercentual']);
        self::assertSame(3000 * 6, $r['projecao'][1]['custoCentavos']);
        self::assertSame(-2000 * 12, $r['projecao'][2]['margemCentavos']);
        self::assertSame(100, $r['categorias'][2]['percentual']);
    }

    #[TestDox('valor zero ou negativo não vira lançamento')]
    public function testValorInvalido(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->lancamento(CategoriaDeCusto::Outros, 0);
    }

    private function lancamento(CategoriaDeCusto $categoria, int $centavos): LancamentoDeCusto
    {
        return new LancamentoDeCusto('2026-10', $categoria, $centavos, OrigemDoCusto::Estimado, null, null, new \DateTimeImmutable());
    }

    /** @return array{situacao: string, usuarios: int, bytes: int, espaco: array{planoCodigo: string}, convenio: array{modo: string, valor: float}} */
    private function conta(string $plano, int $bytes, int $usuarios, string $modo = 'livre', float $valor = 0.0, string $situacao = 'Ativa'): array
    {
        return ['situacao' => $situacao, 'usuarios' => $usuarios, 'bytes' => $bytes, 'espaco' => ['planoCodigo' => $plano], 'convenio' => ['modo' => $modo, 'valor' => $valor]];
    }

    /** @return array{codigo: string, nome: string, cotaGb: int, precoCentavos: int, ativo: bool} */
    private function plano(string $codigo, int $cotaGb, int $preco): array
    {
        return ['codigo' => $codigo, 'nome' => ucfirst($codigo), 'cotaGb' => $cotaGb, 'precoCentavos' => $preco, 'ativo' => true];
    }
}
