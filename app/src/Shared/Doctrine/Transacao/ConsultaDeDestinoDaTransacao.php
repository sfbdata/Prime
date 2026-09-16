<?php

declare(strict_types=1);

namespace App\Shared\Doctrine\Transacao;

/**
 * Pergunta ao banco o destino de uma transação cujo COMMIT falhou do ponto de vista do cliente.
 *
 * Porta separada para que o ramo "o COMMIT lançou" possa ser exercitado nos testes: sob o DAMA a
 * transação real do teste nunca termina, e a resposta verdadeira ali é sempre "em andamento".
 */
interface ConsultaDeDestinoDaTransacao
{
    /**
     * @param string $xid O texto devolvido por `pg_current_xact_id()` dentro da transação.
     *
     * Nunca lança: qualquer dúvida é {@see DestinoDaTransacao::Incerta}.
     */
    public function destinoDe(string $xid): DestinoDaTransacao;
}
