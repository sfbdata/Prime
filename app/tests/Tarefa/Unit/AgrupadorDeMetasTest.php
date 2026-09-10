<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Tarefa\Tarefa;
use App\Pasta\Entity\Pasta;
use App\Tarefa\DTO\GrupoDeMetasOutput;
use App\Tarefa\Enum\AbaMetas;
use App\Tarefa\Service\AgrupadorDeMetas;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O agrupamento é regra de negócio pura — sem banco, sem kernel. Spec:
 * `docs/specs/minhas-metas-abas.md`.
 */
#[CoversClass(AgrupadorDeMetas::class)]
#[Group('tarefa')]
final class AgrupadorDeMetasTest extends TestCase
{
    private AgrupadorDeMetas $agrupador;
    private \DateTimeImmutable $hoje;

    protected function setUp(): void
    {
        $this->agrupador = new AgrupadorDeMetas();
        $this->hoje      = new \DateTimeImmutable('2026-09-10 00:00:00');
    }

    private function meta(string $titulo, ?string $prazo, string $status = Tarefa::STATUS_PENDENTE): Tarefa
    {
        $tarefa = new Tarefa();
        $tarefa->setTitulo($titulo);
        $tarefa->setDescricao('...');
        $tarefa->setStatus($status);
        $tarefa->setPasta(new Pasta());

        if ($prazo !== null) {
            $tarefa->setPrazo(new \DateTimeImmutable($prazo));
        }

        return $tarefa;
    }

    /**
     * @param GrupoDeMetasOutput[] $grupos
     * @return array<string, string[]>  chave do grupo => títulos
     */
    private function mapa(array $grupos): array
    {
        $mapa = [];
        foreach ($grupos as $grupo) {
            $mapa[$grupo->chave] = array_map(static fn (Tarefa $t): string => $t->getTitulo(), $grupo->metas);
        }

        return $mapa;
    }

    #[TestDox('Por urgência: cada meta cai no balde do seu prazo')]
    public function testAgrupaPorUrgencia(): void
    {
        $metas = [
            $this->meta('Atrasada', '2026-09-05'),
            $this->meta('Vence hoje', '2026-09-10'),
            $this->meta('Vence em 7 dias', '2026-09-17'),
            $this->meta('Vence em 8 dias', '2026-09-18'),
            $this->meta('Sem prazo', null),
        ];

        $mapa = $this->mapa($this->agrupador->agrupar($metas, AbaMetas::RESPONSAVEL, $this->hoje));

        self::assertSame(['Atrasada'], $mapa['atrasadas']);
        self::assertSame(['Vence hoje', 'Vence em 7 dias'], $mapa['proximas'], 'A janela é fechada nos dois extremos: hoje entra, o sétimo dia entra.');
        self::assertSame(['Vence em 8 dias'], $mapa['depois']);
        self::assertSame(['Sem prazo'], $mapa['sem_prazo']);
    }

    #[TestDox('Meta em revisão sai da fila de urgência mesmo estando atrasada')]
    public function testEmRevisaoVenceAUrgencia(): void
    {
        $metas = [$this->meta('Entreguei e está atrasada', '2026-09-01', Tarefa::STATUS_EM_REVISAO)];

        $mapa = $this->mapa($this->agrupador->agrupar($metas, AbaMetas::RESPONSAVEL, $this->hoje));

        self::assertArrayNotHasKey('atrasadas', $mapa, 'Já entreguei: não é mais pendência minha.');
        self::assertSame(['Entreguei e está atrasada'], $mapa['em_revisao']);
    }

    #[TestDox('Concluída atrasada vai para Concluídas, não para Atrasadas')]
    public function testConcluidaVenceAUrgencia(): void
    {
        $metas = [$this->meta('Concluída fora do prazo', '2026-08-01', Tarefa::STATUS_CONCLUIDA)];

        $mapa = $this->mapa($this->agrupador->agrupar($metas, AbaMetas::RESPONSAVEL, $this->hoje));

        self::assertArrayNotHasKey('atrasadas', $mapa);
        self::assertSame(['Concluída fora do prazo'], $mapa['concluidas']);
    }

