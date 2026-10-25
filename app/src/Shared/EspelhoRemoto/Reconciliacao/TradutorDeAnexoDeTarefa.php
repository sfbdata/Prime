<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Reconciliacao;

/**
 * Traduz o valor de `tarefa_mensagem.arquivo_anexo` no nome da chave. A regra é do domínio Tarefa
 * (`ChavesDeTarefa`); Shared não depende de domínio, então ela entra por aqui (`src/Shared/CLAUDE.md`).
 */
interface TradutorDeAnexoDeTarefa
{
    /** O `<nome>` do valor, ou null quando o valor não é endereçável. */
    public function nomeDoValorDaColuna(?string $valor): ?string;
}
