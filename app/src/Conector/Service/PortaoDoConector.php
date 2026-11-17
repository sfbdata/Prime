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
 * contexto com o filtro de escritório desligado: só {@see abrir()} produz o contexto (`PortaoDoConectorArquiteturaTest`).
 *
 * API pública (os PRs da autorização e do `/mcp` chamam):
 *  - {@see conferir()}: só as conferências, sem efeito colateral e sem devolver contexto. Para a tela de autorização
 *    (`/oauth/authorize`), que roda no navegador e não pode trocar o filtro, o escritório nem o modo da requisição.
 *  - {@see abrir()}: as conferências e depois tranca a requisição no escritório do token. Para cada requisição do
 *    `/mcp`, antes de despachar a ferramenta. Uma vez por requisição: abrir de novo recusa (falha fechada); o
 *    `reset()` dos serviços zera tudo no fim.
 *
 * O que o portão NÃO confere: a liberação nos três níveis (14.a), o corte de "Sair de todos os aparelhos"
 * (`autorizado_em`) e a impressão da senha (seção 4, regra 3). São dados da concessão do token, e quem os confere é a
 * {@see ConferenciaDaConcessao::exigir()}, que também chama {@see conferir()}. No `/mcp` (PR 5), o validador do token
 * roda a conferência da concessão antes, e só depois o middleware do portão chama {@see abrir()}: a pessoa e o
 * escritório são resolvidos duas vezes de propósito, e só {@see abrir()} tranca a requisição.
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
    public function conferir(int $userId, int $tenantId): void
    {
        $this->resolver($userId, $tenantId);
    }

    /**
     * {@see conferir()} e depois tranca a requisição no escritório do token: liga o filtro `tenant` e confere que
     * ficou ligado com este escritório (falha fechada: qualquer erro recusa); fixa o escritório e o vínculo no
     * TenantContext, sem sessão (quem lê o escritório de lá, como a auditoria, vê o do token); liga o modo do conector
     * (teto, 6.3). Requisição já aberta (escritório fixado ou modo ligado) recusa antes de tudo.
     *
     * @throws AcessoDoConectorRecusado
     */
    public function abrir(int $userId, int $tenantId): ContextoDoConector
    {
        if ($this->tenantContext->estaFixadoSemSessao() || $this->modo->estaLigado()) {
            throw AcessoDoConectorRecusado::jaAberto();
        }

        $contexto = $this->resolver($userId, $tenantId);

        $this->trancarNoEscritorio($tenantId);
        $this->tenantContext->fixarSemSessao($contexto->escritorio, $contexto->vinculo);
        $this->modo->ligar($contexto->vinculo);

        return $contexto;
    }

    /** @throws AcessoDoConectorRecusado */
    private function resolver(int $userId, int $tenantId): ContextoDoConector
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
        // Vínculo removido ou escritório inexistente: o texto do conector, sem o "Selecione outro escritório" da tela.
        if ($recusa === BarreirasDoEscritorio::ACESSO_INDISPONIVEL) {
            throw AcessoDoConectorRecusado::escritorio();
        }
        if ($recusa !== null) {
            throw AcessoDoConectorRecusado::escritorio($recusa);
        }
        // Vínculo ativo e situação aprovada para todos, sem exceção (4.1): segunda trava, além das barreiras.
        if ($vinculo === null || !$vinculo->isActive() || $vinculo->getSituacao() !== SituacaoNaEquipe::Aprovado) {
            throw AcessoDoConectorRecusado::escritorio();
        }

        if (!$this->aceites->existeAceiteVigente($pessoa, $this->termoVigente->getVersao())) {
            throw AcessoDoConectorRecusado::termos();
        }

        return new ContextoDoConector($pessoa, $escritorio, $vinculo);
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
