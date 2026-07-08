<?php

declare(strict_types=1);

namespace App\Processo\Exception;

use App\Processo\Enum\MotivoFalhaDatajud;

/**
 * Lançada quando a consulta ao Datajud (CNJ) falha por um motivo ligado à interação com a
 * API externa (rede, timeout, indisponibilidade, limite, configuração, tribunal sem base
 * pública ou resposta malformada). Carrega o motivo para que a camada de apresentação
 * escolha a mensagem amigável e o status HTTP. Distinta de TribunalNaoIdentificadoException,
 * que é erro do dado de entrada (número fora do padrão CNJ).
 */
final class ConsultaDatajudException extends \RuntimeException
{
    public function __construct(
        private readonly MotivoFalhaDatajud $motivo,
        string $detalheTecnico = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($detalheTecnico !== '' ? $detalheTecnico : $motivo->value, 0, $previous);
    }

    public function getMotivo(): MotivoFalhaDatajud
    {
        return $this->motivo;
    }
}
