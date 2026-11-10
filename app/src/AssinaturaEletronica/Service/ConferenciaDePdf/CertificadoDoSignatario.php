<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\ConferenciaDePdf;

/**
 * O que o motor lê do certificado (o `cert` do protótipo, mais o CPF e o CNPJ da ICP-Brasil). Datas em UTC. Tudo aqui
 * é declarado pelo próprio certificado: a cadeia até a AC raiz não é conferida antes da etapa 6 (M25). `der` é o
 * certificado inteiro, para o vínculo do signingCertificateV2 (M24).
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
        public string $der,
    ) {
    }

    /**
     * O emissor declara ser da ICP-Brasil: a organização do emissor contém "ICP-Brasil" (regra do protótipo). Só o
     * texto; a cadeia não é conferida (M25).
     */
    public function emissorDeclaraIcpBrasil(): bool
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
