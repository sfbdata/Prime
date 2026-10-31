<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\HistoricoDaTrilhaDaPasta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(HistoricoDaTrilhaDaPasta::class)]
final class HistoricoDaTrilhaDaPastaTest extends TestCase
{
    private function servico(): HistoricoDaTrilhaDaPasta
    {
        $pastas = $this->createStub(PastaRepository::class);
        $pastas->method('numerosDosProcessosVinculados')->willReturn([]);

        return new HistoricoDaTrilhaDaPasta($pastas, $this->createStub(PublicacaoDjenRepository::class));
    }

    private function meta(Pasta $pasta, string $titulo, string $status, ?string $conclusao = null): void
    {
        $m = new Tarefa();
        $m->setTitulo($titulo);
        $m->setStatus($status);
        if ($conclusao !== null) {
            $m->setDataConclusao(new \DateTimeImmutable($conclusao));
        }
        $pasta->getTarefas()->add($m);
    }

    #[TestDox('meta aberta e meta respondida viram fichas da aba Metas, com ícone e tom próprios')]
    public function testMetas(): void
    {
        $pasta = new Pasta();
        $this->meta($pasta, 'Juntar procuração', Tarefa::STATUS_PENDENTE);
        $this->meta($pasta, 'Protocolar inicial', Tarefa::STATUS_CONCLUIDA, '2026-10-20');

        $itens = $this->servico()->montar($pasta, new Tenant());

        self::assertCount(2, $itens);
        $porChave = array_column($itens, null, 'chave');
        self::assertSame('bi-check2-circle', $porChave['meta_respondida']['icone']);
        self::assertSame('20/10/26', $porChave['meta_respondida']['data']);
        self::assertSame('Protocolar inicial', $porChave['meta_respondida']['detalhe']);
        self::assertSame('bi-flag', $porChave['meta_aberta']['icone']);
        self::assertSame('tarefas', $porChave['meta_aberta']['aba']);
    }

    #[TestDox('aba Metas oculta para o usuário: as metas não entram na faixa')]
    public function testAbaOculta(): void
    {
        $pasta = new Pasta();
        $this->meta($pasta, 'Juntar procuração', Tarefa::STATUS_PENDENTE);

        self::assertSame([], $this->servico()->montar($pasta, new Tenant(), ['tarefas']));
    }

    #[TestDox('a faixa guarda no máximo 8 fichas')]
    public function testLimite(): void
    {
        $pasta = new Pasta();
        for ($i = 0; $i < 12; ++$i) {
            $this->meta($pasta, 'Meta ' . $i, Tarefa::STATUS_PENDENTE);
        }

        self::assertCount(HistoricoDaTrilhaDaPasta::LIMITE, $this->servico()->montar($pasta, new Tenant()));
    }
}
