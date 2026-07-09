<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Enum\TipoVinculo;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada do formulário que vincula uma Pessoa a um Objeto de Cobrança (SPEC §7). Será a data_class
 * do respectivo Type. A resolução de Pessoa e Objeto por id (guarda multi-tenant — ambos no MESMO
 * tenant) e a persistência ocorrem no VincularPessoaAObjetoUseCase; aqui só se validam presença e
 * formato. A data de início, se ausente, é assumida como hoje pelo UseCase.
 */
final class VincularPessoaAObjetoInput
{
    #[Assert\NotNull(message: 'Informe a pessoa a vincular.')]
    #[Assert\Positive(message: 'Pessoa inválida.')]
    public ?int $pessoaId = null;

    #[Assert\NotNull(message: 'Informe o objeto de cobrança.')]
    #[Assert\Positive(message: 'Objeto de cobrança inválido.')]
    public ?int $objetoId = null;

    public TipoVinculo $tipoVinculo = TipoVinculo::Outro;

    public ?\DateTimeImmutable $dataInicio = null;

    public ?string $observacao = null;
}
