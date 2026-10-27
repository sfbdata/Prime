<?php

declare(strict_types=1);

namespace App\Auth\Exception;

use App\Auth\Service\PoliticaDeCadastro;

/**
 * A política de cadastro da plataforma (configuração `cadastro` do Master) não deixa nascer esta conta. Lançada antes
 * de qualquer gravação. Não é DomainException de propósito: os controllers que capturam DomainException para virar 422
 * não a engolem; cada porta a captura e responde 403 com o texto da política (chave de tradução, nunca texto fixo).
 */
final class CadastroNaoDisponivel extends \RuntimeException
{
    public function __construct(public readonly string $politica)
    {
        parent::__construct('Cadastro indisponível pela política da plataforma: ' . $politica . '.');
    }

    /** A chave do catálogo (bloco `entrada`) com a frase que a pessoa lê. */
    public function chaveDeTraducao(): string
    {
        return PoliticaDeCadastro::chaveDoAviso($this->politica);
    }
}
