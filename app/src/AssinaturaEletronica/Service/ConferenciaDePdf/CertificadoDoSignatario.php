<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\ConferenciaDePdf;

/**
 * O que o motor lê do certificado (o `cert` do protótipo, mais o CPF e o CNPJ da ICP-Brasil). Datas em UTC.
 */
final readonly class CertificadoDoSignatario
{
    public function __construct(
        public string $numeroDeSerie,
        public ?string $emissor,
        public ?string $organizacaoDoEmissor,
        public ?string $nomeCompleto,
        public ?string $titular,
        public ?string $cpf,
        public ?string $cnpj,
        public \DateTimeImmutable $validoDesde,
        public \DateTimeImmutable $validoAte,
        public string $chavePublica,
        public ?string $identificadorDaChave,
    ) {
    }

    /** Emitido sob a ICP-Brasil: a organização do emissor contém "ICP-Brasil" (regra do protótipo). */
    public function icpBrasil(): bool
    {
        return $this->organizacaoDoEmissor !== null && stripos($this->organizacaoDoEmissor, 'ICP-Brasil') !== false;
    }

    /** CPF como o protótipo mostra (`***.456.789-**`); sem CPF, o CNPJ formatado (dado público). */
    public function documentoMascarado(): ?string
    {
        if ($this->cpf !== null) {
            return '***.' . substr($this->cpf, 3, 3) . '.' . substr($this->cpf, 6, 3) . '-**';
        }
        if ($this->cnpj !== null) {
            return substr($this->cnpj, 0, 2) . '.' . substr($this->cnpj, 2, 3) . '.' . substr($this->cnpj, 5, 3) . '/'
                . substr($this->cnpj, 8, 4) . '-' . substr($this->cnpj, 12, 2);
        }

        return null;
    }
}
