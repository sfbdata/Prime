<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\TenantRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Repository\LiberacaoDoVotoRepository;
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
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
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
            $liberacao = $this->liberacoes->findDoTenantParaOMaster($tenant) ?? new LiberacaoDoVoto($tenant);
            if ($liberado) {
                $liberacao->liberar();
            } else {
                $liberacao->bloquear();
            }

            // A auditoria vai para o escritório alterado, não para o da sessão do Master.
            $this->escopoDePlataforma->executarComoConta(
                (int) $tenant->getId(),
                fn () => $this->liberacoes->salvar($liberacao, flush: true),
            );
            ++$alterados;
        }

        return $alterados;
    }
}
