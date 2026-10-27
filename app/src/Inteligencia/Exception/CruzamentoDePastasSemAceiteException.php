<?php

declare(strict_types=1);

namespace App\Inteligencia\Exception;

/**
 * O escritório não deu o aceite da BlueJus IA (`inteligencia_configuracao.consentimento_envio_externo_em`), e sem ele a IA
 * não lê as outras pastas dos clientes (PR 4c do chat da Central, D12/LGPD). Mensagem fixa: nunca leva id, NUP nem nome.
 */
final class CruzamentoDePastasSemAceiteException extends \DomainException
{
    public const MOTIVO = 'cruzamento_sem_consentimento';

    public const MENSAGEM = 'O escritório ainda não autorizou a IA a ler as pastas dos clientes. O administrador confere o aceite na configuração da BlueJus IA.';

    public function __construct()
    {
        parent::__construct(self::MENSAGEM);
    }

    public function motivo(): string
    {
        return self::MOTIVO;
    }
}
