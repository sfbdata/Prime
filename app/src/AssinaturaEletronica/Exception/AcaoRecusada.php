<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Exception;

/**
 * Ação que a situação do documento ou os dados enviados não permitem: vira 422 com `{erro}`. A mensagem é a chave do
 * catálogo `assinatura_eletronica` (o controller traduz), com os parâmetros do texto.
 */
final class AcaoRecusada extends \DomainException
{
    /**
     * @param array<string, int|string> $parametros
     */
    public function __construct(
        string $chave,
        public readonly array $parametros = [],
    ) {
        parent::__construct($chave);
    }
}
