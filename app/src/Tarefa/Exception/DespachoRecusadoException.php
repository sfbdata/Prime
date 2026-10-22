<?php

declare(strict_types=1);

namespace App\Tarefa\Exception;

/** Lançada quando o Despachar não pode gravar. A mensagem é a que o usuário lê. */
final class DespachoRecusadoException extends \DomainException
{
    public static function semConteudo(): self
    {
        return new self('Escreva o despacho ou anexe um arquivo.');
    }

    public static function foraDoEscritorio(): self
    {
        return new self('Meta não encontrada.');
    }

    public static function semCodigoLivre(): self
    {
        return new self('Não foi possível registrar o despacho agora. Tente de novo.');
    }
}
