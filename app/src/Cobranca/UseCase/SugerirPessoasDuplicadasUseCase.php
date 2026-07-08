<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Repository\PessoaRepository;
use App\Entity\Tenant\Tenant;

/**
 * Sugere possíveis Pessoas duplicadas antes de um novo cadastro (SPEC §7/§24).
 *
 * História: ao cadastrar alguém no domínio de cobranças, o gestor recebe sugestões de cadastros que
 * possivelmente já existem no MESMO escritório, comparando por CPF/CNPJ. É advisory — não bloqueia o
 * cadastro (CPF/CNPJ são opcionais), apenas ajuda a evitar duplicidades. A comparação ignora
 * formatação (pontos, traços, barras): casa por dígitos normalizados. A busca JAMAIS atravessa
 * tenant (invariável 24): duplicatas de outro escritório não são sugeridas. Sem CPF nem CNPJ
 * informados, não há o que deduplicar e o resultado é vazio.
 */
final class SugerirPessoasDuplicadasUseCase
{
    public function __construct(
        private readonly PessoaRepository $pessoaRepository,
    ) {
    }

    /**
     * @return list<Pessoa>
     */
    public function executar(Tenant $tenant, ?string $cpf, ?string $cnpj): array
    {
        $cpfDigitos = $this->apenasDigitos($cpf);
        $cnpjDigitos = $this->apenasDigitos($cnpj);

        if ($cpfDigitos === '' && $cnpjDigitos === '') {
            return [];
        }

        return $this->pessoaRepository->buscarPossiveisDuplicadas($tenant, $cpfDigitos, $cnpjDigitos);
    }

    private function apenasDigitos(?string $valor): string
    {
        return preg_replace('/\D/', '', (string) $valor) ?? '';
    }
}
