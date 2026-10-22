<?php

declare(strict_types=1);

namespace App\Tarefa\Exception;

/**
 * Lançada quando a caixa da esteira não pode ser mudada: quem pediu não é o primário nem administra perfis
 * (D2 da spec minhas-metas-esteira-2210), ou marcou como avisado alguém que não é da equipe ativa do
 * escritório. A mensagem é a que o usuário lê.
 */
final class CaixaNaoConfiguravelException extends \DomainException
{
    public static function semPermissao(): self
    {
        return new self('Só o usuário primário e quem administra perfis configuram as caixas da esteira.');
    }

    public static function avisadoForaDaEquipe(): self
    {
        return new self('Só pessoas da equipe ativa do escritório podem ser avisadas.');
    }
}
