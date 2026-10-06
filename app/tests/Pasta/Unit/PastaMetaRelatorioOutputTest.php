<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Pasta\DTO\PastaMetaRelatorioOutput;
use App\Pasta\DTO\PastaMetasResumoOutput;
use App\Pasta\Entity\Pasta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Conteúdo do drawer "Relatório da meta" (desenho 1.2.3, dc L.3557-3600): pílula,
 * frase do prazo, os seis campos e o histórico — só com o que a meta registrou.
 */
#[CoversClass(PastaMetaRelatorioOutput::class)]
#[CoversClass(PastaMetasResumoOutput::class)]
final class PastaMetaRelatorioOutputTest extends TestCase
{
    private const HOJE = '2026-10-06';

    private function pessoa(string $nome): User
    {
        return (new User())->setFullName($nome);
    }

    /** @param list<User> $responsaveis */
    private function meta(
        Pasta $pasta,
        string $status,
        ?string $prazo,
        ?User $criador = null,
        array $responsaveis = [],
        ?string $conclusao = null,
        ?string $alteracao = null,
        string $criadaEm = '2026-09-20 10:00',
    ): Tarefa {
        $m = new Tarefa();
        $m->setTitulo('Juntar procuração');
        $m->setStatus($status);
        $m->setCriadoPor($criador);
        if ($prazo !== null) {
            $m->setPrazo(new \DateTimeImmutable($prazo));
        }
        if ($conclusao !== null) {
            $m->setDataConclusao(new \DateTimeImmutable($conclusao));
        }
        foreach ($responsaveis as $r) {
            $m->addResponsavel($r);
        }
        // Sem setter: as duas datas nascem no construtor / no PreUpdate; o teste as fixa.
        (new \ReflectionProperty(Tarefa::class, 'dataCriacao'))->setValue($m, new \DateTimeImmutable($criadaEm));
        if ($alteracao !== null) {
            (new \ReflectionProperty(Tarefa::class, 'dataAlteracao'))->setValue($m, new \DateTimeImmutable($alteracao));
        }
        $m->setPasta($pasta);
        $pasta->getTarefas()->add($m);

        return $m;
    }

    private function relatorio(Pasta $pasta, int $i = 0): PastaMetaRelatorioOutput
    {
        $resumo = PastaMetasResumoOutput::montar($pasta, new \DateTimeImmutable(self::HOJE . ' 15:00'));

        return $resumo->linhas[$i]['relatorio'];
    }

    /** @return array<string, string> rótulo => valor */
    private function campos(PastaMetaRelatorioOutput $r): array
    {
        $campos = [];
        foreach ($r->campos as $c) {
            $campos[$c['rotulo']] = $c['valor'];
        }

        return $campos;
    }

    #[TestDox('meta aberta: pílula "Pendente", "Vence dd/mm/aaaa", seis campos na ordem do desenho e histórico criada → prazo')]
    public function testMetaAberta(): void
    {
        $pasta = (new Pasta())->setNup('2026/0042');
        $this->meta($pasta, Tarefa::STATUS_PENDENTE, '2026-10-20', $this->pessoa('Ana Souza'), [$this->pessoa('Bruno Lima'), $this->pessoa('Carla Dias')]);

        $r = $this->relatorio($pasta);

        self::assertSame('aberta', $r->tom);
        self::assertSame('Pendente', $r->situacao);
        self::assertSame('Vence 20/10/2026', $r->prazoTexto);
        self::assertFalse($r->prazoEmAtraso);
        self::assertSame([
            'Criada por'         => 'Ana Souza',
            'Responsáveis'       => 'Bruno Lima, Carla Dias',
            'Prazo'              => '20/10/2026',
            'Última modificação' => 'Sem alterações',
            'Pasta'              => '2026/0042',
            'Situação'           => 'Pendente',
        ], $this->campos($r));
        self::assertSame([
            ['texto' => 'Meta criada por Ana Souza para Bruno Lima, Carla Dias', 'data' => '20/09/2026', 'tom' => 'criada'],
            ['texto' => 'Prazo definido para 20/10/2026', 'data' => '20/10/2026', 'tom' => 'prazo'],
        ], $r->historico);
    }

    #[TestDox('meta em revisão usa o status real na pílula ("Para Revisão"), como a linha')]
    public function testMetaEmRevisao(): void
    {
        $pasta = new Pasta();
        $this->meta($pasta, Tarefa::STATUS_EM_REVISAO, '2026-10-20');

        $r = $this->relatorio($pasta);

        self::assertSame('Para Revisão', $r->situacao);
        self::assertSame('Para Revisão', $this->campos($r)['Situação']);
    }

