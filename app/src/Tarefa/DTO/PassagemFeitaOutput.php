<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

/**
 * Resultado da passagem, para o aviso (toast) da tela: o nome da etapa para onde a pasta foi (`null` quando ela
 * encerrou), a pasta e a meta nova.
 */
final readonly class PassagemFeitaOutput
{
    public function __construct(
        public ?string $proxima,
        public string $pasta,
        public ?int $novaMetaId,
    ) {
    }
}
