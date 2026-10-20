<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\Repository\ConfiguracaoDoEspacoRepository;

/**
 * O Master ajusta as faixas de alerta (desenho 05: Atenção, Alerta, Crítico, Bloqueio) e liga ou
 * desliga o bloqueio de envio (convenção: nasce desligado). Global, vale para todos os escritórios.
 * A autorização (`ROLE_SUPER_ADMIN`) é do controller que chama.
 */
final class ConfigurarFaixasDoEspacoUseCase
{
    public function __construct(
        private readonly ConfiguracaoDoEspacoRepository $configuracoes,
    ) {
    }

    public function executar(int $atencao, int $alerta, int $critico, int $bloqueio, bool $bloqueioAtivo): void
    {
        $configuracao = $this->configuracoes->atual();
        $configuracao->definirFaixas($atencao, $alerta, $critico, $bloqueio);
        $configuracao->ligarBloqueio($bloqueioAtivo);
        $this->configuracoes->salvar($configuracao, flush: true);
    }
}
