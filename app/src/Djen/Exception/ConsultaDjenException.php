<?php

declare(strict_types=1);

namespace App\Djen\Exception;

use App\Djen\Enum\MotivoFalhaDjen;

/**
 * Falha ao consultar o DJEN (API pública do CNJ). Carrega o motivo classificado (enum) para que a
 * apresentação (controller/CLI) escolha a mensagem amigável e o status HTTP.
 *
 * Cobre apenas falhas EXTERNAS (rede, timeout, indisponibilidade, limite, resposta malformada).
 * Erros de programação/dados internos continuam sendo \Exception comuns.
 */
final class ConsultaDjenException extends \RuntimeException
{
    public function __construct(
        private readonly MotivoFalhaDjen $motivo,
        string $detalheTecnico = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($detalheTecnico !== '' ? $detalheTecnico : $motivo->value, 0, $previous);
    }

    public function getMotivo(): MotivoFalhaDjen
    {
        return $this->motivo;
    }
}
