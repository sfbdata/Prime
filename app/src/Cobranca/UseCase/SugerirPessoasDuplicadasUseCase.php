<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Repository\PessoaRepository;
use App\Entity\Tenant\Tenant;

/**
 * Sugere Pessoas possivelmente duplicadas por CPF/CNPJ dentro do próprio escritório (SPEC §7).
 *
 * História: ao cadastrar/editar uma Pessoa, o gestor pode ver quem já existe com o mesmo documento,
 * evitando cadastros repetidos. A busca é ASSISTIVA (advisory): NÃO decide, NÃO bloqueia, NÃO impede
 * o cadastro — apenas informa (invariável 24). CPF e CNPJ são opcionais; sem nenhum documento
 * informado não há o que comparar e o resultado é vazio (curto-circuito, sem tocar o banco). O
 * escopo é SEMPRE intra-tenant: a sugestão nunca atravessa escritórios.
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
        $cpf = $this->normalizar($cpf);
        $cnpj = $this->normalizar($cnpj);

        if ($cpf === null && $cnpj === null) {
            return [];
        }

        return $this->pessoaRepository->buscarPossiveisDuplicadas($tenant, $cpf, $cnpj);
    }

    private function normalizar(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $valor = trim($valor);

        return $valor !== '' ? $valor : null;
    }
}
