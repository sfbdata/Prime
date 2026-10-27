<?php

declare(strict_types=1);

namespace App\Inteligencia\MessageHandler;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\PedidoDeLinguagem;
use App\Inteligencia\DTO\RespostaDeLinguagem;
use App\Inteligencia\DTO\ResultadoDaAnalise;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\Disponibilidade;
use App\Inteligencia\Enum\StatusDaAnalise;
use App\Inteligencia\Exception\ArmazenamentoIndisponivelException;
use App\Inteligencia\Exception\ContextoBloqueadoException;
use App\Inteligencia\Exception\DocumentoIlegivelException;
use App\Inteligencia\Exception\FalhaDoProvedorException;
use App\Inteligencia\Exception\ProvedorIndisponivelException;
use App\Inteligencia\Exception\RespostaInvalidaException;
use App\Inteligencia\Fluxo\FluxoDaAnaliseDaPasta;
use App\Inteligencia\Fluxo\FluxoDaPerguntaLivre;
use App\Inteligencia\Fluxo\FluxoDeAnalise;
use App\Inteligencia\Fluxo\FluxoDeAnaliseSemPasta;
use App\Inteligencia\Fluxo\FluxoDoResumoDoPush;
use App\Inteligencia\Message\ProcessarAnaliseDeInteligencia;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Service\DisponibilidadeDeInteligencia;
use App\Inteligencia\Service\ProvedorDeLinguagem;
use App\Pasta\Entity\Pasta;
use App\Service\NotificacaoService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Roda no worker: escolhe o {@see FluxoDeAnalise} pelo tipo da linha, deixa o fluxo montar o
 * contexto e o pedido, chama o provedor, deixa o fluxo interpretar e grava o resultado.
 *
 * Fronteira de confiança (como o `SincronizarPastaNoDriveHandler`): o tenant da mensagem é
 * conferido contra a linha ANTES de qualquer coisa; divergência é log + no-op. Tenant explícito em
 * toda consulta daqui para baixo — o TenantFilter é inerte sem sessão.
 *
 * Destinos:
 *   · IA desligada desde a solicitação (plataforma, escritório ou permissão de quem pediu) →
 *     `indisponivel` (plataforma) ou `falhou` (os outros dois), com o motivo, SEM chamar o provedor;
 *   · sem provedor → `indisponivel` + Unrecoverable (sem retry; repetir não muda nada);
 *   · falha transitória do provedor com retentativa à frente → volta a `pendente` + re-lança (o
 *     Messenger retenta; a linha segue "em andamento" e um novo clique devolve a mesma, sem gasto
 *     duplo); na ÚLTIMA tentativa → `falhou` + re-lança (vai para `failed`);
 *   · falha definitiva (400/401/403) → `falhou` + Unrecoverable;
 *   · contexto bloqueado/vazio, resposta inválida, tipo sem fluxo → `falhou`, sem re-lançar.
 *
 * Tipo SEM pasta (o chat da Central, {@see FluxoDeAnaliseSemPasta}): mesma máquina de estados, sem procurar pasta e
 * sem notificar (a resposta aparece na própria conversa). A resposta gravada e a análise concluída vão numa
 * transação só. O miolo provedor → interpretação é o mesmo dos tipos com pasta.
 *
 * "Última tentativa" sai do contador da própria linha (`tentativas`, incrementado a cada entrega)
 * contra {@see TENTATIVAS_MAXIMAS}, que espelha `max_retries` do transport `async`.
 *
 * Log só com ids, tenant, tipo, agente, provedor, tokens, duração e classe do erro — NUNCA o prompt.
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

    public const MOTIVO_SEM_GRAVAR_A_RESPOSTA = 'não foi possível gravar a resposta na conversa';

    /** @var array<string, FluxoDeAnalise> chave = `TipoDeAnalise->value` */
    private array $fluxos;

    /** @var array<string, FluxoDeAnaliseSemPasta> chave = `TipoDeAnalise->value` */
    private array $fluxosSemPasta = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AnaliseDeInteligenciaRepository $analises,
        FluxoDoResumoDoPush $fluxoDoPush,
        FluxoDaAnaliseDaPasta $fluxoDaPasta,
        FluxoDaPerguntaLivre $fluxoDaPergunta,
        #[AutowireIterator(FluxoDeAnaliseSemPasta::TAG)]
        iterable $fluxosSemPasta,
        private readonly ProvedorDeLinguagem $provedor,
        private readonly NotificacaoService $notificacoes,
        private readonly UrlGeneratorInterface $urls,
        private readonly DisponibilidadeDeInteligencia $disponibilidade,
        #[Autowire(service: 'monolog.logger.inteligencia')]
        private readonly LoggerInterface $logger,
    ) {
        $this->fluxos = [
            $fluxoDoPush->tipo()->value => $fluxoDoPush,
            $fluxoDaPasta->tipo()->value => $fluxoDaPasta,
            $fluxoDaPergunta->tipo()->value => $fluxoDaPergunta,
        ];
        foreach ($fluxosSemPasta as $fluxo) {
            $this->fluxosSemPasta[$fluxo->tipo()->value] = $fluxo;
        }
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

        if (!$this->reservar($analise)) {
            return;
        }

        if (!$this->aindaDisponivel($analise, $tenant)) {
            return;
        }

        $semPasta = $this->fluxosSemPasta[$analise->getTipo()->value] ?? null;
        if ($semPasta !== null) {
            $this->processarSemPasta($semPasta, $analise, $tenant);

            return;
        }

        $fluxo = $this->fluxos[$analise->getTipo()->value] ?? null;
        if ($fluxo === null) {
            $this->falhar($analise, 'tipo de análise sem fluxo: ' . $analise->getTipo()->value);

            return;
        }

        $pasta = $this->em->find(Pasta::class, $analise->getAlvoId());
        if ($pasta === null || $pasta->getTenant()?->getId() !== $tenant->getId()) {
            $this->falhar($analise, 'pasta não encontrada neste escritório');

            return;
        }

        $pedido = $this->preparar($analise, static fn (): ?PedidoDeLinguagem => $fluxo->preparar($analise, $tenant, $pasta), $fluxo->motivoDeContextoVazio());
        if ($pedido === null) {
            return;
        }

        $concluido = $this->completarEInterpretar($analise, $pedido, $fluxo->interpretar(...));
        if ($concluido === null) {
            return;
        }

        [$resposta, $resultado] = $concluido;
        $this->aplicarResultado($analise, $resposta, $resultado);
        $this->em->flush();
        $this->registrarConclusao($analise, $resposta);

        $this->notificar($analise, $pasta, $fluxo);
    }

    /**
     * O ramo dos tipos sem pasta: não procura pasta, não notifica, e grava o efeito do fluxo (a resposta na conversa)
     * junto com a conclusão, numa transação só. Se o efeito for recusado ({@see \LogicException} ou
     * {@see \DomainException}, antes de gravar qualquer coisa), a análise termina `falhou` na mesma transação.
     */
    private function processarSemPasta(FluxoDeAnaliseSemPasta $fluxo, AnaliseDeInteligencia $analise, Tenant $tenant): void
    {
        $pedido = $this->preparar($analise, static fn (): ?PedidoDeLinguagem => $fluxo->preparar($analise, $tenant), $fluxo->motivoDeContextoVazio());
        if ($pedido === null) {
            return;
        }

        $concluido = $this->completarEInterpretar($analise, $pedido, $fluxo->interpretar(...));
        if ($concluido === null) {
            return;
        }

        [$resposta, $resultado] = $concluido;
        if ($this->gravarEConcluir($fluxo, $analise, $tenant, $resposta, $resultado)) {
            $this->registrarConclusao($analise, $resposta);
        }
    }

    /**
     * Efeito do fluxo e conclusão da análise numa transação só. Devolve false quando a análise terminou `falhou`
     * (efeito recusado ou falha inesperada); nunca relança: reentregar recobraria o provedor.
     */
    private function gravarEConcluir(FluxoDeAnaliseSemPasta $fluxo, AnaliseDeInteligencia $analise, Tenant $tenant, RespostaDeLinguagem $resposta, ResultadoDaAnalise $resultado): bool
    {
        try {
            return $this->em->wrapInTransaction(function () use ($fluxo, $analise, $tenant, $resposta, $resultado): bool {
                try {
                    $fluxo->aoConcluir($analise, $tenant, $resultado);
                } catch (\LogicException | \DomainException $e) {
                    // Motivo FIXO: a mensagem da exceção pode citar entidade e valores (texto da pessoa).
                    $analise->falhar(self::MOTIVO_SEM_GRAVAR_A_RESPOSTA, $resposta->texto);
                    $this->logger->warning('Análise {analise}: resposta recusada pela gravação ({classe}).', $this->contexto($analise) + ['classe' => $e::class]);

                    return false;
                }
                $this->aplicarResultado($analise, $resposta, $resultado);

                return true;
            });
        } catch (\Throwable $e) {
            // O wrapInTransaction fechou o EntityManager no rollback: só a conexão ainda grava. Sem isto a linha
            // ficaria em `processando` para sempre. NÃO relança: reentregar recobraria o provedor (reservar aceita Falhou).
            $this->falharPelaConexao($analise, $resposta->texto);
            $this->logger->error('Análise {analise}: falha inesperada ao gravar a resposta ({classe}).', $this->contexto($analise) + ['classe' => $e::class]);

            return false;
        }
    }

    /** Marca `falhou` direto na conexão (EntityManager fechado), com motivo fixo e sem texto da exceção; guarda o texto bruto do modelo (já cobrado). Só mexe na linha deste escritório que ainda está `processando`. */
    private function falharPelaConexao(AnaliseDeInteligencia $analise, string $textoBruto): void
    {
        try {
            $this->em->getConnection()->executeStatement(
                'UPDATE inteligencia_analise SET status = :status, erro_motivo = :motivo, texto_bruto = :bruto, concluida_em = :agora WHERE id = :id AND tenant_id = :tenant AND status = :processando',
                [
                    'status' => StatusDaAnalise::Falhou->value,
                    'motivo' => self::MOTIVO_SEM_GRAVAR_A_RESPOSTA,
                    'bruto' => $textoBruto,
                    'agora' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    'id' => $analise->getId(),
                    'tenant' => $analise->getTenant()?->getId(),
                    'processando' => StatusDaAnalise::Processando->value,
                ],
            );
        } catch (\Throwable $e) {
            $this->logger->error('Análise {analise}: não foi possível marcar a falha ({classe}).', $this->contexto($analise) + ['classe' => $e::class]);
        }
    }

    /**
     * O miolo comum: registra o provedor, chama-o, classifica a falha e interpreta a resposta. Devolve a resposta e o
     * resultado, ou null quando a análise já terminou aqui (`indisponivel`, `falhou`); falha do provedor com
     * retentativa ou definitiva re-lança.
     *
     * @param \Closure(string): ResultadoDaAnalise $interpretar
     *
     * @return array{0: RespostaDeLinguagem, 1: ResultadoDaAnalise}|null
     */
    private function completarEInterpretar(AnaliseDeInteligencia $analise, PedidoDeLinguagem $pedido, \Closure $interpretar): ?array
    {
        $analise->registrarProvedor($this->provedor->nome());

        try {
            $resposta = $this->provedor->completar($pedido);
        } catch (ProvedorIndisponivelException $e) {
            $analise->marcarIndisponivel($e->getMessage());
            $this->em->flush();
            $this->logger->info('Análise {analise} indisponível: provedor não configurado.', $this->contexto($analise));

            throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
        } catch (FalhaDoProvedorException $e) {
            if ($e->transitoria) {
                $this->falhaTransitoria($analise, $e, 'provedor', $e->esperarSegundos);
            }

            $this->falhar($analise, 'falha definitiva: ' . $e->getMessage());
            $this->logger->warning('Análise {analise} falhou no provedor ({classe}, transitória: {transitoria}).', $this->contexto($analise) + [
                'classe' => $e::class,
                'transitoria' => 'não',
            ]);

            throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
        }

        try {
            $resultado = $interpretar($resposta->texto);
        } catch (RespostaInvalidaException $e) {
            $this->falhar($analise, $e->getMessage(), $e->textoBruto);
            $this->logger->warning('Análise {analise}: resposta inválida do provedor.', $this->contexto($analise));

            return null;
        }

        return [$resposta, $resultado];
    }

    /** Muda o estado da análise para `concluida` (sem flush: quem chama decide a transação). */
    private function aplicarResultado(AnaliseDeInteligencia $analise, RespostaDeLinguagem $resposta, ResultadoDaAnalise $resultado): void
    {
        $analise->concluir(
            resumo: $resultado->resumo,
            pontos: $resultado->pontos,
            quemAge: $resultado->quemAge,
            textoBruto: $resposta->texto,
            provedor: $this->provedor->nome(),
            modelo: $resposta->modelo,
            tokensEntrada: $resposta->tokensEntrada,
            tokensSaida: $resposta->tokensSaida,
            duracaoMs: $resposta->duracaoMs,
            textoDaAnalise: $resultado->textoDaAnalise,
        );
    }

    private function registrarConclusao(AnaliseDeInteligencia $analise, RespostaDeLinguagem $resposta): void
    {
        $this->logger->info('Análise {analise} concluída.', $this->contexto($analise) + [
            'modelo' => $resposta->modelo,
            'tokens_entrada' => $resposta->tokensEntrada,
            'tokens_saida' => $resposta->tokensSaida,
            'duracao_ms' => $resposta->duracaoMs,
            'itens' => (int) ($analise->getContextoResumo()['total'] ?? 0),
        ]);
    }

    /**
     * Reserva a linha para este worker: trava a linha (SELECT ... FOR UPDATE), relê o status e só então passa a
     * `processando`. Sem a trava, uma mensagem reentregue ou dois workers liam `pendente` ao mesmo tempo e os dois
     * chamavam o provedor, que cobra as duas vezes. O segundo espera o primeiro gravar e encontra `processando`.
     */
    private function reservar(AnaliseDeInteligencia $analise): bool
    {
        return $this->em->wrapInTransaction(function () use ($analise): bool {
            $this->em->refresh($analise, LockMode::PESSIMISTIC_WRITE);

            if (!in_array($analise->getStatus(), [StatusDaAnalise::Pendente, StatusDaAnalise::Falhou], true)) {
                return false; // já processada ou em curso por outro worker — idempotente
            }

            if ($analise->estaExcluida()) {
                return false; // excluída antes de processar: não gastar cota com o que ninguém vai ler
            }

            $analise->iniciarProcessamento();
            $this->em->flush();

            return true;
        });
    }

    /**
     * IA-034L: desligar a IA (plataforma, escritório ou permissão de quem pediu) corta também o que
     * já está na fila. A pergunta é a mesma da solicitação, sem a cota — esta análise já foi
     * contada quando entrou na fila. Indisponível → a linha termina com o motivo e o provedor NÃO
     * é chamado. Sem re-lançar: é um desfecho, não um erro; repetir a mensagem não muda nada.
     */
    private function aindaDisponivel(AnaliseDeInteligencia $analise, Tenant $tenant): bool
    {
        $solicitante = $analise->getSolicitante();
        if ($solicitante === null) {
            $this->falhar($analise, AnaliseDeInteligenciaRepository::MOTIVO_CANCELADA_ANTES_DO_PROVEDOR . 'quem pediu a análise não existe mais');

            return false;
        }

        $disponibilidade = $this->disponibilidade->paraProcessamento($solicitante, $tenant);
        if ($disponibilidade->estaDisponivel()) {
            return true;
        }

        $motivo = AnaliseDeInteligenciaRepository::MOTIVO_CANCELADA_ANTES_DO_PROVEDOR . $disponibilidade->mensagem();
        if ($disponibilidade === Disponibilidade::NaoConfiguradaNaPlataforma) {
            $analise->registrarProvedor($this->provedor->nome());
            $analise->marcarIndisponivel($motivo);
            $this->em->flush();
            $this->logger->info('Análise {analise} cancelada: IA desligada na plataforma.', $this->contexto($analise));

            return false;
        }

        $this->falhar($analise, $motivo);

        return false;
    }

    /**
     * O pedido ao provedor, ou null quando a análise já terminou aqui (`falhou` com o motivo): contexto vazio,
     * sigilo, documento que não pode ser lido (PR F) ou análise inconsistente. Armazenamento fora do ar ao ler um
     * documento (PR F) é transitório: volta à fila como a falha de rede do provedor.
     */
    private function preparar(AnaliseDeInteligencia $analise, \Closure $montar, string $motivoDeContextoVazio): ?PedidoDeLinguagem
    {
        try {
            $pedido = $montar();
        } catch (ContextoBloqueadoException $e) {
            $this->falhar($analise, 'contexto bloqueado: ' . $e->getMessage());

            return null;
        } catch (DocumentoIlegivelException $e) {
            $this->falhar($analise, $e->getMessage());

            return null;
        } catch (ArmazenamentoIndisponivelException $e) {
            $this->falhaTransitoria($analise, $e, 'armazenamento', null);
        } catch (\LogicException $e) {
            $this->falhar($analise, 'análise inconsistente: ' . $e->getMessage());

            return null;
        }

        if ($pedido === null) {
            $this->falhar($analise, $motivoDeContextoVazio);
        }

        return $pedido;
    }

    /**
     * Falha que repetir pode resolver (rede/5xx/429 do provedor, I/O do armazenamento). Com retentativa à frente,
     * a linha volta à fila e continua "em andamento"; na última, falha e o Messenger leva a mensagem a `failed`.
     */
    private function falhaTransitoria(AnaliseDeInteligencia $analise, \RuntimeException $e, string $onde, ?int $esperarSegundos): never
    {
        $motivo = 'falha transitória: ' . $e->getMessage();

        if ($analise->getTentativas() < self::TENTATIVAS_MAXIMAS) {
            $analise->devolverParaFila($motivo);
            $this->em->flush();
            $this->logger->warning('Análise {analise}: falha transitória no ' . $onde . ', de volta à fila (tentativa {tentativas} de {max}).', $this->contexto($analise) + [
                'max' => self::TENTATIVAS_MAXIMAS,
            ]);

            if ($esperarSegundos !== null) {
                // O provedor disse quando voltar: re-tenta depois disso, não no atraso padrão do transport. O
                // limite de tentativas continua sendo o contador da linha (a recuperável não respeita max_retries).
                throw new RecoverableMessageHandlingException($e->getMessage(), 0, null, $esperarSegundos * 1000);
            }

            throw $e; // Messenger faz o retry
        }

        $this->falhar($analise, $motivo);
        $this->logger->warning('Análise {analise} falhou no ' . $onde . ' ({classe}, transitória: {transitoria}).', $this->contexto($analise) + [
            'classe' => $e::class,
            'transitoria' => 'sim',
        ]);

        throw $e; // última tentativa: o Messenger leva para `failed`
    }

    private function falhar(AnaliseDeInteligencia $analise, string $motivo, ?string $textoBruto = null): void
    {
        $analise->falhar($motivo, $textoBruto);
        $this->em->flush();
        $this->logger->warning('Análise {analise} falhou: {motivo}.', $this->contexto($analise) + ['motivo' => $motivo]);
    }

    /** Aviso no sino de quem pediu. Falha aqui não desfaz a análise já gravada: só loga. */
    private function notificar(AnaliseDeInteligencia $analise, Pasta $pasta, FluxoDeAnalise $fluxo): void
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
                $fluxo->textoDaNotificacao($analise, $pasta),
                $this->urls->generate('pasta_show', ['id' => $pasta->getId()]) . $fluxo->ancoraDaPasta(),
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
            'agente' => $analise->getAgente()?->value,
            'comando' => $analise->getComando()?->value,
            'provedor' => $this->provedor->nome(),
            'tentativas' => $analise->getTentativas(),
        ];
    }
}
