<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada da troca manual da pessoa cobrada de um Caso (SPEC §8). O gestor escolhe a nova pessoa e
 * informa o MOTIVO (obrigatório — SPEC §8.9), que fica no histórico. A troca é decisão manual e não
 * afeta dívida, pagamentos nem acordos (invariáveis 9/10). A resolução de caso e pessoa por id
 * (guarda multi-tenant) e o registro ocorrem no AlterarPessoaCobradaUseCase.
 */
final class AlterarPessoaCobradaInput
{
    #[Assert\NotNull(message: 'Informe o caso de cobrança.')]
    #[Assert\Positive(message: 'Caso de cobrança inválido.')]
    public ?int $casoId = null;

    #[Assert\NotNull(message: 'Informe a nova pessoa cobrada.')]
    #[Assert\Positive(message: 'Pessoa cobrada inválida.')]
    public ?int $novaPessoaCobradaId = null;

    #[Assert\NotBlank(message: 'Informe o motivo da alteração.')]
    #[Assert\Length(max: 255, maxMessage: 'O motivo pode ter no máximo {{ limit }} caracteres.')]
    public ?string $motivo = null;
}
