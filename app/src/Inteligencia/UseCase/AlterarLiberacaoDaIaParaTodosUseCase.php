<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Exception\EscritorioNaoEncontradoException;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\Service\GuardaDoMasterDaIa;
use App\Repository\TenantRepository;

/**
 * O Master libera (ou bloqueia) a BlueJus IA de TODOS os escritórios ativos de uma vez (pedido M5.9 do dono). A chave
 * individual de cada escritório continua valendo ({@see AlterarIaDoEscritorioUseCase}); esta é só a ação em lote.
 * NÃO confundir com a "chave geral" (ligadaParaTodos), que é o interruptor da plataforma e não muda aqui.
 *
 * Só mexe em liberarPeloMaster()/bloquearPeloMaster(): o modo de cobrança e o orçamento de cada escritório ficam como
 * estão (escritório sem configuração ganha uma nova, com os padrões da entidade). Vale para os escritórios ativos NO
 * MOMENTO da ação (isActive e não excluído); escritório criado depois NÃO entra. Quem já está no estado pedido não é
 * regravado. Cada escritório alterado grava a própria auditoria. Devolve quantos escritórios mudaram.
 */
final class AlterarLiberacaoDaIaParaTodosUseCase
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly ConfiguracaoDeInteligenciaRepository $configuracoes,
        private readonly AlterarIaDoEscritorioUseCase $porEscritorio,
    ) {
    }

    public function executar(bool $liberada, User $master): int
    {
        GuardaDoMasterDaIa::exigir($master);

        $alterados = 0;
        foreach ($this->configuracoes->listarEscritoriosParaOMaster() as $linha) {
            if ($linha['liberada'] === $liberada) {
                continue;
            }
            $tenant = $this->tenants->find($linha['tenant_id']);
            if (!$tenant instanceof Tenant || $tenant->isActive() !== true || $tenant->getExcluidoEm() !== null) {
                continue;
            }
            // Um escritório por vez pelo mesmo caminho da aba (trava do escritório, auditoria nele e a trava do Master da
            // D16), mantendo o modo e o teto de cada um: "bloquear todos" também trava a contratação. Suspenso no meio do
            // laço: pula, como os de cima.
            try {
                $this->porEscritorio->alterarLiberacao((int) $tenant->getId(), $liberada, $master);
            } catch (EscritorioNaoEncontradoException) {
                continue;
            }
            ++$alterados;
        }

        return $alterados;
    }
}