    #[TestDox('Cada meta aparece em EXATAMENTE um grupo')]
    public function testNenhumaMetaEmDoisGrupos(): void
    {
        $metas = [
            $this->meta('A', '2026-09-01'),
            $this->meta('B', '2026-09-12'),
            $this->meta('C', null),
            $this->meta('D', '2026-09-02', Tarefa::STATUS_EM_REVISAO),
            $this->meta('E', '2026-09-02', Tarefa::STATUS_CONCLUIDA),
            $this->meta('F', '2026-12-25'),
        ];

        $grupos = $this->agrupador->agrupar($metas, AbaMetas::RESPONSAVEL, $this->hoje);

        $titulos = [];
        foreach ($grupos as $grupo) {
            foreach ($grupo->metas as $meta) {
                $titulos[] = $meta->getTitulo();
            }
        }

        sort($titulos);
        self::assertSame(['A', 'B', 'C', 'D', 'E', 'F'], $titulos, 'Nenhuma meta pode sumir nem se repetir no agrupamento.');
    }

    #[TestDox('Grupo vazio não é devolvido — a tela não mostra bloco sem conteúdo')]
    public function testGruposVaziosSaem(): void
    {
        $grupos = $this->agrupador->agrupar([$this->meta('Só uma', null)], AbaMetas::RESPONSAVEL, $this->hoje);

        self::assertCount(1, $grupos);
        self::assertSame('sem_prazo', $grupos[0]->chave);
    }

    #[TestDox('Dentro do grupo, o prazo mais apertado vem primeiro')]
    public function testOrdenaPeloPrazoDentroDoGrupo(): void
    {
        $metas = [
            $this->meta('Atrasada há 2 dias', '2026-09-08'),
            $this->meta('Atrasada há 30 dias', '2026-08-11'),
            $this->meta('Atrasada há 9 dias', '2026-09-01'),
        ];

        $mapa = $this->mapa($this->agrupador->agrupar($metas, AbaMetas::RESPONSAVEL, $this->hoje));

        self::assertSame(['Atrasada há 30 dias', 'Atrasada há 9 dias', 'Atrasada há 2 dias'], $mapa['atrasadas']);
    }

    #[TestDox('Aba Criei agrupa por etapa da revisão, não por prazo')]
    public function testAbaCrieiAgrupaPorEtapa(): void
    {
        $metas = [
            $this->meta('Voltou para mim', '2026-12-01', Tarefa::STATUS_EM_REVISAO),
            $this->meta('Está com o colega', '2026-09-20'),
            $this->meta('Colega atrasou', '2026-09-01'),
            $this->meta('Já aprovei', '2026-09-05', Tarefa::STATUS_CONCLUIDA),
        ];

        $mapa = $this->mapa($this->agrupador->agrupar($metas, AbaMetas::CRIEI, $this->hoje));

        self::assertSame(['Voltou para mim'], $mapa['aguardando']);
        self::assertSame(['Colega atrasou', 'Está com o colega'], $mapa['andamento'], 'Em andamento ordena por prazo, atrasadas primeiro.');
        self::assertSame(['Já aprovei'], $mapa['concluidas']);
        self::assertArrayNotHasKey('atrasadas', $mapa, 'A aba Criei não usa os baldes de urgência.');
    }

    #[TestDox('Concluídas vem recolhido; os demais grupos, abertos')]
    public function testApenasConcluidasVemRecolhido(): void
    {
        $metas = [
            $this->meta('Aberta', '2026-09-01'),
            $this->meta('Fechada', '2026-09-01', Tarefa::STATUS_CONCLUIDA),
        ];

        foreach ($this->agrupador->agrupar($metas, AbaMetas::RESPONSAVEL, $this->hoje) as $grupo) {
            self::assertSame(
                $grupo->chave === 'concluidas',
                $grupo->recolhido,
                "Grupo '{$grupo->chave}' com estado de recolhimento errado.",
            );
        }
    }

    #[TestDox('Sem metas, não há grupos')]
    public function testListaVazia(): void
    {
        self::assertSame([], $this->agrupador->agrupar([], AbaMetas::RESPONSAVEL, $this->hoje));
        self::assertSame([], $this->agrupador->agrupar([], AbaMetas::CRIEI, $this->hoje));
    }

    #[TestDox('Prazo com hora não muda o balde — o corte é por dia')]
    public function testPrazoComHoraContaPeloDia(): void
    {
        $metas = [$this->meta('Vence hoje às 23h', '2026-09-10 23:00:00')];

        $mapa = $this->mapa($this->agrupador->agrupar($metas, AbaMetas::RESPONSAVEL, $this->hoje));

        self::assertSame(['Vence hoje às 23h'], $mapa['proximas'], 'Vence hoje ainda não está atrasada.');
    }
}
