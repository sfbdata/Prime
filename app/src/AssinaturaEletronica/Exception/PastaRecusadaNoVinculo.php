<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * A pasta escolhida no "Vincular à pasta" que a pessoa não pode usar (spec assinatura-documentos-etapa-3, E3-10).
 * Pasta inexistente, de outro escritório ou que a pessoa nem vê (cadeado): 404, sem dizer qual. Pasta que ela vê mas
 * não edita: 403. A mensagem é a chave do catálogo `assinatura_eletronica`.
 */
final class PastaRecusadaNoVinculo extends \RuntimeException
{
    private function __construct(string $chave, public readonly int $status)
    {
        parent::__construct($chave);
    }

    public static function naoEncontrada(): self
    {
        return new self('assinatura_eletronica.erro.vinculo_pasta_nao_encontrada', Response::HTTP_NOT_FOUND);
    }

    public static function semPermissao(): self
    {
        return new self('assinatura_eletronica.erro.vinculo_sem_permissao', Response::HTTP_FORBIDDEN);
    }
}
