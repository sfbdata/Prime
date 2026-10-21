<?php

declare(strict_types=1);

namespace App\Comercial\Envio;

/**
 * Transacional: do próprio ciclo da conta ou do serviço (confirmação, boas-vindas, aviso de espaço), não consulta o
 * descadastro. Lista: comunicação com descadastro (dicas, campanhas, alertas), consulta o descadastro da lista ao
 * enfileirar e de novo ao enviar (spec `comercial-e-emails.md` §3.1).
 */
enum TipoDeEnvio: string
{
    case Transacional = 'transacional';
    case Lista = 'lista';
}
