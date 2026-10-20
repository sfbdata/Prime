<?php

declare(strict_types=1);

namespace App\Cliente\DTO;

/**
 * Um alerta do bloco "Inteligência cadastral" (desenho 02, l.749 a 751). `abrirClienteId` só vem quando o outro
 * cadastro é de cliente e o usuário pode vê-lo; o controller troca pelo `abrirUrl` do contrato.
 */
final class AlertaDaInteligenciaCadastral
{
    public const HOMONIMO = 'homonimo';
    public const MESMO_DOCUMENTO = 'mesmoDocumento';

    public function __construct(
        public readonly string $tipo,
        public readonly string $ic,
        public readonly string $negrito,
        public readonly string $texto,
        public readonly ?int $abrirClienteId = null,
    ) {
    }
}
