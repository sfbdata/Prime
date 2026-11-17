<?php

declare(strict_types=1);

namespace App\Conector\UseCase;

use App\Conector\Entity\EscritorioDoConector;
use App\Conector\Repository\EscritorioDoConectorRepository;
use App\Conector\Service\GuardaDoMasterDoConector;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\TenantRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;

/**
 * O Master libera ou bloqueia o conector de I.A. em UM escritório, na aba "Configurações da I.A." do painel
 * (`docs/specs/mcp-remoto-oauth.md`, 14.a, nível 2). Só o nível 2: liberar não liga o conector (falta o administrador
 * do escritório ligar e dar o aceite), e bloquear não apaga o aceite nem revoga as conexões (decisão 4 do plano do PR 8):
 * só suspende. Suspenso é zero acesso na hora, porque a {@see \App\Conector\Service\LiberacaoDoConector} é conferida a
 * cada chamada; o limite de 7 dias da concessão continua correndo.
 *
 * Escritório inexistente, suspenso ou excluído: {@see ESCRITORIO_NAO_ENCONTRADO}. Sem linha do conector, ela nasce aqui.
 * A auditoria vai para o escritório alterado, não para o da sessão do Master.
 */
final class AlterarLiberacaoDoConectorPeloMasterUseCase
{
    /** Chave do catálogo da recusa; o controller responde 404. */
    public const ESCRITORIO_NAO_ENCONTRADO = 'conector.master.recusa.escritorio_nao_encontrado';

    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly EscritorioDoConectorRepository $escritorios,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    /** @throws \DomainException com {@see ESCRITORIO_NAO_ENCONTRADO} */
    public function executar(int $tenantId, bool $liberado, User $master): void
    {
        GuardaDoMasterDoConector::exigir($master);
        $tenant = $this->escritorioAtivo($tenantId);
        $escritorio = $this->escritorios->buscarDoEscritorioParaOMaster($tenant) ?? new EscritorioDoConector($tenant);

        if ($liberado) {
            $escritorio->liberarPeloMaster();
        } else {
            $escritorio->bloquearPeloMaster();
        }

        $this->escopoDePlataforma->executarComoConta(
            (int) $tenant->getId(),
            fn () => $this->escritorios->salvar($escritorio, flush: true),
        );
    }

    /**
     * O mesmo recorte da tabela ({@see EscritorioDoConectorRepository::listarEscritoriosParaOMaster()}): ativo e não
     * excluído. Pela consulta, e não pelos getters do escritório, porque a regra das barreiras mora só no
     * `BarreirasDoEscritorio` (`PortaoDoConectorArquiteturaTest`).
     */
    private function escritorioAtivo(int $tenantId): Tenant
    {
        $tenant = $tenantId > 0 ? $this->tenants->findOneBy(['id' => $tenantId, 'isActive' => true, 'excluidoEm' => null]) : null;
        if (!$tenant instanceof Tenant) {
            throw new \DomainException(self::ESCRITORIO_NAO_ENCONTRADO);
        }

        return $tenant;
    }
}
