<?php

declare(strict_types=1);

namespace App\Pasta\Exception;

/**
 * Recusa de "Destacar comentário" (spec pasta-destacar-comentario.md): o controller traduz pelo tipo, 404 para o que
 * não é da pasta ou do escritório (não revela que existe), 403 sem edição e 422 para cor ou aba inválidas.
 */
final class DestaqueDeComentarioRecusadoException extends \DomainException
{
    public const NAO_ENCONTRADO = 404;
    public const SEM_PERMISSAO = 403;
    public const INVALIDO = 422;

    private function __construct(string $mensagem, private readonly int $tipo)
    {
        parent::__construct($mensagem);
    }

    public static function naoEncontrado(): self
    {
        return new self('Comentário não encontrado.', self::NAO_ENCONTRADO);
    }

    public static function semPermissao(): self
    {
        return new self('Só quem pode editar a pasta destaca comentários.', self::SEM_PERMISSAO);
    }

    public static function invalido(string $mensagem): self
    {
        return new self($mensagem, self::INVALIDO);
    }

    public function tipo(): int
    {
        return $this->tipo;
    }
}
