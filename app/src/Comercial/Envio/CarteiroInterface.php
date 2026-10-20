<?php

declare(strict_types=1);

namespace App\Comercial\Envio;

/**
 * A porta única de envio dos e-mails desta frente e dos consumidores (Push da 0d, alertas de vagas da a7). Spec
 * `comercial-e-emails.md` §3.1.
 *
 * Grava o envio e o põe na fila `comercial`, numa transação só da conexão (nunca pelo `flush` do EntityManager: não
 * grava as entidades pendentes do chamador). Em `lista`, consulta o descadastro antes; descadastrado, registra e não
 * enfileira. O envio de fato é do worker, com a vazão (20/min, 1.500/dia), o layout, o rodapé legal e o
 * List-Unsubscribe.
 *
 */
interface CarteiroInterface
{
    public function enviar(MensagemDeEmail $mensagem): ResultadoDoEnvio;
}
