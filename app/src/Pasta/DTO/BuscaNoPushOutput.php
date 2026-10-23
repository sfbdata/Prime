<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Djen\DTO\ResultadoSincronizacao;

/**
 * O retorno do "Buscar agora" do painel do Push (spec push-painel-monitoramento §2): a mensagem que
 * a página mostra depois de recarregar a aba, e o tom dela.
 */
final readonly class BuscaNoPushOutput
{
    public const TOM_SUCESSO = 'success';
    public const TOM_AVISO   = 'warning';

    private function __construct(
        public int $novas,
        public int $adiados,
        public string $mensagem,
        public string $tom,
    ) {
    }

    public static function doResultado(ResultadoSincronizacao $resultado): self
    {
        $novas = $resultado->getNovas();
        $adiados = $resultado->getProcessosAdiados();

        if ($resultado->temFalhas() && $resultado->getProcessosConsultados() === 0) {
            return new self($novas, $adiados, 'Não foi possível consultar o DJEN agora. Tente novamente em instantes.', self::TOM_AVISO);
        }

        $mensagem = $novas > 0 ? $novas . ' movimentação(ões) nova(s)' : 'Nenhuma movimentação nova';
        if ($adiados > 0) {
            $motivo = $resultado->temFalhas() ? '' : ' (limite de consultas do DJEN)';
            $mensagem .= '. ' . $adiados . ' processo(s) não foram consultados agora' . $motivo . '; a captação automática diária consulta todos.';
        }

        return new self($novas, $adiados, $mensagem, $adiados > 0 ? self::TOM_AVISO : self::TOM_SUCESSO);
    }
}
