<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Master\Entity\ConfiguracaoGlobal;
use App\Master\Repository\ConfiguracaoGlobalRepository;

/**
 * Os testes de horário futuro congelam o relógio em hoje 12:00, mas a sessão do login carrega a hora real.
 * O ExpiracaoDaSessaoListener mede `relógio - último uso da sessão`: antes do meio-dia o relógio congelado fica
 * adiantado e a sessão "expira por inatividade" (a requisição cai no /login). O adiantamento passa de 12 h no
 * máximo; o prazo de 1 dia (teto do app.sessao_gc_segundos) cobre qualquer hora de execução.
 */
trait LiberaSessaoDoRelogioFixoTrait
{
    private function liberarSessaoDoRelogioFixo(): void
    {
        $repositorio  = static::getContainer()->get(ConfiguracaoGlobalRepository::class);
        $configuracao = $repositorio->buscarUnica() ?? new ConfiguracaoGlobal();
        $configuracao->definirValores(['sessao' => '1440'], null, new \DateTimeImmutable());
        $repositorio->salvar($configuracao, true);
    }
}
