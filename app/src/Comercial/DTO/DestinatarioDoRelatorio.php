<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

/**
 * Um destinatário no visualizador da campanha (desenho 05 VS 21.10.26, L511): o e-mail (só o Master vê; o anonimizado
 * pela retenção de 24 meses aparece como "endereço anonimizado") e o estado do envio dele.
 */
final class DestinatarioDoRelatorio
{
    public function __construct(
        public readonly string $email,
        public readonly string $estado,
        public readonly bool $lido,
    ) {
    }

    /** @return array{email: string, estado: string, lido: bool} */
    public function paraArray(): array
    {
        return ['email' => $this->email, 'estado' => $this->estado, 'lido' => $this->lido];
    }
}
