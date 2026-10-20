<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Tarefa\Tarefa;
use App\Tarefa\DTO\GrupoDeMetasOutput;
use App\Tarefa\DTO\MinhasMetasOutput;
use App\Tarefa\Enum\AbaMetas;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * "Precisa de atenção" sai das atrasadas pela regra (`AgrupadorDeMetas::classificar`), como no
 * desenho (`atencao: M.filter(m => classe(m) === 'atr')`), e não do grupo "Atrasadas", que a aba
 * Criei não tem.
 */
#[CoversClass(MinhasMetasOutput::class)]
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

    /** @param GrupoDeMetasOutput[] $grupos */
    private function painel(AbaMetas $aba, array $grupos): MinhasMetasOutput
    {
        return new MinhasMetasOutput($aba, $grupos, [], ['atrasadas' => 0, 'proximas' => 0, 'sem_prazo' => 0, 'aguardando_revisao' => 0], [], false);
    }

    /** @param Tarefa[] $metas @return string[] */
    private function titulos(array $metas): array
    {
        return array_map(static fn (Tarefa $m): string => $m->getTitulo(), $metas);
    }

    #[TestDox('Na aba Criei (grupos por etapa) as delegadas atrasadas entram, da que venceu primeiro; folgada e em revisão vencida ficam de fora')]
    public function testCrieiPelaRegra(): void
    {
        $painel = $this->painel(AbaMetas::CRIEI, [
            new GrupoDeMetasOutput('aguardando', 'Aguardando sua revisão', 'accent', [$this->meta('Revisão vencida', '-4 days', Tarefa::STATUS_EM_REVISAO)]),
            new GrupoDeMetasOutput('andamento', 'Em andamento', 'warn', [
                $this->meta('Folgada', '+10 days'),
                $this->meta('Ontem', '-1 day'),
                $this->meta('Semana passada', '-7 days'),
                $this->meta('Sem data', null),
            ]),
        ]);

        self::assertSame(['Semana passada', 'Ontem'], $this->titulos($painel->metasQuePrecisamDeAtencao()));
        self::assertSame(0, $painel->atrasadasOcultas());
        self::assertSame(2, $painel->fatiasDoAndamento()[2]['total'], 'a legenda conta as mesmas atrasadas');
    }

    #[TestDox('O teto de 5 continua, e o que passa dele vai para "Ver todas as N atrasadas"')]
    public function testTetoEOcultas(): void
    {
        $atrasadas = [];
        for ($i = 1; $i <= 7; ++$i) {
            $atrasadas[] = $this->meta('Atrasada ' . $i, '-' . $i . ' days');
        }
        $painel = $this->painel(AbaMetas::RESPONSAVEL, [new GrupoDeMetasOutput('atrasadas', 'Atrasadas', 'danger', array_reverse($atrasadas))]);

        self::assertSame(['Atrasada 7', 'Atrasada 6', 'Atrasada 5', 'Atrasada 4', 'Atrasada 3'], $this->titulos($painel->metasQuePrecisamDeAtencao()));
        self::assertSame(2, $painel->atrasadasOcultas());
    }

    #[TestDox('Sem atrasadas a lista de atenção fica vazia')]
    public function testSemAtrasadas(): void
    {
        $painel = $this->painel(AbaMetas::RESPONSAVEL, [new GrupoDeMetasOutput('depois', 'Depois', 'cinza', [$this->meta('Folgada', '+20 days')])]);

        self::assertSame([], $painel->metasQuePrecisamDeAtencao());
        self::assertSame(0, $painel->atrasadasOcultas());
    }
}
