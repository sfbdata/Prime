<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada do registro de uma tentativa de cobrança (SPEC §10): boleto/valor atualizado enviado,
 * eventualmente com um novo prazo combinado. É movimento SÓ de histórico — NÃO cria obrigação nem
 * altera o valor/prazo ORIGINAIS da dívida (invariável 20). A resolução do caso por id (guarda
 * multi-tenant) e o registro do evento ocorrem no RegistrarTentativaCobrancaUseCase.
 */
final class RegistrarTentativaCobrancaInput
{
    #[Assert\NotNull(message: 'Informe o caso de cobrança.')]
    #[Assert\Positive(message: 'Caso de cobrança inválido.')]
    public ?int $casoId = null;

    /** Valor solicitado na tentativa, em CENTAVOS; opcional (registro informativo). */
    #[Assert\Positive(message: 'O valor solicitado deve ser positivo.')]
    public ?int $valorSolicitado = null;

    /** Novo prazo combinado nesta tentativa; opcional — não substitui o vencimento original. */
    public ?\DateTimeImmutable $novoPrazo = null;

    public ?string $observacao = null;
}
