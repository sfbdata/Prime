<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada do cancelamento MANUAL da judicialização de um Caso de Cobrança. O caso é resolvido por
 * id + tenant no `CancelarJudicializacaoUseCase` (guarda multi-tenant) e só cancela se estiver
 * `Judicializado`. O motivo é obrigatório e fica registrado no histórico; a normalização (trim)
 * ocorre no UseCase.
 *
 * Decisão do dono (2026-09-08): disponível SEMPRE que o caso estiver judicializado — não só quando
 * a pasta vinculada estiver excluída. Reabre de propósito a "transição única" da spec original
 * (`docs/specs/cobranca-judicializar-cria-pasta.md`).
 */
final class CancelarJudicializacaoInput
{
    #[Assert\NotNull(message: 'Informe o caso de cobrança.')]
    #[Assert\Positive(message: 'Caso de cobrança inválido.')]
    public ?int $casoId = null;

    #[Assert\NotBlank(message: 'Informe o motivo do cancelamento.', normalizer: 'trim')]
    public ?string $motivo = null;
}
