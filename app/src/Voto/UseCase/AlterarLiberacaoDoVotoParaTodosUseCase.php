<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\TenantRepository;
use App\Voto\Repository\LiberacaoDoVotoRepository;
use App\Voto\Exception\EscritorioNaoEncontradoException;
use App\Voto\Service\GuardaDoMasterDoVoto;

/**
 * O Master libera (ou bloqueia) o BlueJus Voto para TODOS os escritórios ativos de uma vez (pedido M5.9 do dono). A
 * chave individual de cada escritório continua valendo ({@see AlterarLiberacaoDoVotoUseCase}); esta é só a ação em lote.
 *
 * Vale para os escritórios ativos NO MOMENTO da ação (isActive e não excluído, o mesmo critério da grade). Escritório
 * criado depois NÃO entra: nasce bloqueado, e o Master libera pela chave dele ou repete o lote. Quem já está no estado
 * pedido não é regravado (nem ganha linha). Cada escritório alterado grava a própria auditoria, como na chave
 * individual. Devolve quantos escritórios mudaram.
 */
final class AlterarLiberacaoDoVotoParaTodosUseCase
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly LiberacaoDoVotoRepository $liberacoes,
        private readonly AlterarLiberacaoDoVotoUseCase $porEscritorio,
    ) {
    }

    public function executar(bool $liberado, User $master): int
    {
        GuardaDoMasterDoVoto::exigir($master);

        $alterados = 0;
        foreach ($this->liberacoes->listarEscritoriosParaOMaster() as $linha) {
            if ($linha['liberado'] === $liberado) {
                continue;
            }
            $tenant = $this->tenants->find($linha['tenant_id']);
            if (!$tenant instanceof Tenant || $tenant->isActive() !== true || $tenant->getExcluidoEm() !== null) {
                continue;
            }
            // Um escritório por vez pelo mesmo caminho da aba (trava do escritório, auditoria nele e a trava do Master da
            // D16): "bloquear todos" também trava a contratação, e "liberar todos" destrava.
            // Suspenso no meio do laço: pula, como os de cima.
            try {
                $this->porEscritorio->executar((int) $tenant->getId(), $liberado, $master);
            } catch (EscritorioNaoEncontradoException) {
                continue;
            }
            ++$alterados;
        }

        return $alterados;
    }
}
