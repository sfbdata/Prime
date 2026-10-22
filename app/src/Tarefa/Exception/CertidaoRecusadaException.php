<?php

declare(strict_types=1);

namespace App\Tarefa\Exception;

/**
 * Lançada quando a certidão de cumprimento não pode ser gravada (spec `minhas-metas-esteira-2210.md`, C15): quem
 * pediu não é responsável nem criador da meta, ou o despacho já tem certidão. A mensagem é a que o usuário lê.
 */
final class CertidaoRecusadaException extends \DomainException
{
    private const SEM_PERMISSAO = 1;

    public static function semPermissao(): self
    {
        return new self('Só o responsável ou o criador da meta certificam o cumprimento.', self::SEM_PERMISSAO);
    }

    /** Falta de permissão vira 403 no controller; os demais casos voltam à meta com o aviso. */
    public function ehFaltaDePermissao(): bool
    {
        return $this->getCode() === self::SEM_PERMISSAO;
    }

    public static function jaCertificado(): self
    {
        return new self('Este despacho já tem certidão.');
    }

    /** Envio para revisão, devolução e mensagem antiga não têm o que cumprir (L127). */
    public static function naoCertificavel(): self
    {
        return new self('Só o despacho recebe certidão de cumprimento.');
    }

    public static function foraDoEscritorio(): self
    {
        return new self('Despacho não encontrado.');
    }
}
