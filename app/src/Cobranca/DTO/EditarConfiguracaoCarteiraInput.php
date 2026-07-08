<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\ModoCarteira;
use App\Cobranca\Enum\TipoVinculo;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada do formulário de edição da configuração de uma Carteira de Cobrança (SPEC §4). Será a
 * data_class do CarteiraType de edição. Espelha o CriarCarteiraInput MENOS o clienteId: o credor é
 * imutável após a criação (invariável 4), então a edição jamais o altera. A resolução da carteira por
 * id + tenant (guarda multi-tenant) e a persistência ocorrem no EditarConfiguracaoCarteiraUseCase —
 * aqui só se validam formato e presença dos campos de configuração.
 */
final class EditarConfiguracaoCarteiraInput
{
    #[Assert\NotBlank(message: 'Informe o nome da carteira.')]
    #[Assert\Length(max: 255, maxMessage: 'O nome pode ter no máximo {{ limit }} caracteres.')]
    public ?string $nome = null;

    public ModoCarteira $modo = ModoCarteira::Unico;

    public FormaHonorarios $formaHonorarios = FormaHonorarios::SemPercentual;

    /** Percentual padrão (ex.: "10.00"); casa com o decimal(5,2) da entidade — até 3 inteiros e 2 casas. */
    #[Assert\Regex(
        pattern: '/^\d{1,3}(\.\d{1,2})?$/',
        message: 'O percentual de honorários deve ser um decimal válido (ex.: 10.00).',
    )]
    public ?string $percentualHonorarios = null;

    #[Assert\PositiveOrZero(message: 'A tolerância de atraso não pode ser negativa.')]
    public int $toleranciaAtrasoDias = 0;

    public ?TipoVinculo $tipoVinculoPreferido = null;

    #[Assert\Length(max: 50, maxMessage: 'O rótulo do objeto pode ter no máximo {{ limit }} caracteres.')]
    public ?string $rotuloObjeto = null;
}
