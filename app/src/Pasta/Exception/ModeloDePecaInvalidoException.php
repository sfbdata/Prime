<?php

declare(strict_types=1);

namespace App\Pasta\Exception;

/** O modelo de petição não pode ser gravado; a mensagem é a que a tela mostra. */
final class ModeloDePecaInvalidoException extends \DomainException
{
    public static function semTitulo(): self
    {
        return new self('Informe o título.');
    }

    public static function semTexto(): self
    {
        return new self('Escreva o texto do modelo.');
    }

    public static function materiaDesconhecida(): self
    {
        return new self('Escolha uma matéria da lista.');
    }

    public static function tipoDesconhecido(): self
    {
        return new self('Escolha um tipo de peça da lista.');
    }
}
