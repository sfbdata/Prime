<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Contexto\MontadorDeContextoDaPasta;
use App\Inteligencia\DTO\AnaliseOutput;
use App\Inteligencia\DTO\SolicitarAnaliseDaPastaInput;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Exception\ContextoBloqueadoException;
use App\Inteligencia\Exception\ContextoVazioException;
use App\Inteligencia\Exception\FilaIndisponivelException;
use App\Inteligencia\Exception\InteligenciaIndisponivelException;
use App\Inteligencia\Exception\PastaNaoEncontradaException;
use App\Inteligencia\Prompt\PromptDoAgente;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Service\DisponibilidadeDeInteligencia;
use App\Inteligencia\Service\EnfileiradorDeAnalise;
use App\Inteligencia\Service\VisibilidadeDoFinanceiroDaPasta;
use App\Pasta\Repository\PastaRepository;
use App\Service\PermissionChecker;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * "Gerar análise" de UM agente da BlueJus IA na pasta (drawer do cabeçalho).
 *
 * Quem: usuário que vê a pasta E tem `modules.inteligencia.view`, num escritório com a IA ligada.
 * O quê: pede ao worker a análise do agente sobre os dados REAIS desta pasta. Pré-condições, na
 * ordem: pasta do escritório (404), permissão sobre a pasta (403), disponibilidade (409/429),
 * contexto não sigiloso e com ao menos uma linha nas seções do agente (409 `sem_dados`).
 * Idempotência POR AGENTE: pedido em andamento do mesmo agente → devolve o mesmo; contexto igual
 * ao da última concluída do agente → devolve a última com aviso (não gasta cota).
 * Pós-condição: linha `pendente` com `agente` e `contexto_resumo.financeiro` (a decisão sobre o
 * financeiro é tomada AQUI, com sessão — o worker só obedece) + 1 mensagem no `async`.
 *
 * @throws PastaNaoEncontradaException
 * @throws AccessDeniedException
 * @throws InteligenciaIndisponivelException
 * @throws ContextoBloqueadoException
 * @throws ContextoVazioException
 * @throws FilaIndisponivelException
 */
final class SolicitarAnaliseDaPastaUseCase
{
    public function __construct(
        private readonly PastaRepository $pastas,
        private readonly PermissionChecker $permissionChecker,
        private readonly DisponibilidadeDeInteligencia $disponibilidade,
        private readonly VisibilidadeDoFinanceiroDaPasta $financeiro,
        private readonly MontadorDeContextoDaPasta $montador,
        private readonly AnaliseDeInteligenciaRepository $analises,
        private readonly EnfileiradorDeAnalise $enfileirador,
    ) {
    }

    public function executar(SolicitarAnaliseDaPastaInput $input, User $user, Tenant $tenant): AnaliseOutput
    {
        $pasta = $this->pastas->findOneBy(['id' => $input->pastaId, 'tenant' => $tenant]);
        if ($pasta === null) {
            throw new PastaNaoEncontradaException($input->pastaId);
        }

        if (!$this->permissionChecker->canAccessResource($user, $tenant, 'pasta', (int) $pasta->getId(), 'view')) {
            throw new AccessDeniedException('Sem permissão para ver esta pasta.');
        }

        $disponibilidade = $this->disponibilidade->para($user, $tenant);
        if (!$disponibilidade->estaDisponivel()) {
            throw new InteligenciaIndisponivelException($disponibilidade);
        }

        $agente = $input->agente;
        $alvoId = (int) $pasta->getId();
        $emAndamento = $this->analises->findPendenteDoAlvo($tenant, AnaliseDeInteligencia::ALVO_PASTA, $alvoId, TipoDeAnalise::AnalisePasta, $agente);
        if ($emAndamento !== null) {
            return AnaliseOutput::fromEntity($emAndamento, sprintf('Já existe uma análise do %s em andamento.', $agente->nome()));
        }

        $incluirFinanceiro = $agente->leFinanceiro() && $this->financeiro->podeVer($user, $tenant, $pasta);
        $contexto = $this->montador->para($tenant, $pasta, $agente, $incluirFinanceiro);
        if ($contexto->vazio()) {
            throw ContextoVazioException::semDadosParaOAgente();
        }

        $ultima = $this->analises->findUltimaConcluidaDoAlvo($tenant, AnaliseDeInteligencia::ALVO_PASTA, $alvoId, TipoDeAnalise::AnalisePasta, $agente);
        if ($ultima !== null && $ultima->getContextoHash() === $contexto->hash) {
            return AnaliseOutput::fromEntity($ultima, 'Nada novo desde a última análise deste agente.');
        }

        $analise = new AnaliseDeInteligencia(
            tenant: $tenant,
            solicitante: $user,
            tipo: TipoDeAnalise::AnalisePasta,
            alvoTipo: AnaliseDeInteligencia::ALVO_PASTA,
            alvoId: $alvoId,
            versaoDoPrompt: PromptDoAgente::VERSAO,
            contextoHash: $contexto->hash,
            contextoResumo: $contexto->resumo(),
            agente: $agente,
        );
        $this->analises->salvar($analise, true);

        $this->enfileirador->enfileirar($analise, $tenant);

        return AnaliseOutput::fromEntity($analise);
    }
}
