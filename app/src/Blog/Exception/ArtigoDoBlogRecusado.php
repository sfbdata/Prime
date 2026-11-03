<?php

declare(strict_types=1);

namespace App\Blog\Exception;

/**
 * Recusa ao salvar um artigo (o controller devolve 422 com a mensagem no campo): slug repetido ou troca do slug de um
 * artigo que já foi ao ar sem a confirmação de que o link antigo vai quebrar.
 */
final class ArtigoDoBlogRecusado extends \DomainException
{
    public function __construct(
        string $mensagem,
        public readonly string $campo,
    ) {
        parent::__construct($mensagem);
    }

    public static function slugRepetido(string $slug): self
    {
        return new self(sprintf('Já existe um artigo com o slug "%s".', $slug), 'slug');
    }

    public static function trocaDeSlugSemConfirmacao(): self
    {
        return new self('Este artigo já foi ao ar: trocar o slug quebra o link antigo. Confirme a troca para salvar.', 'slug');
    }
}
