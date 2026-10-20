<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Pasta\Entity\EnvioAoCliente;

/** Uma linha do "Histórico de envios" (desenho `hist`, dc L.5258): ícone, título e "data hora · para". */
final readonly class EnvioAoClienteOutput
{
    public function __construct(
        public string $icone,
        public string $titulo,
        public string $sub,
    ) {
    }

    public static function de(EnvioAoCliente $envio): self
    {
        return new self(
            $envio->getTipo()->icone(),
            $envio->getTipo()->titulo($envio->getDescricao()),
            $envio->getEm()->format('d/m/Y H:i') . ' · ' . implode(', ', $envio->getPara()),
        );
    }
}
