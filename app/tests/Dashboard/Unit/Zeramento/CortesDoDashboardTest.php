<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit\Zeramento;

use App\Dashboard\Entity\DashboardZeramento;
use App\Dashboard\Zeramento\CortesDoDashboard;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** O corte de cada coluna e pessoa (DASH-014): o mais recente entre o da equipe e o da pessoa (`dzCorte` do desenho). */
#[CoversClass(CortesDoDashboard::class)]
final class CortesDoDashboardTest extends TestCase
{
    private function pessoa(int $id): User
    {
        $u = $this->createStub(User::class);
        $u->method('getId')->willReturn($id);

        return $u;
    }

    private function zeramento(string $coluna, ?User $pessoa, string $quando): DashboardZeramento
    {
        return new DashboardZeramento(new Tenant(), $coluna, $pessoa, new \DateTimeImmutable($quando), new User());
    }

    #[TestDox('Vale o mais recente entre o da equipe e o da pessoa; quem não tem corte próprio fica com o da equipe')]
    public function testMaisRecenteEntreEquipeEPessoa(): void
    {
        $cortes = CortesDoDashboard::deZeramentos([
            $this->zeramento('metas', null, '2026-10-10 09:00'),
            $this->zeramento('metas', $this->pessoa(7), '2026-10-15 14:30'),
            $this->zeramento('metas', $this->pessoa(8), '2026-10-05 08:00'),
        ]);

        self::assertSame('2026-10-15 14:30', $cortes->corte('metas', 7)?->format('Y-m-d H:i'));
        self::assertSame('2026-10-10 09:00', $cortes->corte('metas', 8)?->format('Y-m-d H:i'));
        self::assertSame('2026-10-10 09:00', $cortes->corte('metas', 9)?->format('Y-m-d H:i'));
        self::assertNull($cortes->corte('demandas', 7));
        self::assertTrue($cortes->temCorte('metas'));
        self::assertFalse($cortes->temCorte('demandas'));
    }

    #[TestDox('Zeramento desfeito não conta: a coluna volta a contar tudo')]
    public function testDesfeitoNaoConta(): void
    {
        $z = $this->zeramento('relatorio', $this->pessoa(7), '2026-10-15 14:30');
        $z->desfazer(new User(), new \DateTimeImmutable('2026-10-16 10:00'));

        $cortes = CortesDoDashboard::deZeramentos([$z]);

        self::assertNull($cortes->corte('relatorio', 7));
        self::assertSame([], $cortes->colunas());
        self::assertSame([], $cortes->paraTela());
    }

    #[TestDox('Grupos juntam quem tem o mesmo corte (uma consulta por grupo); colunasDe lista as colunas cortadas da pessoa')]
    public function testGruposEColunasDaPessoa(): void
    {
        $cortes = CortesDoDashboard::deZeramentos([
            $this->zeramento('metas', null, '2026-10-10 09:00'),
            $this->zeramento('metas', $this->pessoa(7), '2026-10-15 14:30'),
            $this->zeramento('prazos', $this->pessoa(8), '2026-10-12 11:00'),
        ]);

        $grupos = $cortes->grupos('metas', [7, 8, 9]);
        self::assertCount(2, $grupos);
        self::assertSame([7], $grupos[0]['pessoas']);
        self::assertSame([8, 9], $grupos[1]['pessoas']);
        self::assertSame([], $cortes->grupos('prazos', [7, 9]));
        self::assertSame(['metas'], $cortes->colunasDe(7));
        self::assertSame(['metas', 'prazos'], $cortes->colunasDe(8));
    }

    #[TestDox('paraTela leva o da equipe e os de cada pessoa em ISO 8601, para o "Contando desde" do menu')]
    public function testParaTela(): void
    {
        $cortes = CortesDoDashboard::deZeramentos([
            $this->zeramento('metas', $this->pessoa(7), '2026-10-15 14:30:00'),
        ]);

        $tela = $cortes->paraTela();
        self::assertNull($tela['metas']['todos']);
        self::assertStringStartsWith('2026-10-15T14:30:00', $tela['metas']['pessoas'][7]);
    }
}
