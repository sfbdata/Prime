<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

/** A marca do usuário num alerta, depois da gravação (o que a tela deve mostrar). */
final class MarcaDoAlertaOutput
{
    public function __construct(
        public readonly string $alerta,
        public readonly ?string $status,
        public readonly ?string $feedback,
    ) {
    }

    /** @return array{alerta: string, status: ?string, feedback: ?string} */
    public function paraArray(): array
    {
        return ['alerta' => $this->alerta, 'status' => $this->status, 'feedback' => $this->feedback];
    }
}
