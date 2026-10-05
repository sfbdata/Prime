<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Pasta\DTO\PastaMetasResumoOutput;
use App\Pasta\Entity\Pasta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(PastaMetasResumoOutput::class)]
final class PastaMetasResumoOutputTest extends TestCase
{
    private function usuario(string $nome): User
    {
        $u = new User();
        $u->setFullName($nome);

        return $u;
    }

    private function meta(Pasta $pasta, string $status, ?string $prazo, User ...$responsaveis): Tarefa
    {
        $m = new Tarefa();
        $m->setTitulo('Meta ' . $status . ' ' . ($prazo ?? 'sem prazo'));
        $m->setStatus($status);
        if ($prazo !== null) {
            $m->setPrazo(new \DateTimeImmutable($prazo));
        }
        foreach ($responsaveis as $r) {
            $m->addResponsavel($r);
        }
        $pasta->getTarefas()->add($m);

        return $m;
    }

    #[TestDox('conta concluídas, pendentes e atrasadas pela MESMA regra da lista: atrasada = aberta com prazo antes de hoje')]
    public function testContagens(): void
    {
        $pasta = new Pasta();
        $ana   = $this->usuario('Ana Souza');
        $bia   = $this->usuario('Bia Lima');
        $this->meta($pasta, Tarefa::STATUS_CONCLUIDA, '2026-09-01', $ana);
        $this->meta($pasta, Tarefa::STATUS_PENDENTE, '2026-10-20', $ana);
        $this->meta($pasta, Tarefa::STATUS_EM_REVISAO, null, $bia);
        $this->meta($pasta, Tarefa::STATUS_PENDENTE, '2026-10-02', $ana, $bia);   // 3 dias de atraso em 05/10
        $this->meta($pasta, Tarefa::STATUS_CONCLUIDA, '2026-09-20');               // concluída com prazo passado NÃO é atraso

        $r = PastaMetasResumoOutput::montar($pasta, new \DateTimeImmutable('2026-10-05 15:00'));

        self::assertSame(5, $r->total);
        self::assertSame(2, $r->concluidas);
        self::assertSame(2, $r->pendentes);
        self::assertSame(1, $r->atrasadas);
        self::assertSame(40, $r->percentual);

        self::assertCount(1, $r->atencao);
        self::assertSame('02/10/2026', $r->atencao[0]['prazo']);
        self::assertSame(3, $r->atencao[0]['atraso']);
        self::assertSame('Ana Souza, Bia Lima', $r->atencao[0]['responsaveis']);

        self::assertSame(
            [['nome' => 'Ana Souza', 'iniciais' => 'AS', 'contagem' => '3 metas'], ['nome' => 'Bia Lima', 'iniciais' => 'BL', 'contagem' => '2 metas']],
            $r->pessoas,
        );
    }

    #[TestDox('pasta sem meta: tudo zero, sem divisão por zero e sem listas')]
    public function testVazio(): void
    {
        $r = PastaMetasResumoOutput::montar(new Pasta(), new \DateTimeImmutable('2026-10-05'));

        self::assertSame([0, 0, 0, 0, 0], [$r->total, $r->concluidas, $r->pendentes, $r->atrasadas, $r->percentual]);
        self::assertSame([], $r->atencao);
        self::assertSame([], $r->pessoas);
    }

    #[TestDox('a mais atrasada vem primeiro em "Precisa de atenção"')]
    public function testOrdemDaAtencao(): void
    {
        $pasta = new Pasta();
        $this->meta($pasta, Tarefa::STATUS_PENDENTE, '2026-10-04');
        $this->meta($pasta, Tarefa::STATUS_PENDENTE, '2026-09-25');

        $r = PastaMetasResumoOutput::montar($pasta, new \DateTimeImmutable('2026-10-05'));

        self::assertSame([10, 1], array_column($r->atencao, 'atraso'));
    }
}
