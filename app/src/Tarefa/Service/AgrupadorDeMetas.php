<?php

declare(strict_types=1);

namespace App\Tarefa\Service;

use App\Entity\Tarefa\Tarefa;
use App\Tarefa\DTO\GrupoDeMetasOutput;
use App\Tarefa\Enum\AbaMetas;

/**
 * Quebra a lista plana de metas nos blocos que a tela mostra.
 *
 * Todas as abas agrupam por urgência do prazo, com os mesmos grupos, na mesma ordem, sem nota e todos
 * abertos (desenho 04, dc P:148, P:154 e P:163; LIS-07/08/10, decisão do dono de 21/10): Atrasadas,
 * Vencem em 7 dias, Aguardando revisão, No prazo, Sem prazo, Concluídas. A aba só muda QUAIS metas
 * entram. As chaves (`depois`, `em_revisao`...) ficaram as de antes porque CSS, JS e seletores as usam;
 * o rótulo é chave do catálogo (`metas.grupos.*`), traduzida no Twig.
 *
 * Invariante: cada meta cai em EXATAMENTE um grupo. `em_revisao` e `concluida` vencem a
 * urgência do prazo — uma meta entregue e atrasada apareceria nos dois blocos se a ordem de
 * decisão fosse outra, e o usuário contaria a mesma pendência duas vezes.
 */
final class AgrupadorDeMetas
{
    private const JANELA_PROXIMOS_DIAS = 7;

    /** Chave do grupo => tom da linha, na ordem de leitura da tela (dc `G`, P:154). */
    private const GRUPOS = [
        'atrasadas'  => 'danger',
        'proximas'   => 'warn',
        'em_revisao' => 'accent',
        'depois'     => 'cinza',
        'sem_prazo'  => 'cinza',
        'concluidas' => 'ok',
    ];

    /**
     * A aba não muda os grupos (nome, ordem, cor) desde o desenho 04; fica na assinatura porque é
     * o contrato do UseCase.
     *
     * @param Tarefa[] $metas
     * @return GrupoDeMetasOutput[]  só os grupos não vazios, na ordem de leitura da tela
     */
    public function agrupar(array $metas, AbaMetas $aba, ?\DateTimeImmutable $hoje = null): array
    {
        $hoje = ($hoje ?? new \DateTimeImmutable())->setTime(0, 0);

        $baldes = array_fill_keys(array_keys(self::GRUPOS), []);
        foreach ($metas as $meta) {
            $baldes[self::classificar($meta, $hoje)][] = $meta;
        }

        $grupos = [];
        foreach (self::GRUPOS as $chave => $tom) {
            if ($baldes[$chave] === []) {
                continue;
            }
            $doGrupo  = in_array($chave, ['atrasadas', 'proximas', 'depois'], true) ? $this->ordenarPorPrazo($baldes[$chave]) : $baldes[$chave];
            $grupos[] = new GrupoDeMetasOutput($chave, 'metas.grupos.' . $chave, $tom, $doGrupo);
        }

        return $grupos;
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
