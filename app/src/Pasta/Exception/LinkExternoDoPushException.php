<?php

declare(strict_types=1);

namespace App\Pasta\Exception;

/**
 * Recusa de domínio do link externo do Push. A mensagem é a que a tela mostra: os quatro bloqueios
 * de geração são os textos exatos do desenho ([EXP] 4379-4387, `pushLinkRegra`).
 */
final class LinkExternoDoPushException extends \DomainException
{
    public const SEM_PROCESSO = 'A pasta não tem processo vinculado.';
    public const PASTA_CANCELADA = 'A pasta está cancelada: o link externo fica indisponível.';
    public const PASTA_ARQUIVADA = 'A pasta está arquivada: reative para compartilhar o Push.';
    public const SEM_MOVIMENTACOES = 'Ainda não há movimentações para compartilhar.';
    // Fora do desenho (decisão do orquestrador, 19/10): dado sigiloso nunca vai a uma página pública.
    public const SEGREDO_DE_JUSTICA = 'O processo está em segredo de justiça: o link externo fica indisponível.';
    public const PASTA_TRANCADA = 'A pasta está trancada: o link externo fica indisponível.';

    public static function bloqueio(string $motivo): self
    {
        return new self($motivo);
    }

    public static function renovarRevogado(): self
    {
        return new self('Link revogado não pode ser renovado.');
    }

    public static function renovarAtivo(): self
    {
        return new self('Só um link expirado pode ser renovado.');
    }

    public static function semPedidoPendente(): self
    {
        return new self('Não há pedido de renovação pendente neste link.');
    }

    public static function pedidoIndisponivel(): self
    {
        return new self('Este link não aceita pedido de renovação.');
    }
}
