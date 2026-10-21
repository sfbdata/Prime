<?php

declare(strict_types=1);

namespace App\Tarefa\Service;

use App\Entity\Tarefa\Tarefa;
use App\Tarefa\DTO\GrupoDeMetasOutput;
use App\Tarefa\Enum\AbaMetas;

/**
 * Quebra a lista plana de metas nos blocos que a tela mostra.
 *
 * Todas as abas agrupam por urgência do prazo, com os mesmos grupos, na mesma ordem (desenho 04,
 * dc L151-154: a aba Criei só muda QUAIS metas entram, não como se agrupam). A aba Criei agrupava
 * por etapa da revisão até o acerto do desenho 04; o que voltou para revisão de quem criou continua
 * no grupo "Enviadas para revisão".
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

        // A aba fica na assinatura: é o contrato com quem chama, e o agrupamento não depende mais dela.
        $grupos = $this->porUrgencia($metas, $hoje);

        return array_values(array_filter($grupos, static fn (GrupoDeMetasOutput $g): bool => !$g->estaVazio()));
    }

    /**
     * @param Tarefa[] $metas
     * @return GrupoDeMetasOutput[]
     */
    private function porUrgencia(array $metas, \DateTimeImmutable $hoje): array
    {
        $baldes = ['atrasadas' => [], 'proximas' => [], 'depois' => [], 'sem_prazo' => [], 'em_revisao' => [], 'concluidas' => []];

        foreach ($metas as $meta) {
            $baldes[self::classificar($meta, $hoje)][] = $meta;
        }

        foreach (['atrasadas', 'proximas', 'depois'] as $chave) {
            $baldes[$chave] = $this->ordenarPorPrazo($baldes[$chave]);
        }

        return [
            new GrupoDeMetasOutput('atrasadas', 'Atrasadas', 'danger', $baldes['atrasadas']),
            new GrupoDeMetasOutput('proximas', 'Vencem em 7 dias', 'warn', $baldes['proximas']),
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
     * Em que balde de urgência a meta cai — a regra única que decide o grupo da lista e, desde a
     * fidelidade de 13/10, também a pílula de status da linha (`StatusDoPrazoDaMeta`). Pública e pura
     * para que grupo e pílula nunca discordem.
     *
     * @return 'atrasadas'|'proximas'|'depois'|'sem_prazo'|'em_revisao'|'concluidas'
     */
    public static function classificar(Tarefa $meta, ?\DateTimeImmutable $hoje = null): string
    {
        $hoje   = ($hoje ?? new \DateTimeImmutable())->setTime(0, 0);
        $limite = $hoje->modify('+' . self::JANELA_PROXIMOS_DIAS . ' days');

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
     * Prazo mais apertado primeiro. Meta sem prazo vai para o fim (dentro dos baldes de urgência
     * isso não acontece; a regra fica para o método não depender de quem o chama).
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
