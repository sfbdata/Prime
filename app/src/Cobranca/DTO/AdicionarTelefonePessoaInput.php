<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Enum\TipoTelefone;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada para adicionar um novo telefone à lista de uma Pessoa (spec de qualificação §4). Será a
 * data_class do respectivo Type (Onda de UI). A resolução da pessoa por id (guarda multi-tenant), a
 * regra de "primeiro item nasce atual" e a persistência ocorrem no AdicionarTelefonePessoaUseCase.
 */
final class AdicionarTelefonePessoaInput
{
    #[Assert\NotNull(message: 'Informe a pessoa.')]
    #[Assert\Positive(message: 'Pessoa inválida.')]
    public ?int $pessoaId = null;

    // `normalizer: trim` (2026-07-28): `NotBlank` sozinho aceita `'   '` — só `''`/null/[] são vazios
    // para ele — e o UseCase apara antes de gravar. Sem o normalizador, três espaços entravam como
    // telefone EM BRANCO. Achado ao cobrir a rota de EDITAR, que tem o mesmo par validação+trim.
    #[Assert\NotBlank(message: 'Informe o telefone.', normalizer: 'trim')]
    #[Assert\Length(max: 20, maxMessage: 'O telefone pode ter no máximo {{ limit }} caracteres.')]
    public ?string $numero = null;

    /**
     * WhatsApp ou telefone comum (2026-07-28). NÃO é `NotNull`: o form já nasce com `Fixo` marcado, e
     * exigir a escolha só transformaria "esqueci de clicar" em erro de validação para um campo que
     * tem default óbvio. Nulo grava nulo — o mesmo estado do telefone legado, que não afirma nada.
     */
    public ?TipoTelefone $tipo = TipoTelefone::Fixo;
}
