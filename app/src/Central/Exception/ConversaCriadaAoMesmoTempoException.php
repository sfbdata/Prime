<?php

declare(strict_types=1);

namespace App\Central\Exception;

/**
 * As duas pessoas abriram a conversa no mesmo instante e o índice único do par recusou a segunda linha. Não
 * houve dano (a conversa existe); o controller responde 409 e a tela tenta de novo, achando a que ficou.
 */
final class ConversaCriadaAoMesmoTempoException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('A conversa acabou de ser criada. Tente de novo.');
    }
}
