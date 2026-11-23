<?php

declare(strict_types=1);

namespace App\Ponto\Exception;

/**
 * A pessoa não tem vínculo ativo no escritório da sessão (aba Equipe da /ponto). Para quem pede, ela não existe aqui:
 * o controller responde 404, como para uma pessoa que não existe.
 */
final class ForaDaEquipeException extends RecusaDoPonto
{
    public const RECUSA_FORA_DA_EQUIPE = 'Pessoa não encontrada na equipe deste escritório.';

    public const CHAVE_DA_RECUSA = [
        self::RECUSA_FORA_DA_EQUIPE => 'ponto_servidor.recusa.equipe.fora_da_equipe',
    ];

    public function __construct()
    {
        parent::__construct(self::RECUSA_FORA_DA_EQUIPE);
    }
}
