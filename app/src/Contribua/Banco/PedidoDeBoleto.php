<?php

declare(strict_types=1);

namespace App\Contribua\Banco;

/** Um boleto de apoio para uma conta (escritório). O documento e o e-mail vão só para o banco. */
final readonly class PedidoDeBoleto
{
    public function __construct(
        /** Nosso código, devolvido pelo banco em toda consulta: `BJ<conta>-B<...>`. */
        public string $codigo,
        public string $chaveDeIdempotencia,
        public string $nomeDoPagador,
        /** Só números: CPF (11) ou CNPJ (14). */
        public string $documento,
        public string $email,
        public int $valorCentavos,
        public \DateTimeImmutable $vencimento,
    ) {
    }

    public function tipoDoDocumento(): string
    {
        return 11 === strlen($this->documento) ? 'CPF' : 'CNPJ';
    }
}
