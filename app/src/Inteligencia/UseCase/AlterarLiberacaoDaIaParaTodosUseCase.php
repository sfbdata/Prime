<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\Service\GuardaDoMasterDaIa;
use App\Repository\TenantRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;

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
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
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
            $configuracao = $this->configuracoes->findDoTenantParaOMaster($tenant) ?? new ConfiguracaoDeInteligencia($tenant);
            if ($liberada) {
                $configuracao->liberarPeloMaster();
            } else {
                $configuracao->bloquearPeloMaster();
            }

            // A auditoria vai para o escritório alterado, não para o da sessão do Master.
            $this->escopoDePlataforma->executarComoConta(
                (int) $tenant->getId(),
                fn () => $this->configuracoes->salvar($configuracao, flush: true),
            );
            ++$alterados;
        }

        return $alterados;
    }
}
