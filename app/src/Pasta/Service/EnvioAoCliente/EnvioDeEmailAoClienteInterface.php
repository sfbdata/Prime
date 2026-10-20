<?php

declare(strict_types=1);

namespace App\Pasta\Service\EnvioAoCliente;

/**
 * A porta de envio dos e-mails ao cliente do Push (spec push-avisar-cliente). Espelha a
 * `CarteiroInterface::enviar(MensagemDeEmail): ResultadoDoEnvio` da frente 5d: quando o Carteiro
 * entrar no master, a implementação vira um adaptador para ele e nada mais muda.
 *
 * Até lá vale {@see EnvioDeEmailAoClientePeloMailer} (Symfony Mailer síncrono, com o link e os
 * cabeçalhos List-Unsubscribe do descadastro, assunto {@see self::ASSUNTO_DO_DESCADASTRO}).
 */
interface EnvioDeEmailAoClienteInterface
{
    /** O assunto do descadastro ("Não quero mais receber") dos e-mails ao cliente; escopo = o escritório. */
    public const ASSUNTO_DO_DESCADASTRO = 'push_cliente';

    /** @throws \RuntimeException o e-mail não saiu (falha do transporte) */
    public function enviar(MensagemAoCliente $mensagem): ResultadoDoEnvioAoCliente;
}
