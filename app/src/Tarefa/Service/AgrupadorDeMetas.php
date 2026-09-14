<?php

declare(strict_types=1);

namespace App\Tarefa\Service;

use App\Entity\Tarefa\Tarefa;
use App\Tarefa\DTO\GrupoDeMetasOutput;
use App\Tarefa\Enum\AbaMetas;

/**
 * Quebra a lista plana de metas nos blocos que a tela mostra.
 *
 * Duas leituras diferentes, porque as duas perguntas são diferentes:
 *  - quem EXECUTA quer saber o que vence primeiro  → agrupa por urgência do prazo;
 *  - quem DELEGOU quer saber o que voltou para ele → agrupa por etapa da revisão.
 *
 * Invariante: cada meta cai em EXATAMENTE um grupo. `em_revisao` e `concluida` vencem a
 * urgência do prazo — uma meta entregue e atrasada apareceria nos dois blocos se a ordem de
 * decisão fosse outra, e o usuário contaria a mesma pendência duas vezes.
 */
final class AgrupadorDeMetas
{
    private const JANELA_PROXIMOS_DIAS = 7;

    /**
     * @param Tarefa[] $metas
     * @return GrupoDeMetasOutput[]  só os grupos não vazios, na ordem de leitura da tela
     */
    public function agrupar(array $metas, AbaMetas $aba, ?\DateTimeImmutable $hoje = null): array
    {
        $hoje = ($hoje ?? new \DateTimeImmutable())->setTime(0, 0);

        $grupos = $aba->agrupaPorEtapaDaRevisao()
            ? $this->porEtapaDaRevisao($metas, $hoje)
            : $this->porUrgencia($metas, $hoje);

        return array_values(array_filter($grupos, static fn (GrupoDeMetasOutput $g): bool => !$g->estaVazio()));
    }

    /**
     * @param Tarefa[] $metas
     * @return GrupoDeMetasOutput[]
     */
    private function porUrgencia(array $metas, \DateTimeImmutable $hoje): array
    {
        $limite = $hoje->modify('+' . self::JANELA_PROXIMOS_DIAS . ' days');

        $baldes = ['atrasadas' => [], 'proximas' => [], 'depois' => [], 'sem_prazo' => [], 'em_revisao' => [], 'concluidas' => []];

        foreach ($metas as $meta) {
            $baldes[$this->baldeDaUrgencia($meta, $hoje, $limite)][] = $meta;
        }

        foreach (['atrasadas', 'proximas', 'depois'] as $chave) {
            $baldes[$chave] = $this->ordenarPorPrazo($baldes[$chave]);
        }

        return [
            new GrupoDeMetasOutput('atrasadas', 'Atrasadas', 'danger', $baldes['atrasadas']),
            new GrupoDeMetasOutput('proximas', 'Próximos 7 dias', 'warn', $baldes['proximas']),
            new GrupoDeMetasOutput('depois', 'Depois', 'cinza', $baldes['depois']),
            new GrupoDeMetasOutput('sem_prazo', 'Sem prazo', 'cinza', $baldes['sem_prazo']),
            new GrupoDeMetasOutput(
                'em_revisao',
                'Enviadas para revisão',
                'accent',
                $baldes['em_revisao'],
                nota: 'aguardando quem criou a meta',
            ),
            new GrupoDeMetasOutput(
                'concluidas',
                'Concluídas',
                'ok',
                $baldes['concluidas'],
                recolhido: true,
                nota: 'nos últimos 30 dias',
            ),
        ];
    }

    /**
     * @param Tarefa[] $metas
     * @return GrupoDeMetasOutput[]
     */
    private function porEtapaDaRevisao(array $metas, \DateTimeImmutable $hoje): array
    {
        $baldes = ['aguardando' => [], 'andamento' => [], 'concluidas' => []];

        foreach ($metas as $meta) {
            $baldes[match ($meta->getStatus()) {
                Tarefa::STATUS_CONCLUIDA  => 'concluidas',
                Tarefa::STATUS_EM_REVISAO => 'aguardando',
                default                   => 'andamento',
            }][] = $meta;
        }

        return [
            new GrupoDeMetasOutput(
                'aguardando',
                'Aguardando sua revisão',
                'accent',
                $baldes['aguardando'],
                nota: 'o responsável terminou e enviou para você',
            ),
            new GrupoDeMetasOutput(
                'andamento',
                'Em andamento',
                'warn',
                $this->ordenarPorPrazo($baldes['andamento']),
                nota: 'com quem você delegou',
            ),
            new GrupoDeMetasOutput(
                'concluidas',
                'Concluídas',
                'ok',
                $baldes['concluidas'],
                recolhido: true,
                nota: 'nos últimos 30 dias',
            ),
        ];
    }

    private function baldeDaUrgencia(Tarefa $meta, \DateTimeImmutable $hoje, \DateTimeImmutable $limite): string
    {
        if ($meta->getStatus() === Tarefa::STATUS_CONCLUIDA) {
            return 'concluidas';
        }

        // Entregue: a bola está com quem criou. Sai da fila de trabalho mesmo se o prazo
        // já passou — cobrar do responsável algo que não está mais na mão dele é ruído.
        if ($meta->getStatus() === Tarefa::STATUS_EM_REVISAO) {
            return 'em_revisao';
        }

        $prazo = $meta->getPrazo();
        if ($prazo === null) {
            return 'sem_prazo';
        }

        $prazo = $prazo->setTime(0, 0);

        if ($prazo < $hoje) {
            return 'atrasadas';
        }

        return $prazo <= $limite ? 'proximas' : 'depois';
    }

    /**
     * Prazo mais apertado primeiro. Meta sem prazo vai para o fim — dentro dos baldes de
     * urgência isso não acontece, mas o método também serve ao "Em andamento" da aba Criei,
     * que mistura os dois.
     *
     * @param Tarefa[] $metas
     * @return Tarefa[]
     */
    private function ordenarPorPrazo(array $metas): array
    {
        usort($metas, static function (Tarefa $a, Tarefa $b): int {
            $pa = $a->getPrazo();
            $pb = $b->getPrazo();

            if ($pa === null && $pb === null) {
                return $b->getDataCriacao() <=> $a->getDataCriacao();
            }
            if ($pa === null) {
                return 1;
            }
            if ($pb === null) {
                return -1;
            }

            return $pa <=> $pb;
        });

        return $metas;
    }
}