    #[TestDox('meta atrasada: "N dias em atraso · prazo X" em vermelho e o evento de atraso até hoje (singular com 1 dia)')]
    public function testMetaAtrasada(): void
    {
        $pasta = new Pasta();
        $this->meta($pasta, Tarefa::STATUS_PENDENTE, '2026-10-03', alteracao: '2026-10-01 09:30');
        $this->meta($pasta, Tarefa::STATUS_PENDENTE, '2026-10-05');

        $tres = $this->relatorio($pasta, 0);
        self::assertSame('atrasada', $tres->tom);
        self::assertSame('Atrasada', $tres->situacao);
        self::assertSame('3 dias em atraso · prazo 03/10/2026', $tres->prazoTexto);
        self::assertTrue($tres->prazoEmAtraso);
        self::assertSame('01/10/2026', $this->campos($tres)['Última modificação']);
        self::assertSame(
            [['Atualizada', '01/10/2026', 'atualizada'], ['3 dias em atraso até hoje', '06/10/2026', 'atraso']],
            array_map(static fn (array $e) => [$e['texto'], $e['data'], $e['tom']], array_slice($tres->historico, 2)),
        );

        $um = $this->relatorio($pasta, 1);
        self::assertSame('1 dia em atraso · prazo 05/10/2026', $um->prazoTexto);
        self::assertSame('1 dia em atraso até hoje', $um->historico[array_key_last($um->historico)]['texto']);
    }

    #[TestDox('meta concluída: o rótulo REAL do prazo com inicial maiúscula e o evento "Concluída" na data da conclusão')]
    public function testMetaConcluida(): void
    {
        $pasta = new Pasta();
        $this->meta($pasta, Tarefa::STATUS_CONCLUIDA, '2026-10-10', conclusao: '2026-10-08 17:40', alteracao: '2026-10-08 17:40');
        $this->meta($pasta, Tarefa::STATUS_CONCLUIDA, '2026-10-01', conclusao: '2026-10-04 08:00');

        $noPrazo = $this->relatorio($pasta, 0);
        self::assertSame('concluida', $noPrazo->tom);
        self::assertSame('Concluída', $noPrazo->situacao);
        self::assertSame('Concluída no prazo 10/10/2026', $noPrazo->prazoTexto);
        self::assertFalse($noPrazo->prazoEmAtraso);
        $ultimo = $noPrazo->historico[array_key_last($noPrazo->historico)];
        self::assertSame(['Concluída', '08/10/2026', 'concluida'], [$ultimo['texto'], $ultimo['data'], $ultimo['tom']]);
        self::assertNotContains('atualizada', array_column($noPrazo->historico, 'tom'), 'concluída mostra a conclusão, não "Atualizada"');

        self::assertSame('Concluída com 3 dias de atraso', $this->relatorio($pasta, 1)->prazoTexto, 'o desenho só conhece "no prazo"; o sistema diz a verdade');
    }

    #[TestDox('concluída sem data de conclusão: o evento existe, a data não é inventada')]
    public function testConcluidaSemDataDeConclusao(): void
    {
        $pasta = new Pasta();
        $this->meta($pasta, Tarefa::STATUS_CONCLUIDA, '2026-10-10');

        $r = $this->relatorio($pasta);

        self::assertSame('Prazo 10/10/2026', $r->prazoTexto);
        $ultimo = $r->historico[array_key_last($r->historico)];
        self::assertSame(['Concluída', 'data de conclusão não registrada'], [$ultimo['texto'], $ultimo['data']]);
    }

    #[TestDox('sem prazo, sem autor e sem responsáveis: sem frase de prazo, campos com "—"/"Sem prazo" e só o evento de criação')]
    public function testMetaSemPrazoNemPessoas(): void
    {
        $pasta = new Pasta();
        $this->meta($pasta, Tarefa::STATUS_PENDENTE, null);

        $r = $this->relatorio($pasta);

        self::assertNull($r->prazoTexto);
        $campos = $this->campos($r);
        self::assertSame('—', $campos['Criada por']);
        self::assertSame('—', $campos['Responsáveis']);
        self::assertSame('Sem prazo', $campos['Prazo']);
        self::assertSame('—', $campos['Pasta'], 'pasta sem identificador');
        self::assertSame([['texto' => 'Meta criada', 'data' => '20/09/2026', 'tom' => 'criada']], $r->historico);
    }

    #[TestDox('o relatório usa o MESMO estado da linha (a lista e o drawer nunca divergem)')]
    public function testMesmoEstadoDaLinha(): void
    {
        $pasta = new Pasta();
        $this->meta($pasta, Tarefa::STATUS_PENDENTE, '2026-10-01');
        $this->meta($pasta, Tarefa::STATUS_PENDENTE, '2026-10-30', criadaEm: '2026-09-21 10:00');
        $this->meta($pasta, Tarefa::STATUS_CONCLUIDA, '2026-10-01', conclusao: '2026-09-30', criadaEm: '2026-09-22 10:00');

        $resumo = PastaMetasResumoOutput::montar($pasta, new \DateTimeImmutable(self::HOJE));

        foreach ($resumo->linhas as $linha) {
            self::assertSame($linha['estado'], $linha['relatorio']->tom);
        }
        self::assertSame(['atrasada', 'aberta', 'concluida'], array_map(static fn (array $l) => $l['relatorio']->tom, $resumo->linhas));
    }
}
