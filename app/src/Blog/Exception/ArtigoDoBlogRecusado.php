<?php

declare(strict_types=1);

namespace App\Blog\Exception;

/**
 * Recusa ao salvar um artigo (o controller devolve 422 com a mensagem no campo): slug repetido, troca do slug de um
 * artigo que já foi ao ar sem a confirmação de que o link antigo vai quebrar, capa que não é deste artigo, ou imagem
 * enviada fora das regras (tipo, tamanho, dimensões).
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

    public static function capaDeOutroArtigo(): self
    {
        return new self('A imagem de compartilhamento precisa ser uma imagem enviada para este artigo.', 'imagemChave');
    }

    public static function imagem(string $mensagem): self
    {
        return new self($mensagem, 'imagem');
    }
}
