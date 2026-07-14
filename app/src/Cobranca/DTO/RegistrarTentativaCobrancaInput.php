<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Enum\CanalContato;
use App\Cobranca\Enum\ResultadoContato;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada do registro de um CONTATO de cobrança (ajuste 2026-07, supera SPEC §10): o gestor informa
 * quando e por qual canal falou (ou tentou falar) com o cobrado e o desfecho — tipicamente "Não
 * atendido". É movimento SÓ de histórico — NÃO cria obrigação nem altera o valor/prazo ORIGINAIS da
 * dívida (invariável 20). A resolução do caso por id (guarda multi-tenant) e o registro do evento
 * ocorrem no RegistrarTentativaCobrancaUseCase.
 */
final class RegistrarTentativaCobrancaInput
{
    #[Assert\NotNull(message: 'Informe o caso de cobrança.')]
    #[Assert\Positive(message: 'Caso de cobrança inválido.')]
    public ?int $casoId = null;

    /** Data e hora em que o contato foi feito; o form pré-preenche com "agora" (editável). */
    #[Assert\NotNull(message: 'Informe a data e hora do contato.')]
    public ?\DateTimeImmutable $dataContato = null;

    /** Canal pelo qual o contato foi feito/tentado (telefone, WhatsApp, e-mail, SMS). */
    #[Assert\NotNull(message: 'Informe o canal do contato.')]
    public ?CanalContato $canal = null;

    /** Desfecho do contato; default operacional "Não atendido". */
    #[Assert\NotNull(message: 'Informe o resultado do contato.')]
    public ?ResultadoContato $resultado = ResultadoContato::NaoAtendido;

    public ?string $observacao = null;
}
