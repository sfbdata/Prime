<?php

declare(strict_types=1);

namespace App\Shared\Doctrine\Transacao;

/**
 * O que se sabe, depois de uma falha, sobre o destino de uma transação (E2.5).
 *
 * A distinção existe porque "o `flush()` lançou" não diz se o banco gravou. Medido no PG 15 do
 * dev: um COMMIT que chega ao servidor e perde a resposta termina `committed`, e o cliente só vê
 * uma `DriverException` genérica — o tipo da exceção não separa os casos.
 */
enum DestinoDaTransacao: string
{
    /** O COMMIT não foi enviado, ou o servidor provou que ela abortou (`pg_xact_status = aborted`). */
    case NaoConfirmada = 'nao_confirmada';

    /** O servidor provou que ela confirmou, apesar da exceção vista pelo cliente. */
    case Confirmada = 'confirmada';

    /** Não há prova para nenhum dos lados: `in progress`, sem resposta, ou transação aninhada. */
    case Incerta = 'incerta';

    /**
     * Só a ausência PROVADA de COMMIT autoriza apagar o arquivo que a transação ia referenciar.
     * Nos outros dois casos pode existir linha confirmada apontando para ele (INV-6).
     */
    public function permiteDescartarArquivoNovo(): bool
    {
        return $this === self::NaoConfirmada;
    }
}
