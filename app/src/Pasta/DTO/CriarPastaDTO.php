<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

final readonly class CriarPastaDTO
{
    /**
     * @param ?string $nup Número da pasta. `null` (ou em branco) = o sistema gera o próximo
     *                     livre do escritório — ver GerarNumeroDePasta. Continua aceitando um
     *                     número explícito porque a importação do acervo (CSV) e a descoberta
     *                     pelo Drive trazem o número da origem, que precisa ser preservado.
     */
    public function __construct(
        public ?string $nup = null,
        public ?string $nomeCliente = null,
        public ?string $nomeAcao = null,
    ) {}
}
