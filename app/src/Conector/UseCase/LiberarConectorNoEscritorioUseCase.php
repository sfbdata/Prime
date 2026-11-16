<?php

declare(strict_types=1);

namespace App\Conector\UseCase;

use App\Conector\Entity\EscritorioDoConector;
use App\Conector\Repository\EscritorioDoConectorRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\Service\RegraDosMasters;
use App\Repository\TenantRepository;
use App\Repository\UserRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Psr\Clock\ClockInterface;

/**
 * O Master libera o conector de I.A. num escritório pelo console (`conector:liberar`, `docs/specs/mcp-remoto-oauth.md`,
 * 13.1 e 14.a), para conectar um cliente real antes das telas do PR 8: liga, de uma vez, os níveis 2 e 3 só deste
 * escritório (liberado pelo Master, ligado e com o aceite da versão vigente). O nível 1 (a chave geral) não muda.
 *
 * Quem aparece como autor do aceite é o Master informado (decisão 8 de 16/11), conferido como Master e com a conta
 * ativa. A gravação entra no `audit_log` com o escritório alvo ({@see EscopoDeAuditoriaDePlataforma::executarComoConta()}).
 */
final class LiberarConectorNoEscritorioUseCase
{
    public const SEM_MASTER = 'Informe o e-mail de um Master com a conta ativa.';
    public const SEM_ESCRITORIO = 'Escritório não encontrado ou desativado.';

    public function __construct(
        private readonly UserRepository $pessoas,
        private readonly TenantRepository $tenants,
        private readonly EscritorioDoConectorRepository $escritorios,
        private readonly RegraDosMasters $regraDosMasters,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDeAuditoria,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** @throws \DomainException com {@see SEM_MASTER} ou {@see SEM_ESCRITORIO} */
    public function executar(int $tenantId, string $emailDoMaster): void
    {
        $master = $this->master($emailDoMaster);
        $tenant = $this->escritorioAtivo($tenantId);

        $escritorio = $this->escritorios->buscarDoEscritorio($tenant) ?? new EscritorioDoConector($tenant);
        $escritorio->liberarPeloMaster();
        $escritorio->ligar($master, $this->relogio->now());

        $this->escopoDeAuditoria->executarComoConta(
            (int) $tenant->getId(),
            fn () => $this->escritorios->salvar($escritorio, flush: true),
        );
    }

    private function master(string $email): User
    {
        $encontrados = trim($email) === '' ? [] : $this->pessoas->encontrarPorEmailIgnorandoCaixa($email);
        // Duas contas que só diferem na caixa: nenhuma delas é "o" Master.
        $master = \count($encontrados) === 1 ? $encontrados[0] : null;
        if (!$master instanceof User || !$master->isActive() || !$this->regraDosMasters->ehMaster($master)) {
            throw new \DomainException(self::SEM_MASTER);
        }

        return $master;
    }

    private function escritorioAtivo(int $tenantId): Tenant
    {
        $tenant = $tenantId > 0 ? $this->tenants->find($tenantId) : null;
        if (!$tenant instanceof Tenant || $tenant->isActive() !== true) {
            throw new \DomainException(self::SEM_ESCRITORIO);
        }

        return $tenant;
    }
}
