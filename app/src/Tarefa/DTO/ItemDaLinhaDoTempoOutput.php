<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

use App\Pasta\DTO\TimelineItemDTO;

/**
 * Um item da linha do tempo da meta no formato único do desenho 04B de 22/10 (L70-71, L127): todo item do histórico
 * (despacho ou evento) é um cartão "DESPACHO N · tipo", numerado do mais antigo para o mais novo.
 *
 * `tipo` é chave do catálogo (a tela aplica `|trans`); `tom` escolhe a cor (`azul`, `ambar`, `roxo`, `verde`,
 * `cinza`). `despacho` só existe nas mensagens; nos eventos da auditoria é nulo.
 */
final class ItemDaLinhaDoTempoOutput
{
    public function __construct(
        public readonly int $numero,
        public readonly bool $maisRecente,
        public readonly TimelineItemDTO $item,
        public readonly ?DespachoNaLinhaDoTempoOutput $despacho,
        public readonly string $tipo,
        public readonly string $icone,
        public readonly string $tom,
    ) {
    }
}
