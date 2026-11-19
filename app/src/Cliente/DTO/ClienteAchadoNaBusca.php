<?php

declare(strict_types=1);

namespace App\Cliente\DTO;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClientePF;

/**
 * Um cliente achado pela {@see \App\Cliente\UseCase\BuscarClientesUseCase}. O documento vem cru: quem mostra decide a
 * máscara (a tela da pasta mostra o miolo; o conector não mostra nada).
 */
final class ClienteAchadoNaBusca
{
    /**
     * @param string $documento        o CPF ou CNPJ como está gravado (vazio sem documento)
     * @param string $documentoDigitos só os dígitos do documento
     * @param bool   $documentoInteiro o termo digitado era o documento inteiro deste cliente
     */
    public function __construct(
        public readonly Cliente $cliente,
        public readonly string $nome,
        public readonly string $documento,
        public readonly string $documentoDigitos,
        public readonly bool $documentoInteiro,
    ) {
    }

    public function ehPessoaFisica(): bool
    {
        return $this->cliente instanceof ClientePF;
    }
}
