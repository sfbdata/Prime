<?php

declare(strict_types=1);

namespace App\Pasta\Exception;

/**
 * Quem recebeu a pasta de outra conta não pode escrever nela agora (spec acesso-restrito-e-compartilhar §4.5.1, fatia
 * 9b): a conta da pasta está suspensa (só leitura), o convite não tem a caixa da ação ("Movimentar" ou "Enviar
 * documentos") ou a aba da ação está oculta para quem recebeu. O controller responde 403.
 */
final class EscritaNaPastaRecebidaNegadaException extends \DomainException
{
}
