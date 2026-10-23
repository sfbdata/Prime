<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

/**
 * Quem é avisado numa caixa da esteira (desenho 04 de 22/10, dc L61): o nome curto ("Primeiro Último") e o título
 * de advogado, se houver (`dra` / `dr`, de {@see \App\Profile\Service\TratamentoDeAdvogado}). A tela escreve
 * "Dra. Nome" / "Dr. Nome" pelo catálogo (`metas.esteira.tratamento_*`); sem título, só o nome.
 */
final class AvisadoNaEsteiraOutput
{
    public function __construct(
        public readonly string $nome,
        public readonly ?string $tratamento = null,
    ) {
    }
}
