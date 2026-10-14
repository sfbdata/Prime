<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

use App\Entity\Tarefa\Tarefa;
use App\Tarefa\Service\AgrupadorDeMetas;

/**
 * Texto do prazo na linha de Minhas Metas (desenho de 13/10, `04 - Minhas Metas 1.0.dc.html`, JS
 * `prazoTxt`/`prazoCor`/`prazoPeso`): "concluída" · "sem prazo definido" · "N dia(s) atrasado" ·
 * "vence hoje" · "vence em N dia(s) · dd/mm/aaaa".
 *
 * O destaque (`atrasado`) vem do balde de `AgrupadorDeMetas::classificar`, a mesma regra do grupo e da
 * pílula: só a meta do grupo "Atrasadas" fica vermelha. A em revisão de prazo vencido mostra os dias, sem
 * destaque — saiu da fila de quem executa, e o desenho faz o mesmo.
 *
 * Não reaproveita `Tarefa::getPrazoInfo`: aquele texto ("Vence hoje", "3 dias") é o da tela da meta e
 * da de Equipe, e mudar ali mudaria as duas.
 */
final class TextoDoPrazoDaMeta
{
    private function __construct(
        public readonly string $texto,
        public readonly bool $atrasado,
    ) {
    }

    public static function de(Tarefa $meta, ?\DateTimeImmutable $hoje = null): self
    {
        $hoje  = ($hoje ?? new \DateTimeImmutable())->setTime(0, 0);
        $balde = AgrupadorDeMetas::classificar($meta, $hoje);

        if ($balde === 'concluidas') {
            return new self('concluída', false);
        }

        $prazo = $meta->getPrazo();
        if ($prazo === null) {
            return new self('sem prazo definido', false);
        }

        $prazo = $prazo->setTime(0, 0);
        $dias  = (int) $hoje->diff($prazo)->format('%r%a');

        $texto = match (true) {
            $dias < 0   => -$dias . ' dia(s) atrasado',
            $dias === 0 => 'vence hoje',
            default     => 'vence em ' . $dias . ' dia(s) · ' . $prazo->format('d/m/Y'),
        };

        return new self($texto, $balde === 'atrasadas');
    }
}
