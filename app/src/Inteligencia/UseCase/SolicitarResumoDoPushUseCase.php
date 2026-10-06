<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Contexto\MontadorDeContextoDoPush;
use App\Inteligencia\DTO\AnaliseOutput;
use App\Inteligencia\DTO\SolicitarResumoDoPushInput;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Exception\ContextoBloqueadoException;
use App\Inteligencia\Exception\ContextoVazioException;
use App\Inteligencia\Exception\FilaIndisponivelException;
use App\Inteligencia\Exception\InteligenciaIndisponivelException;
use App\Inteligencia\Exception\PastaNaoEncontradaException;
use App\Inteligencia\Message\ProcessarAnaliseDeInteligencia;
use App\Inteligencia\Prompt\PromptResumoDoPush;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Service\DisponibilidadeDeInteligencia;
use App\Pasta\Repository\PastaRepository;
use App\Service\PermissionChecker;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * "Resumir com IA" na aba Push da pasta.
 *
 * Quem: usuário que vê a pasta E tem `modules.inteligencia.view`, num escritório com a IA ligada.
 * O quê: pede ao worker um resumo das movimentações. Pré-condições, na ordem: pasta do escritório
 * (404), permissão sobre a pasta (403), disponibilidade (409/429), contexto não sigiloso e não vazio.
 * Idempotência: pedido em andamento → devolve o mesmo; contexto igual ao da última concluída →
 * devolve a última com aviso (não gasta cota). Pós-condição: linha `pendente` + 1 mensagem no
 * `async`. Se o dispatch falhar: com o EntityManager aberto, a linha vira `falhou` e o chamador
 * recebe a análise (não um 500); com o EM fechado (o transport `doctrine` divide a conexão e uma
 * exceção do banco o fecha) não há como registrar nada — `FilaIndisponivelException` → 503 honesto.
 *
 * @throws PastaNaoEncontradaException
 * @throws AccessDeniedException
 * @throws InteligenciaIndisponivelException
 * @throws ContextoBloqueadoException
 * @throws ContextoVazioException
 * @throws FilaIndisponivelException
 */
final class SolicitarResumoDoPushUseCase
{
    public function __construct(
        private readonly PastaRepository $pastas,
        private readonly PermissionChecker $permissionChecker,
        private readonly DisponibilidadeDeInteligencia $disponibilidade,
        private readonly MontadorDeContextoDoPush $montador,
        private readonly AnaliseDeInteligenciaRepository $analises,
        private readonly MessageBusInterface $bus,
        #[Autowire(service: 'monolog.logger.inteligencia')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function executar(SolicitarResumoDoPushInput $input, User $user, Tenant $tenant): AnaliseOutput
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

        $alvoId = (int) $pasta->getId();
        $emAndamento = $this->analises->findPendenteDoAlvo($tenant, AnaliseDeInteligencia::ALVO_PASTA, $alvoId);
        if ($emAndamento !== null) {
            return AnaliseOutput::fromEntity($emAndamento, 'Já existe uma análise em andamento para esta pasta.');
        }

        $contexto = $this->montador->para($tenant, $pasta);
        if ($contexto->vazio()) {
            throw new ContextoVazioException();
        }

        $ultima = $this->analises->findUltimaConcluidaDoAlvo($tenant, AnaliseDeInteligencia::ALVO_PASTA, $alvoId);
        if ($ultima !== null && $ultima->getContextoHash() === $contexto->hash) {
            return AnaliseOutput::fromEntity($ultima, 'Nada novo desde a última análise.');
        }

        $analise = new AnaliseDeInteligencia(
            tenant: $tenant,
            solicitante: $user,
            tipo: TipoDeAnalise::ResumoPush,
            alvoTipo: AnaliseDeInteligencia::ALVO_PASTA,
            alvoId: $alvoId,
            versaoDoPrompt: PromptResumoDoPush::VERSAO,
            contextoHash: $contexto->hash,
            contextoResumo: $contexto->resumo($ultima?->getChavesAnalisadas() ?? []),
        );
        $this->analises->salvar($analise, true);

        try {
            $this->bus->dispatch(new ProcessarAnaliseDeInteligencia((int) $analise->getId(), (int) $tenant->getId()));
        } catch (\Throwable $e) {
            $this->registrarFalhaDeEnfileiramento($analise, $tenant, $e);
        }

        return AnaliseOutput::fromEntity($analise);
    }

    /**
     * O pedido fica registrado como falha em vez de derrubar a ação do usuário (padrão do Sync) —
     * mas só se o EntityManager ainda aceitar escrita. Um flush em EM fechado lançaria de novo e
     * viraria 500; nesse caso (ou se a gravação da falha for recusada) a resposta é 503 honesta.
     *
     * @throws FilaIndisponivelException
     */
    private function registrarFalhaDeEnfileiramento(AnaliseDeInteligencia $analise, Tenant $tenant, \Throwable $erro): void
    {
        $contexto = [
            'analise' => $analise->getId(),
            'tenant' => $tenant->getId(),
            'classe' => $erro::class,
        ];

        if (!$this->analises->emAberto()) {
            $this->logger->error('Falha ao enfileirar a análise {analise} do tenant {tenant} ({classe}); EntityManager fechado, falha não registrada.', $contexto);

            throw new FilaIndisponivelException($analise->getId(), $erro);
        }

        try {
            $analise->falhar('falha ao enfileirar: ' . $erro::class);
            $this->analises->salvar($analise, true);
        } catch (\Throwable $segundoErro) {
            $this->logger->error('Falha ao enfileirar a análise {analise} do tenant {tenant} ({classe}) e ao registrar a falha ({classe2}).', $contexto + ['classe2' => $segundoErro::class]);

            throw new FilaIndisponivelException($analise->getId(), $segundoErro);
        }

        $this->logger->error('Falha ao enfileirar a análise {analise} do tenant {tenant}: {classe}.', $contexto);
    }
}
