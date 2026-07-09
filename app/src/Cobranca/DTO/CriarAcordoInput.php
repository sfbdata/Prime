<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada da criação de um Acordo num Caso de Cobrança (SPEC §12). O caso é resolvido por
 * id + tenant no CriarAcordoUseCase (guarda multi-tenant). O acordo substitui uma ou mais
 * obrigações do MESMO caso (invariável 13) — nunca apagadas, só marcadas (invariável 14) — e
 * gera novas obrigações (parcelas). Ao menos uma obrigação substituída e ao menos uma parcela.
 */
final class CriarAcordoInput
{
    #[Assert\NotNull(message: 'Informe o caso de cobrança.')]
    #[Assert\Positive(message: 'Caso de cobrança inválido.')]
    public ?int $casoId = null;

    #[Assert\NotNull(message: 'Informe a data do acordo.')]
    public ?\DateTimeImmutable $dataAcordo = null;

    /**
     * Ids das obrigações substituídas pelo acordo (invariável 13; substituição parcial permitida).
     *
     * @var int[]
     */
    #[Assert\Count(min: 1, minMessage: 'Informe ao menos uma obrigação a substituir.')]
    #[Assert\All([new Assert\Positive(message: 'Obrigação inválida.')])]
    public array $obrigacoesSubstituidasIds = [];

    /**
     * Parcelas geradas pelo acordo (novas obrigações do caso).
     *
     * @var ParcelaAcordoInput[]
     */
    #[Assert\Valid]
    #[Assert\Count(min: 1, minMessage: 'Informe ao menos uma parcela do acordo.')]
    public array $parcelas = [];
}
