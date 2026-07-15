<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada do cadastro de um Objeto de Cobrança (SPEC §4/§5). É a data_class do formulário. A resolução
 * da Carteira dona por id (guarda multi-tenant) e a normalização dos textos (trim; null se vazio)
 * ocorrem no CriarObjetoUseCase — aqui só se validam formato e presença dos campos.
 */
final class CriarObjetoInput
{
    #[Assert\NotNull(message: 'Informe a carteira.')]
    #[Assert\Positive(message: 'Carteira inválida.')]
    public ?int $carteiraId = null;

    #[Assert\NotBlank(message: 'Informe a identificação do objeto.')]
    #[Assert\Length(max: 255, maxMessage: 'A identificação pode ter no máximo {{ limit }} caracteres.')]
    public ?string $identificacao = null;

    #[Assert\NotBlank(message: 'Informe o nome de quem será cobrado.')]
    #[Assert\Length(max: 255, maxMessage: 'O nome pode ter no máximo {{ limit }} caracteres.')]
    public ?string $nomeCobrado = null;

    public ?string $descricao = null;

    #[Assert\Length(max: 255, maxMessage: 'A referência externa pode ter no máximo {{ limit }} caracteres.')]
    public ?string $referenciaExterna = null;
}
