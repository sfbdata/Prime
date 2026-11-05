<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Nonce da Content-Security-Policy, um por requisição principal (auditoria I-xss 5.04).
 *
 * O template marca cada `<script>` inline do sistema com `nonce="{{ csp_nonce() }}"`; a página que manda CSP
 * (hoje o Painel do Master e o editor do blog, `CabecalhosDeSegurancaDoMaster`) põe o mesmo valor em
 * `script-src 'nonce-...'`. Assim só roda o script inline que o próprio servidor escreveu: um `<script>` ou
 * um `on*=""` injetado por dado de usuário fica sem o nonce e o navegador o recusa. Nas páginas sem CSP o
 * atributo não tem efeito nenhum.
 *
 * O valor mora nos atributos da requisição principal, para o template e o listener da resposta lerem o mesmo.
 */
final class NonceDaCsp
{
    public const ATRIBUTO = '_csp_nonce';

    public function __construct(private readonly RequestStack $requisicoes)
    {
    }

    /** O nonce da requisição principal (criado na primeira chamada). Fora de requisição (console, e-mail), vazio. */
    public function nonce(): string
    {
        $requisicao = $this->requisicoes->getMainRequest();
        if ($requisicao === null) {
            return '';
        }

        $atual = $requisicao->attributes->get(self::ATRIBUTO);
        if (\is_string($atual) && $atual !== '') {
            return $atual;
        }

        $novo = base64_encode(random_bytes(18));
        $requisicao->attributes->set(self::ATRIBUTO, $novo);

        return $novo;
    }
}
