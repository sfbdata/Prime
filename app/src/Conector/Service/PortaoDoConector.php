<?php

declare(strict_types=1);

namespace App\Conector\Service;

use App\Conector\DTO\ContextoDoConector;
use App\Conector\Exception\AcessoDoConectorRecusado;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\TenantRepository;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Service\Tenant\TenantContext;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Tenant\Service\BarreirasDoEscritorio;
use App\Termo\Repository\AceiteTermoRepository;
use App\Termo\TermoVigente;

/**
 * A porta única do conector de I.A. (spec mcp-remoto-oauth 4 e 4.1). Sem HTTP e sem sessão: recebe a pessoa e o
 * escritório do token e devolve o {@see ContextoDoConector}, ou lança {@see AcessoDoConectorRecusado}. Nunca devolve
 * contexto com o filtro de escritório desligado.
 *
 * API pública (os PRs da autorização e do `/mcp` chamam):
 *  - {@see conferir()}: só as conferências, sem efeito colateral. Para a tela de autorização (`/oauth/authorize`), que
 *    roda no navegador e não pode trocar o filtro, o escritório nem o modo da requisição.
 *  - {@see abrir()}: as conferências e depois tranca a requisição no escritório do token. Para cada requisição do
 *    `/mcp`, antes de despachar a ferramenta. Uma vez por requisição; o `reset()` dos serviços zera tudo no fim.
 *
 * O que fica para o PR do `/mcp` (ponto marcado em {@see conferir()}): liberação nos três níveis (14.a), corte de
 * "Sair de todos os aparelhos" (`autorizado_em`) e impressão da senha (seção 4, regra 3). São dados do token, que este
 * PR ainda não tem.
 */
final class PortaoDoConector
{
    public function __construct(
        private readonly UserRepository $pessoas,
        private readonly TenantRepository $escritorios,
        private readonly UserTenantRepository $vinculos,
        private readonly BarreirasDoEscritorio $barreiras,
        private readonly AceiteTermoRepository $aceites,
        private readonly TermoVigente $termoVigente,
        private readonly FiltroDoEscritorio $filtro,
        private readonly ModoDoConector $modo,
        private readonly TenantContext $tenantContext,
    ) {
    }

    /**
     * As conferências da seção 4, regra 1, na ordem: pessoa ativa; as barreiras do escritório (as MESMAS do navegador,
     * pelo serviço extraído; o primário de conta suspensa a pedido é recusado com a mensagem do pedido de exclusão);
     * vínculo ativo, também para o Master; termos de uso aceitos na versão vigente.
     *
     * @throws AcessoDoConectorRecusado
     */
    public function conferir(int $userId, int $tenantId): ContextoDoConector
    {
        $pessoa = $this->pessoas->find($userId);
        if (!$pessoa instanceof User || !$pessoa->isActive()) {
            throw AcessoDoConectorRecusado::pessoa();
        }

        $escritorio = $this->escritorios->find($tenantId);
        $vinculo = $escritorio instanceof Tenant ? $this->vinculos->findPorUserETenant($pessoa, $escritorio) : null;

        // Sem o passe do Master (seção 4, regra 1): no conector, o Master com vínculo em análise ou suspenso é recusado.
        $resultado = $this->barreiras->conferir($vinculo, $escritorio, $pessoa, passeDoMaster: false);
        $recusa = $resultado->recusa();
        if ($recusa !== null) {
            throw AcessoDoConectorRecusado::escritorio($recusa);
        }
        // Vínculo ativo e situação aprovada para todos, sem exceção (4.1): segunda trava, além das barreiras.
        if ($vinculo === null || !$vinculo->isActive() || $vinculo->getSituacao() !== SituacaoNaEquipe::Aprovado) {
            throw AcessoDoConectorRecusado::escritorio();
        }

        // PR do `/mcp`: liberação nos três níveis, `autorizado_em` e impressão da senha entram aqui.

        if (!$this->aceites->existeAceiteVigente($pessoa, $this->termoVigente->getVersao())) {
            throw AcessoDoConectorRecusado::termos();
        }

        return new ContextoDoConector($pessoa, $escritorio, $vinculo);
    }

    /**
     * {@see conferir()} e depois tranca a requisição no escritório do token: liga o filtro `tenant` e confere que
     * ficou ligado com este escritório (falha fechada: qualquer erro recusa); fixa o escritório e o vínculo no
     * TenantContext, sem sessão (quem lê o escritório de lá, como a auditoria, vê o do token); liga o modo do conector
     * (teto, 6.3).
     *
     * @throws AcessoDoConectorRecusado
     */
    public function abrir(int $userId, int $tenantId): ContextoDoConector
    {
        $contexto = $this->conferir($userId, $tenantId);

        $this->trancarNoEscritorio($tenantId);
        $this->tenantContext->fixarSemSessao($contexto->escritorio, $contexto->vinculo);
        $this->modo->ligar($contexto->vinculo);

        return $contexto;
    }

    /** @throws AcessoDoConectorRecusado */
    private function trancarNoEscritorio(int $tenantId): void
    {
        try {
            $this->filtro->ligar($tenantId);
            $ligado = $this->filtro->estaLigadoEm($tenantId);
        } catch (\Throwable $erro) {
            throw AcessoDoConectorRecusado::filtro($erro);
        }

        if (!$ligado) {
            throw AcessoDoConectorRecusado::filtro();
        }
    }
}
