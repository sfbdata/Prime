<?php

declare(strict_types=1);

namespace App\Tests\Shared\Doubles;

use App\Shared\Doctrine\Transacao\ConsultaDeDestinoDaTransacao;
use App\Shared\Doctrine\Transacao\DestinoDaTransacao;

/**
 * O banco "responde" o destino que o teste escolher (E2.5).
 *
 * Sob o DAMA a resposta verdadeira é sempre "em andamento" — a transação do teste nunca termina.
 * Para exercitar os ramos "o banco provou aborted" e "o banco provou committed" com o resto do
 * sistema real, a consulta é trocada por esta. Registra os xids perguntados.
 */
final class ConsultaDeDestinoFixa implements ConsultaDeDestinoDaTransacao
{
    /** @var list<string> */
    public array $perguntados = [];

    public function __construct(private readonly DestinoDaTransacao $destino)
    {
    }

    public function destinoDe(string $xid): DestinoDaTransacao
    {
        $this->perguntados[] = $xid;

        return $this->destino;
    }
}
