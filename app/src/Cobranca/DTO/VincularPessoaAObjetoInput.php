<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Enum\TipoVinculo;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada da criação de um vínculo temporal entre Pessoa e Objeto de Cobrança (SPEC §7). A resolução
 * de pessoa e objeto por id + tenant (guarda multi-tenant) e a persistência ocorrem no
 * VincularPessoaAObjetoUseCase — aqui só se validam formato e presença dos campos. A data de início é
 * opcional (default = hoje no UseCase); o vínculo nasce ABERTO (sem data de fim).
 */
final class VincularPessoaAObjetoInput
{
    #[Assert\NotNull(message: 'Informe a pessoa.')]
    #[Assert\Positive(message: 'Pessoa inválida.')]
    public ?int $pessoaId = null;

    #[Assert\NotNull(message: 'Informe o objeto de cobrança.')]
    #[Assert\Positive(message: 'Objeto de cobrança inválido.')]
    public ?int $objetoId = null;

    public TipoVinculo $tipoVinculo = TipoVinculo::Outro;

    public ?\DateTimeImmutable $dataInicio = null;

    public ?string $observacao = null;
}
