<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\Service\NormalizadorDocumento;
use App\Entity\Tenant\Tenant;

/**
 * Sugere Pessoas possivelmente duplicadas por CPF/CNPJ dentro do próprio escritório (SPEC §7).
 *
 * História: ao cadastrar/editar uma Pessoa, o gestor pode ver quem já existe com o mesmo documento,
 * evitando cadastros repetidos. A busca é ASSISTIVA (advisory): NÃO decide, NÃO bloqueia, NÃO impede
 * o cadastro — apenas informa (invariável 24). CPF e CNPJ são opcionais; sem nenhum documento
 * informado não há o que comparar e o resultado é vazio (curto-circuito, sem tocar o banco). O
 * escopo é SEMPRE intra-tenant: a sugestão nunca atravessa escritórios.
 *
 * Deduplicação por DÍGITOS (invariável 24; Etapa 7): CPF/CNPJ são comparados apenas pelos dígitos,
 * então "123.456.789-01" e "12345678901" identificam a mesma pessoa. O parâmetro é reduzido a
 * dígitos aqui; a comparação no banco também ignora a formatação armazenada (ver PessoaRepository).
 */
final class SugerirPessoasDuplicadasUseCase
{
    public function __construct(
        private readonly PessoaRepository $pessoaRepository,
    ) {
    }

    /**
     * @return Pessoa[]
     */
    public function executar(Tenant $tenant, ?string $cpf, ?string $cnpj): array
    {
        $cpf = NormalizadorDocumento::apenasDigitos($cpf);
        $cnpj = NormalizadorDocumento::apenasDigitos($cnpj);

        if ($cpf === null && $cnpj === null) {
            return [];
        }

        return $this->pessoaRepository->buscarPossiveisDuplicadas($tenant, $cpf, $cnpj);
    }
}
