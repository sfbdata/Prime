<?php

declare(strict_types=1);

namespace App\Pasta\Exception;

/**
 * Lançada quando se tenta salvar um modelo de checklist com um nome que o escritório
 * já usa, sem ter pedido para substituir.
 *
 * É uma falha de regra de negócio — distinta de negação de acesso — e existe separada
 * das demais justamente porque o front reage a ELA de um jeito próprio: em vez de só
 * mostrar o erro, pergunta "já existe o modelo X, substituir os itens dele?" e repete a
 * chamada com `substituir=1`. Sem uma exceção distinguível, essa pergunta teria de ser
 * adivinhada pelo texto da mensagem.
 */
final class ChecklistModeloJaExisteException extends \DomainException
{
    public function __construct(public readonly string $nome)
    {
        parent::__construct(sprintf('Já existe um modelo chamado "%s" neste escritório.', $nome));
    }
}
