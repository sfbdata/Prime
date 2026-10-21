<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Tarefa\Tarefa;
use App\Tarefa\DTO\AndamentoDasMetasOutput;
use App\Tarefa\DTO\GrupoDeMetasOutput;
use App\Tarefa\DTO\MinhasMetasOutput;
use App\Tarefa\Enum\AbaMetas;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Trilho do desenho 04 (P:112-127, P:176-178): "Seu andamento" e "Precisa de atenção" olham TODAS
 * as metas da pessoa, que chegam prontas do repositório (`$andamento`, `$atencao`). Os grupos da aba
 * aberta não entram na conta.
 */
#[CoversClass(MinhasMetasOutput::class)]
#[CoversClass(AndamentoDasMetasOutput::class)]
final class MinhasMetasOutputAtencaoTest extends TestCase
{
    private function meta(string $titulo, ?string $prazo, string $status = Tarefa::STATUS_PENDENTE): Tarefa
    {
        $meta = new Tarefa();
        $meta->setTitulo($titulo);
        $meta->setStatus($status);
        $meta->setPrazo($prazo === null ? null : new \DateTimeImmutable($prazo));

        return $meta;
    }

    /**
     * @param GrupoDeMetasOutput[] $grupos
     * @param Tarefa[]             $atencao
     */
    private function painel(AbaMetas $aba, array $grupos, ?AndamentoDasMetasOutput $andamento = null, array $atencao = []): MinhasMetasOutput
    {
        return new MinhasMetasOutput(
            $aba,
            $grupos,
            [],
            ['atrasadas' => 0, 'proximas' => 0, 'sem_prazo' => 0, 'aguardando_revisao' => 0],
            [],
            false,
            andamento: $andamento ?? new AndamentoDasMetasOutput(),
            atencao: $atencao,
        );
    }

    /** @param Tarefa[] $metas @return string[] */
    private function titulos(array $metas): array
    {
        return array_map(static fn (Tarefa $m): string => $m->getTitulo(), $metas);
    }

    #[TestDox('"Precisa de atenção" mostra no máximo 4 (dc `.slice(0, 4)`), na ordem em que vieram, e não há mais contagem de ocultas para "Ver todas"')]
    public function testTetoDeQuatroSemVerTodas(): void
    {
        $atrasadas = [];
        for ($i = 7; $i >= 1; --$i) {
            $atrasadas[] = $this->meta('Atrasada ' . $i, '-' . $i . ' days');
        }
        $painel = $this->painel(AbaMetas::RESPONSAVEL, [], atencao: $atrasadas);

        self::assertSame(4, MinhasMetasOutput::TETO_ATENCAO);
        self::assertSame(['Atrasada 7', 'Atrasada 6', 'Atrasada 5', 'Atrasada 4'], $this->titulos($painel->metasQuePrecisamDeAtencao()));
        self::assertFalse(method_exists($painel, 'atrasadasOcultas'), 'o desenho não tem "Ver todas as N atrasadas"');
    }

    #[TestDox('A atenção não sai dos grupos da aba: na aba Criei com uma atrasada no grupo, sem atrasadas no universo, a lista fica vazia')]
    public function testAtencaoNaoDependeDosGrupos(): void
    {
        $painel = $this->painel(AbaMetas::CRIEI, [
            new GrupoDeMetasOutput('atrasadas', 'Atrasadas', 'danger', [$this->meta('Só na aba', '-2 days')]),
        ]);

        self::assertSame([], $painel->metasQuePrecisamDeAtencao());
    }

    #[TestDox('"X de Y" e a legenda de três itens (desenho 04, P:177, sem "em aberto") vêm do andamento de todas as metas, não dos grupos da aba')]
    public function testAndamentoVemDeTodasAsMetas(): void
    {
        $grupos = [new GrupoDeMetasOutput('depois', 'Depois', 'cinza', [$this->meta('Folgada', '+20 days')])];
        $painel = $this->painel(AbaMetas::CRIEI, $grupos, new AndamentoDasMetasOutput(concluidas: 2, total: 10, atrasadas: 3, emRevisao: 1));

        self::assertSame(2, $painel->totalConcluidas());
        self::assertSame(10, $painel->totalDoAndamento());
        self::assertSame(20, $painel->percentualConcluido());
        self::assertSame(
            [['metas.trilho.legenda.concluidas', 2, 'ok'], ['metas.trilho.legenda.atrasadas', 3, 'danger'], ['metas.trilho.legenda.em_revisao', 1, 'accent']],
            array_map(static fn (array $f): array => [$f['chave'], $f['total'], $f['tom']], $painel->fatiasDoAndamento()),
        );
        self::assertSame(1, $painel->totalExibido(), 'o modo lista continua contando o que a aba mostra');
    }

    #[TestDox('Sem metas o andamento fica zerado, sem divisão por zero, e a atenção vazia')]
    public function testSemMetas(): void
    {
        $painel = $this->painel(AbaMetas::RESPONSAVEL, []);

        self::assertSame(0, $painel->percentualConcluido());
        self::assertSame([0, 0, 0], array_column($painel->fatiasDoAndamento(), 'total'));
        self::assertSame([], $painel->metasQuePrecisamDeAtencao());
    }
}
