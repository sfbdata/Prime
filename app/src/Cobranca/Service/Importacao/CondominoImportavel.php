<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

use App\Cobranca\Enum\TipoVinculo;

/**
 * Uma pessoa do relatório "Dados cadastrais dos condôminos" (L.G), já normalizada para os conceitos do
 * domínio — irmão de `BoletoImportavel`, mas de CADASTRO, não de dinheiro.
 *
 * `unidade` casa com `ObjetoCobranca.identificacao` pela mesma regra do relatório de inadimplência
 * (medido: 119/119 das unidades cobradas casam exatamente).
 */
final class CondominoImportavel
{
    /**
     * @param list<string>          $telefones números na ordem da planilha; o primeiro vira o ATUAL
     * @param array<string, string> $endereco  logradouro/numero/complemento/bairro/cidade/uf/cep (só as chaves presentes)
     */
    public function __construct(
        public readonly string $unidade,
        public readonly string $nome,
        public readonly TipoVinculo $tipoVinculo,
        public readonly ?string $cpf,
        public readonly ?string $cnpj,
        public readonly ?string $email,
        public readonly array $telefones,
        public readonly array $endereco,
    ) {
    }

    /** Documento normalizado (só dígitos) para dedup por (tenant + documento); null quando não há. */
    public function documento(): ?string
    {
        return $this->cpf ?? $this->cnpj;
    }

    public function temEndereco(): bool
    {
        return $this->endereco !== [];
    }
}
