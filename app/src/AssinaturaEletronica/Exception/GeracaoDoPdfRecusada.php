<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Exception;

/**
 * "Baixar PDF com selo" recusado agora porque gerar pesa (cerca de 1,2 s por página): vira 429 `{"erro": ...}` com o
 * texto do catálogo da tela que {@see chave()} devolve, e a tela mostra esse texto.
 */
abstract class GeracaoDoPdfRecusada extends \RuntimeException
{
    public function __construct(string $chave)
    {
        parent::__construct($chave);
    }

    /** A chave do texto em `assinatura_eletronica_tela.js.pdf`, que chega também ao assinatura-publica.js. */
    public function chave(): string
    {
        return $this->getMessage();
    }
}
