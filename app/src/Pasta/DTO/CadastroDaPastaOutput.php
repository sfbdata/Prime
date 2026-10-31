<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaReu;

/**
 * O formulário "Cadastro da pasta" da Controladoria. Unidade e titular mostram o que a advogada digitou ou, na falta,
 * o que a Cobrança sabe (`unidadeDaCobranca` / `titularDaCobranca` dizem que o valor veio de lá).
 */
final readonly class CadastroDaPastaOutput
{
    public function __construct(
        public string $reu,
        public string $cpf,
        public string $qualificacao,
        public string $email,
        public string $endereco,
        public string $contrato,
        public string $unidade,
        public string $titularExtrato,
        public string $prescricao,
        public bool $unidadeDaCobranca,
        public bool $titularDaCobranca,
        public bool $completo,
    ) {
    }

    /** @param array{unidade: ?string, titular: ?string} $cobranca */
    public static function de(Pasta $pasta, ?PastaReu $reu, array $cobranca): self
    {
        $unidade = $pasta->getUnidade();
        $titular = $pasta->getTitularExtrato();

        return new self(
            $reu?->getNome() ?? '',
            $reu?->getDocumento() ?? '',
            $reu?->getQualificacao() ?? '',
            $reu?->getEmail() ?? '',
            $reu?->getEndereco() ?? '',
            $pasta->getContrato() ?? '',
            $unidade ?? $cobranca['unidade'] ?? '',
            $titular ?? $cobranca['titular'] ?? '',
            $pasta->getPrescricaoEm()?->format('Y-m-d') ?? '',
            $unidade === null && $cobranca['unidade'] !== null,
            $titular === null && $cobranca['titular'] !== null,
            $reu?->estaCompleto() ?? false,
        );
    }

    /** @return array<string, string|bool> */
    public function paraArray(): array
    {
        return get_object_vars($this);
    }
}
