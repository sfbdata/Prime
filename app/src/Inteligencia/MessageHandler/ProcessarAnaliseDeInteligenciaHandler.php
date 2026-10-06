<?php

declare(strict_types=1);

namespace App\Inteligencia\MessageHandler;

use App\Inteligencia\Contexto\MontadorDeContextoDoPush;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\StatusDaAnalise;
use App\Inteligencia\Exception\ContextoBloqueadoException;
use App\Inteligencia\Exception\FalhaDoProvedorException;
use App\Inteligencia\Exception\ProvedorIndisponivelException;
use App\Inteligencia\Exception\RespostaInvalidaException;
use App\Inteligencia\Message\ProcessarAnaliseDeInteligencia;
use App\Inteligencia\Prompt\PromptResumoDoPush;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Service\InterpretadorDeRespostaDePush;
use App\Inteligencia\Service\ProvedorDeLinguagem;
use App\Pasta\Entity\Pasta;
use App\Service\NotificacaoService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Roda no worker: monta o contexto da pasta, chama o provedor, interpreta e grava o resultado.
 *
 * Fronteira de confiança (como o `SincronizarPastaNoDriveHandler`): o tenant da mensagem é
 * conferido contra a linha ANTES de qualquer coisa; divergência é log + no-op. Tenant explícito em
 * toda consulta daqui para baixo — o TenantFilter é inerte sem sessão.
 *
 * Destinos:
 *   · sem provedor → `indisponivel` + Unrecoverable (sem retry; repetir não muda nada);
 *   · falha transitória do provedor com retentativa à frente → volta a `pendente` + re-lança (o
 *     Messenger retenta; a linha segue "em andamento" e um novo clique devolve a mesma, sem gasto
 *     duplo); na ÚLTIMA tentativa → `falhou` + re-lança (vai para `failed`);
 *   · falha definitiva (400/401/403) → `falhou` + Unrecoverable;
 *   · contexto bloqueado/vazio, resposta inválida → `falhou`, sem re-lançar.
 *
 * "Última tentativa" sai do contador da própria linha (`tentativas`, incrementado a cada entrega)
 * contra {@see TENTATIVAS_MAXIMAS}, que espelha `max_retries` do transport `async`.
 *
 * Log só com ids, tenant, provedor, tokens, duração e classe do erro — NUNCA o prompt.
 */
#[AsMessageHandler]
final class ProcessarAnaliseDeInteligenciaHandler
{
    public const TIPO_NOTIFICACAO = 'ia_analise_concluida';

