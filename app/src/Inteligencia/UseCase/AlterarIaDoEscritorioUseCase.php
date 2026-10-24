<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\AlterarIaDoEscritorioInput;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Enum\ModoDeCobrancaDaIa;
use App\Inteligencia\Exception\EscritorioNaoEncontradoException;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\Service\GuardaDoMasterDaIa;
use App\Repository\TenantRepository;

/**
 * O Master libera (ou bloqueia) a BlueJus IA de UM escritório e define o modo de cobrança e o orçamento mensal (spec
 * ia-anthropic-ligar §10.1). Liberar não liga a IA: o admin do escritório ainda precisa habilitar com o aceite.
 * Bloquear não apaga o aceite (é histórico) e corta a fila do escritório (IA-034L).
 *
 * Escritório inexistente, suspenso ou excluído: {@see EscritorioNaoEncontradoException} (404). Sem linha de
 * configuração, ela nasce aqui, com a IA desligada no escritório.
 */
final class AlterarIaDoEscritorioUseCase
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly ConfiguracaoDeInteligenciaRepository $configuracoes,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    public function executar(AlterarIaDoEscritorioInput $input, User $master): void
    {
        GuardaDoMasterDaIa::exigir($master);
        $tenant = $this->escritorioAtivo($input->tenantId);
        $configuracao = $this->configuracoes->findDoTenantParaOMaster($tenant) ?? new ConfiguracaoDeInteligencia($tenant);

        if ($input->liberada) {
            $configuracao->liberarPeloMaster();
        } else {
            $configuracao->bloquearPeloMaster();
        }
        $configuracao->definirCobranca($this->modo($input->modoDeCobranca), $input->orcamentoMensalCentavos);

        // A auditoria vai para o escritório alterado, não para o da sessão do Master.
        $this->escopoDePlataforma->executarComoConta(
            (int) $tenant->getId(),
            fn () => $this->configuracoes->salvar($configuracao, flush: true),
        );
    }

    private function modo(string $valor): ModoDeCobrancaDaIa
    {
        return ModoDeCobrancaDaIa::tryFrom($valor) ?? throw new \InvalidArgumentException('Escolha um modo de cobrança da lista.');
    }

    private function escritorioAtivo(int $tenantId): Tenant
    {
        $tenant = $tenantId > 0 ? $this->tenants->find($tenantId) : null;
        if (!$tenant instanceof Tenant || $tenant->isActive() !== true || $tenant->getExcluidoEm() !== null) {
            throw new EscritorioNaoEncontradoException($tenantId);
        }

        return $tenant;
    }
}
