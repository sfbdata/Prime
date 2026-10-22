<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

/**
 * O que o bloco DESPACHO precisa do servidor (desenho 04B de 22/10, L59 e L118; C12): o prazo padrão de 5 dias
 * úteis pela regra do escritório, o mínimo (hoje) e os dias não úteis dos próximos meses, para a tela recontar
 * o "N dias úteis" ao mudar a data. Datas em `Y-m-d`, como o `<input type="date">`.
 *
 * @phpstan-type Natureza array{valor: string, rotulo: string, inicio: string}
 */
final class FormularioDoDespachoOutput
{
    /**
     * @param list<string>   $naoUteis
     * @param list<Natureza> $naturezas
     */
    public function __construct(
        public readonly string $hoje,
        public readonly string $prazoPadrao,
        public readonly int $uteisDoPrazoPadrao,
        public readonly array $naoUteis,
        public readonly array $naturezas,
    ) {
    }
}
