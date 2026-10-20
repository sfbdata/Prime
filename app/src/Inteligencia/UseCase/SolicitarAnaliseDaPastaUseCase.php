<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Contexto\MontadorDeContextoDaPasta;
use App\Inteligencia\DTO\AnaliseOutput;
use App\Inteligencia\DTO\ContextoDaPasta;
use App\Inteligencia\DTO\SolicitarAnaliseDaPastaInput;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Enum\Comando;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Exception\ContextoBloqueadoException;
use App\Inteligencia\Exception\ContextoVazioException;
use App\Inteligencia\Exception\FilaIndisponivelException;
use App\Inteligencia\Exception\InteligenciaIndisponivelException;
use App\Inteligencia\Exception\PastaNaoEncontradaException;
use App\Inteligencia\Exception\SelecaoDeDocumentosInvalidaException;
use App\Inteligencia\Prompt\PromptDoAgente;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Service\DisponibilidadeDeInteligencia;
use App\Inteligencia\Service\EnfileiradorDeAnalise;
use App\Inteligencia\Service\VisibilidadeDoFinanceiroDaPasta;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
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
 * Com COMANDO (drawer, spec inteligencia-drawer-da-pasta F1/F2): o agente é o do comando e a
 * idempotência é POR COMANDO (dois comandos do mesmo agente não se travam). Sem comando, o
 * comportamento por agente continua (rotas `/ia/agentes/{agente}`).
 * "Índice inteligente" da aba Documentos (F4): os ids marcados são validados AQUI, antes de qualquer
 * gasto (seleção vazia ou id fora da lista que a aba mostra para quem pede → 422), e vão ordenados em
 * `contexto_resumo.documentosSelecionados`, que o worker obedece. Nos outros comandos o campo é ignorado.
 * Pós-condição: linha `pendente` com `agente` (e `comando`, quando houver) e `contexto_resumo.financeiro` (a decisão sobre o
 * financeiro é tomada AQUI, com sessão — o worker só obedece) + 1 mensagem no `async`.
 *
 * @throws PastaNaoEncontradaException
 * @throws AccessDeniedException
 * @throws InteligenciaIndisponivelException
 * @throws ContextoBloqueadoException
 * @throws ContextoVazioException
 * @throws SelecaoDeDocumentosInvalidaException
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
        private readonly VisibilidadeDasAbasDaPasta $abas,
    ) {
    }

    /**
     * `$antesDeCriar` roda só quando uma análise nova vai ser criada (o controller passa o limitador de ritmo):
     * pedido em andamento ou "nada novo" não gastam a cota de ritmo de quem só olha o resultado.
     */
    public function executar(SolicitarAnaliseDaPastaInput $input, User $user, Tenant $tenant, ?callable $antesDeCriar = null): AnaliseOutput
    {
        $pasta = $this->carregarPastaLiberada($input->pastaId, $user, $tenant);
        $comando = $input->comando;
        $agente = $input->agenteEfetivo();
        $ocultas = array_map(static fn (AbaDaPasta $a): string => $a->value, $this->abas->abasOcultasPara($pasta, $user));
        $selecionados = $this->selecaoValidada($input, $tenant, $pasta, $ocultas);
        $alvoId = (int) $pasta->getId();
        $emAndamento = $this->analises->findPendenteDoAlvo($tenant, AnaliseDeInteligencia::ALVO_PASTA, $alvoId, TipoDeAnalise::AnalisePasta, $agente, $comando);
        if ($emAndamento !== null) {
            return AnaliseOutput::fromEntity($emAndamento, self::avisoEmAndamento($agente, $comando, $emAndamento, $selecionados));
        }

        $incluirFinanceiro = $agente->leFinanceiro() && $this->financeiro->podeVer($user, $tenant, $pasta);
        $contexto = $this->montador->para($tenant, $pasta, $agente, $incluirFinanceiro, $ocultas, $comando, $selecionados);
        if ($contexto->vazio()) {
            throw ContextoVazioException::semDadosParaOAgente();
        }

        $ultima = $this->analises->findUltimaConcluidaDoAlvo($tenant, AnaliseDeInteligencia::ALVO_PASTA, $alvoId, TipoDeAnalise::AnalisePasta, $agente, $comando);
        if ($ultima !== null && $ultima->getContextoHash() === $contexto->hash) {
            return AnaliseOutput::fromEntity($ultima, self::avisoNadaNovo($comando));
        }

        if ($antesDeCriar !== null) {
            $antesDeCriar();
        }

        return $this->criar($tenant, $user, $alvoId, $contexto, $comando);
    }

    /** A linha `pendente` com o contexto decidido aqui, e a mensagem no `async`. */
    private function criar(Tenant $tenant, User $user, int $alvoId, ContextoDaPasta $contexto, ?Comando $comando): AnaliseOutput
    {
        $analise = new AnaliseDeInteligencia(
            tenant: $tenant,
            solicitante: $user,
            tipo: TipoDeAnalise::AnalisePasta,
            alvoTipo: AnaliseDeInteligencia::ALVO_PASTA,
            alvoId: $alvoId,
            versaoDoPrompt: PromptDoAgente::VERSAO,
            contextoHash: $contexto->hash,
            contextoResumo: $contexto->resumo(),
            agente: $contexto->agente,
            comando: $comando,
        );
        $this->analises->salvar($analise, true);

        $this->enfileirador->enfileirar($analise, $tenant);

        return AnaliseOutput::fromEntity($analise);
    }

    /**
     * Os documentos marcados no "Índice inteligente" (F4), ordenados e sem repetição, depois de
     * conferir que cada um está na lista que a aba Documentos mostra para quem pede: da pasta, do
     * escritório, visível pelas abas e entre os mais recentes (a mesma regra do contexto). Nulo nos
     * outros comandos, que ignoram o campo.
     *
     * @param list<string> $ocultas
     *
     * @return list<int>|null
     */
    private function selecaoValidada(SolicitarAnaliseDaPastaInput $input, Tenant $tenant, Pasta $pasta, array $ocultas): ?array
    {
        $pedidos = $input->documentosDoPedido();
        if ($pedidos === null) {
            return null;
        }
        if ($pedidos === []) {
            throw SelecaoDeDocumentosInvalidaException::vazia();
        }

        $mostrados = array_map(static fn (PastaDocumento $d): int => (int) $d->getId(), $this->montador->documentosMostrados($tenant, $pasta, $ocultas));
        $ids = array_values(array_unique($pedidos));
        sort($ids);
        if (array_diff($ids, $mostrados) !== []) {
            throw SelecaoDeDocumentosInvalidaException::foraDaLista();
        }

        return $ids;
    }

    /** A pasta do escritório que quem pede pode ver, com a IA disponível para ele. */
    private function carregarPastaLiberada(int $pastaId, User $user, Tenant $tenant): Pasta
    {
        $pasta = $this->pastas->findOneBy(['id' => $pastaId, 'tenant' => $tenant]);
        if ($pasta === null) {
            throw new PastaNaoEncontradaException($pastaId);
        }

        if (!$this->permissionChecker->canAccessResource($user, $tenant, 'pasta', (int) $pasta->getId(), 'view')) {
            throw new AccessDeniedException('Sem permissão para ver esta pasta.');
        }

        $disponibilidade = $this->disponibilidade->para($user, $tenant);
        if (!$disponibilidade->estaDisponivel()) {
            throw new InteligenciaIndisponivelException($disponibilidade);
        }

        return $pasta;
    }

    /**
     * Aviso da idempotência "em andamento": com comando, o rótulo dele; sem, o nome do agente. No
     * Índice (F4) com OUTRA seleção, diz que a seleção pedida não é a que está rodando.
     *
     * @param list<int>|null $selecionados
     */
    private static function avisoEmAndamento(Agente $agente, ?Comando $comando, AnaliseDeInteligencia $emAndamento, ?array $selecionados): string
    {
        if ($selecionados !== null && ($emAndamento->getContextoResumo()['documentosSelecionados'] ?? null) !== $selecionados) {
            return 'Já há um índice em andamento com outra seleção de documentos. Espere terminar para pedir outro.';
        }

        return $comando !== null
            ? sprintf('Já existe uma análise "%s" em andamento.', $comando->rotulo())
            : sprintf('Já existe uma análise do %s em andamento.', $agente->nome());
    }

    /** Aviso da idempotência "nada novo": com comando, o rótulo dele; sem, o texto por agente de antes. */
    private static function avisoNadaNovo(?Comando $comando): string
    {
        return $comando !== null
            ? sprintf('Nada novo desde a última análise "%s".', $comando->rotulo())
            : 'Nada novo desde a última análise deste agente.';
    }
}
