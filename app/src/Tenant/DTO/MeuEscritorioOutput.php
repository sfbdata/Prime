<?php

declare(strict_types=1);

namespace App\Tenant\DTO;

/**
 * O que a tela Meu Escritório mostra. Campo opcional vazio vem null (a tela escreve "Não informado").
 * `doc` são só os dígitos (11 do CPF ou 14 do CNPJ); `docFormatado` a máscara.
 */
final readonly class MeuEscritorioOutput
{
    public function __construct(
        public string $codigo,
        public string $nome,
        public ?string $doc,
        public ?string $docFormatado,
        public ?string $oab,
        public ?string $email,
        public ?string $telefone,
        public ?string $cep,
        public ?string $endereco,
        public ?string $cidadeUf,
        public \DateTimeImmutable $criadoEm,
        public bool $ativo,
        public ?string $statusDica,
        public ?string $alteradoPor,
        public ?\DateTimeImmutable $alteradoEm,
        public bool $temLogo,
        public bool $podeEditar,
        public bool $podeExcluir,
        public bool $driveConectado,
    ) {
    }

    /** @return array<string, mixed> */
    public function paraArray(): array
    {
        return [
            'codigo' => $this->codigo,
            'nome' => $this->nome,
            'doc' => $this->doc,
            'docFormatado' => $this->docFormatado,
            'oab' => $this->oab,
            'email' => $this->email,
            'telefone' => $this->telefone,
            'cep' => $this->cep,
            'endereco' => $this->endereco,
            'cidadeUf' => $this->cidadeUf,
            'criadoEm' => $this->criadoEm->format('d/m/Y H:i:s'),
            'ativo' => $this->ativo,
            'statusDica' => $this->statusDica,
            'alteradoPor' => $this->alteradoPor,
            'alteradoEm' => $this->alteradoEm?->format('d/m/Y H:i'),
            'temLogo' => $this->temLogo,
            'podeEditar' => $this->podeEditar,
            'podeExcluir' => $this->podeExcluir,
            'driveConectado' => $this->driveConectado,
        ];
    }
}
