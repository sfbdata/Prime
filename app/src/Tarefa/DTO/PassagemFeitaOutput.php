<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

use App\Tarefa\Enum\EtapaDaPasta;

/**
 * Resultado da passagem, para o aviso (toast) da tela: o nome da etapa para onde a pasta foi (`null` quando ela
 * encerrou), a pasta e a meta nova. `destino` é a etapa para onde a pasta foi, para o aprendizado das pendências
 * (Fase 4).
 */
final readonly class PassagemFeitaOutput
{
    public function __construct(
        public ?string $proxima,
        public string $pasta,
        public ?int $novaMetaId,
        public ?EtapaDaPasta $destino = null,
    ) {
    }
}
