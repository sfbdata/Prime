<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

/** O que o modal "Devolver para X" envia (desenho 04B L106-108, L165-167): o motivo fixo e o que falta. */
final readonly class DevolverMetaInput
{
    /** Os motivos do desenho, na ordem dos botões (04B L166). */
    public const MOTIVOS = ['Faltam informações', 'Falta documento', 'Não é da minha competência', 'Prazo inviável', 'Outro motivo'];

    /** Mínimo do "O QUE FALTA PARA AVANÇAR" (04B L167). */
    public const MINIMO_DO_QUE_FALTA = 5;

    public function __construct(
        public string $motivo,
        public string $oQueFalta,
    ) {
    }
}
