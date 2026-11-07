<?php

declare(strict_types=1);

namespace App\ChatIa\Exception;

/**
 * A pessoa não pode consultar prazos e metas pelo chat: sem o módulo Tarefas (a guarda da tela Minhas Metas) ou o escritório
 * sem o aceite de envio de dados à I.A. Não vira resposta HTTP: a consulta recusada entra no prompt como uma linha fixa, sem dado.
 */
final class SemAcessoAsMetasNoChatException extends \DomainException
{
    public const MENSAGEM = 'a pessoa não tem acesso às metas por aqui';

    public function __construct()
    {
        parent::__construct(self::MENSAGEM);
    }
}