    /**
     * 1 entrega + 3 retentativas = `retry_strategy.max_retries: 3` do transport `async` em
     * `config/packages/messenger.yaml`. Mudou lá, muda aqui — senão a linha fica `pendente` sem
     * ninguém para retentar (valor maior) ou vira `falhou` com retentativa ainda a caminho (menor).
     */
    public const TENTATIVAS_MAXIMAS = 4;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AnaliseDeInteligenciaRepository $analises,
        private readonly MontadorDeContextoDoPush $montador,
        private readonly PromptResumoDoPush $prompt,
        private readonly ProvedorDeLinguagem $provedor,
        private readonly InterpretadorDeRespostaDePush $interpretador,
        private readonly NotificacaoService $notificacoes,
        private readonly UrlGeneratorInterface $urls,
        #[Autowire(service: 'monolog.logger.inteligencia')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessarAnaliseDeInteligencia $mensagem): void
    {
        $analise = $this->analises->find($mensagem->analiseId);
        if ($analise === null) {
            return; // apagada — nada a fazer
        }

        $tenant = $analise->getTenant();
        if ($tenant === null || $tenant->getId() !== $mensagem->tenantId) {
            $this->logger->warning('Análise {analise} ignorada: pertence ao tenant {real}, não ao {msg} da mensagem.', [
                'analise' => $mensagem->analiseId,
                'real' => $tenant?->getId(),
                'msg' => $mensagem->tenantId,
            ]);

            return;
        }

        if (!in_array($analise->getStatus(), [StatusDaAnalise::Pendente, StatusDaAnalise::Falhou], true)) {
            return; // já processada ou em curso por outro worker — idempotente
        }

        if ($analise->estaExcluida()) {
            return; // excluída antes de processar: não gastar cota com o que ninguém vai ler
        }

        $analise->iniciarProcessamento();
        $this->em->flush();

        $pasta = $this->em->find(Pasta::class, $analise->getAlvoId());
        if ($pasta === null || $pasta->getTenant()?->getId() !== $tenant->getId()) {
            $this->falhar($analise, 'pasta não encontrada neste escritório');

            return;
        }

        try {
            $contexto = $this->montador->para($tenant, $pasta);
        } catch (ContextoBloqueadoException $e) {
            $this->falhar($analise, 'contexto bloqueado: ' . $e->getMessage());

            return;
        }

        if ($contexto->vazio()) {
            $this->falhar($analise, 'sem movimentações');

            return;
        }

        $anterior = $this->analises->findUltimaConcluidaDoAlvo($tenant, $analise->getAlvoTipo(), $analise->getAlvoId());
        $chavesAnteriores = $anterior?->getChavesAnalisadas() ?? [];
        $analise->registrarContexto($contexto->hash, $contexto->resumo($chavesAnteriores));
        $analise->registrarProvedor($this->provedor->nome());

        $pedido = $this->prompt->montar($contexto, $anterior?->getResumo(), $chavesAnteriores);

        try {
            $resposta = $this->provedor->completar($pedido);
        } catch (ProvedorIndisponivelException $e) {
            $analise->marcarIndisponivel($e->getMessage());
            $this->em->flush();
            $this->logger->info('Análise {analise} indisponível: provedor não configurado.', $this->contexto($analise));

            throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
        } catch (FalhaDoProvedorException $e) {
            $motivo = sprintf('%s: %s', $e->transitoria ? 'falha transitória' : 'falha definitiva', $e->getMessage());

            if ($e->transitoria && $analise->getTentativas() < self::TENTATIVAS_MAXIMAS) {
                // Ainda há retentativa à frente: a linha volta à fila e continua "em andamento".
                $analise->devolverParaFila($motivo);
                $this->em->flush();
                $this->logger->warning('Análise {analise}: falha transitória no provedor, de volta à fila (tentativa {tentativas} de {max}).', $this->contexto($analise) + [
                    'max' => self::TENTATIVAS_MAXIMAS,
                ]);

                throw $e; // Messenger faz o retry
            }

            $this->falhar($analise, $motivo);
            $this->logger->warning('Análise {analise} falhou no provedor ({classe}, transitória: {transitoria}).', $this->contexto($analise) + [
                'classe' => $e::class,
                'transitoria' => $e->transitoria ? 'sim' : 'não',
            ]);

            if ($e->transitoria) {
                throw $e; // última tentativa: o Messenger leva para `failed`
            }

            throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
        }

        try {
            $interpretada = $this->interpretador->interpretar($resposta->texto);
        } catch (RespostaInvalidaException $e) {
            $this->falhar($analise, $e->getMessage(), $e->textoBruto);
            $this->logger->warning('Análise {analise}: resposta inválida do provedor.', $this->contexto($analise));

            return;
        }

        $analise->concluir(
            resumo: $interpretada->resumo,
            pontos: $interpretada->pontos,
            quemAge: $interpretada->quemAge,
            textoBruto: $resposta->texto,
            provedor: $this->provedor->nome(),
            modelo: $resposta->modelo,
            tokensEntrada: $resposta->tokensEntrada,
            tokensSaida: $resposta->tokensSaida,
            duracaoMs: $resposta->duracaoMs,
        );
        $this->em->flush();

        $this->logger->info('Análise {analise} concluída.', $this->contexto($analise) + [
            'modelo' => $resposta->modelo,
            'tokens_entrada' => $resposta->tokensEntrada,
            'tokens_saida' => $resposta->tokensSaida,
            'duracao_ms' => $resposta->duracaoMs,
            'movimentacoes' => count($contexto->itens),
        ]);

        $this->notificar($analise, $pasta);
    }

    private function falhar(AnaliseDeInteligencia $analise, string $motivo, ?string $textoBruto = null): void
    {
        $analise->falhar($motivo, $textoBruto);
        $this->em->flush();
        $this->logger->warning('Análise {analise} falhou: {motivo}.', $this->contexto($analise) + ['motivo' => $motivo]);
    }

    /** Aviso no sino de quem pediu. Falha aqui não desfaz a análise já gravada: só loga. */
    private function notificar(AnaliseDeInteligencia $analise, Pasta $pasta): void
    {
        $solicitante = $analise->getSolicitante();
        $tenant = $analise->getTenant();
        if ($solicitante === null || $tenant === null) {
            return;
        }

        try {
            $this->notificacoes->criarNotificacao(
                $solicitante,
                $tenant,
                self::TIPO_NOTIFICACAO,
                sprintf('A análise por IA da pasta %s ficou pronta.', (string) $pasta->getNup()),
                $this->urls->generate('pasta_show', ['id' => $pasta->getId()]) . '#push',
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Análise {analise}: não foi possível notificar ({classe}).', $this->contexto($analise) + ['classe' => $e::class]);
        }
    }

    /** @return array<string, mixed> */
    private function contexto(AnaliseDeInteligencia $analise): array
    {
        return [
            'analise' => $analise->getId(),
            'tenant' => $analise->getTenant()?->getId(),
            'tipo' => $analise->getTipo()->value,
            'provedor' => $this->provedor->nome(),
            'tentativas' => $analise->getTentativas(),
        ];
    }
}
