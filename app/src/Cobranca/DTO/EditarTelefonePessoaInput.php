<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada para CORRIGIR o número de um telefone já cadastrado (spec de qualificação §7, "excluir um
 * item é ação explícita à parte" — editar segue a mesma lógica de ação explícita).
 *
 * Editar é para o número ERRADO (dígito trocado, DDD faltando), não para a troca de telefone da
 * pessoa: quem mudou de número ganha uma linha NOVA na lista (`AdicionarTelefonePessoaUseCase`) e a
 * marcação de atual, preservando o histórico. As duas coisas parecem a mesma na tela e não são.
 *
 * A resolução de pessoa/telefone por id (guarda multi-tenant) e a sincronização da coluna-sombra
 * ocorrem no EditarTelefonePessoaUseCase.
 */
final class EditarTelefonePessoaInput
{
    #[Assert\NotNull(message: 'Informe a pessoa.')]
    #[Assert\Positive(message: 'Pessoa inválida.')]
    public ?int $pessoaId = null;

    #[Assert\NotNull(message: 'Informe o telefone.')]
    #[Assert\Positive(message: 'Telefone inválido.')]
    public ?int $telefoneId = null;

    // Mesmas regras do campo de adicionar (`AdicionarTelefonePessoaInput`): o limite de 20 é o da
    // coluna `cobranca_pessoa_telefone.numero` — sem ele o banco recusaria com erro cru.
    //
    // `normalizer: trim` NÃO é enfeite: `NotBlank` sozinho aceita `'   '` (só `''`/null/[] são vazios
    // para ele), e o UseCase apara antes de gravar — sem o normalizador, três espaços virariam um
    // telefone EM BRANCO no banco, aprovado pela validação.
    #[Assert\NotBlank(message: 'Informe o telefone.', normalizer: 'trim')]
    #[Assert\Length(max: 20, maxMessage: 'O telefone pode ter no máximo {{ limit }} caracteres.')]
    public ?string $numero = null;
}
