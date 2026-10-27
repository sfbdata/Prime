<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\DTO\PerguntarABlueJusIaInput;
use App\Central\DTO\PerguntaDaIaOutput;
use App\Central\Entity\Conversa;
use App\Central\Entity\Mensagem;
use App\Central\Exception\ConversaNaoEncontradaException;
use App\Central\Repository\ConversaRepository;
use App\Central\Repository\MensagemRepository;
use App\Central\Service\AcessoACentral;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Exception\FilaIndisponivelException;
use App\Inteligencia\Exception\InteligenciaIndisponivelException;
use App\Inteligencia\Exception\PerguntaInvalidaException;
use App\Inteligencia\Prompt\PromptDoChatDaCentral;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Service\DisponibilidadeDeInteligencia;
use App\Inteligencia\Service\EnfileiradorDeAnalise;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Pergunta ao BlueJus IA na conversa da Central (F3, `docs/specs/ia-chat-central.md`, PR 2b); a rota é
 * `IaDaCentralController` (PR 3a).
 *
 * Pergunta em andamento há mais de {@see self::MINUTOS_ATE_CONSIDERAR_PRESA} minutos não conta (worker morto): a nova
 * pergunta a encerra como `falhou` e segue.
 *
 * Ordem: vínculo ativo, texto válido, conversa DELA (abre se não existir; a posse é "é a conversa IA dele", por isso
 * não passa por `participacao()` nem pelo `EnviarMensagemUseCase`, que excluem o tipo `ia`), disponibilidade da IA
 * (plataforma, escritório, módulo, cota, orçamento) e então, numa transação com `FOR UPDATE` na conversa: se já há
 * pergunta em andamento devolve a mesma (sem mensagem nova, sem cota, sem limitador); senão roda `$antesDeCriar` (o
 * limitador) e grava a mensagem da pessoa e a análise `pendente` juntas. A mensagem sai para a fila SÓ depois do commit.
 *
 * A transação é pela conexão (e não `wrapInTransaction`): a recusa do limitador não pode fechar o EntityManager.
 * A mensagem fica gravada mesmo que a IA falhe depois (nenhuma resposta é fabricada). O texto nunca vai para a análise
 * (`contexto_resumo` guarda só ids e contagens), nem para log.
 *
 * @throws \App\Central\Exception\SemAcessoACentralException
 * @throws PerguntaInvalidaException
 * @throws InteligenciaIndisponivelException
 * @throws FilaIndisponivelException
 * @throws \App\Central\Exception\ConversaCriadaAoMesmoTempoException na primeira pergunta com duas abas (a tela repete)
 */
final class PerguntarABlueJusIaUseCase
{
    /** Depois disso sem resposta, a pergunta é dada como presa e não bloqueia a próxima (PR 3 da F3). */
    public const MINUTOS_ATE_CONSIDERAR_PRESA = 10;

    public const MOTIVO_PRESA = 'pergunta sem resposta por mais de 10 minutos';

    public function __construct(
        private readonly AcessoACentral $acesso,
        private readonly ConversaRepository $conversas,
        private readonly MensagemRepository $mensagens,
        private readonly AnaliseDeInteligenciaRepository $analises,
        private readonly AbrirConversaComAIaUseCase $abrirConversa,
        private readonly DisponibilidadeDeInteligencia $disponibilidade,
        private readonly EnfileiradorDeAnalise $enfileirador,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function executar(User $eu, ?Tenant $tenant, string $texto, ?callable $antesDeCriar = null): PerguntaDaIaOutput
    {
        $tenant = $this->acesso->exigirVinculoAtivo($eu, $tenant);
        $input = new PerguntarABlueJusIaInput($texto);
        $conversa = $this->conversaDe($eu, $tenant);

        $disponibilidade = $this->disponibilidade->para($eu, $tenant);
        if (!$disponibilidade->estaDisponivel()) {
            throw new InteligenciaIndisponivelException($disponibilidade);
        }

        [$analise, $mensagemId, $nova] = $this->gravarTravado($input->texto, $conversa, $eu, $tenant, $antesDeCriar);

        if ($nova) {
            // Depois do commit: o worker não pode receber o id de uma linha que ainda não existe para ele.
            $this->enfileirador->enfileirar($analise, $tenant);
        }

        return new PerguntaDaIaOutput(
            (int) $analise->getId(),
            (int) $conversa->getId(),
            $mensagemId,
            $analise->getStatus()->value,
            !$nova,
        );
    }

    private function conversaDe(User $eu, Tenant $tenant): Conversa
    {
        $conversa = $this->conversas->daIaDe($tenant, $eu);
        if ($conversa === null) {
            $this->abrirConversa->executar($eu, $tenant);
            $conversa = $this->conversas->daIaDe($tenant, $eu);
        }

        return $conversa ?? throw new ConversaNaoEncontradaException(0);
    }

    /**
     * @return array{AnaliseDeInteligencia, int, bool} a análise, o id da mensagem da pergunta e se foi criada agora
     */
    private function gravarTravado(string $texto, Conversa $conversa, User $eu, Tenant $tenant, ?callable $antesDeCriar): array
    {
        $conn = $this->entityManager->getConnection();
        $conn->beginTransaction();
        try {
            $this->conversas->travar($conversa, $tenant);

            $alvoId = (int) $conversa->getId();
            // Pendente/processando mais velha que o limite é pergunta presa (worker morto, fila fora): não conta.
            $corte = $this->clock->now()->modify(sprintf('-%d minutes', self::MINUTOS_ATE_CONSIDERAR_PRESA));
            $emAndamento = $this->analises->findPendenteDoAlvo($tenant, AnaliseDeInteligencia::ALVO_CONVERSA_IA, $alvoId, TipoDeAnalise::ChatDaCentral, criadaApos: $corte);
            if ($emAndamento !== null) {
                $resultado = [$emAndamento, (int) ($emAndamento->getContextoResumo()['mensagemId'] ?? 0), false];
            } else {
                if ($antesDeCriar !== null) {
                    $antesDeCriar();
                }
                $this->encerrarAPresa($tenant, $alvoId);
                $resultado = $this->registrar($texto, $conversa, $eu, $tenant);
            }
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();

            throw $e;
        }

        return $resultado;
    }

    /**
     * A pergunta presa (mais velha que o limite, ainda `pendente`/`processando`) termina `falhou` com motivo fixo: some
     * do "em andamento" para sempre e a auditoria a mostra como falha. Vai no flush do `registrar`, na mesma transação.
     */
    private function encerrarAPresa(Tenant $tenant, int $conversaId): void
    {
        $presa = $this->analises->findPendenteDoAlvo($tenant, AnaliseDeInteligencia::ALVO_CONVERSA_IA, $conversaId, TipoDeAnalise::ChatDaCentral);
        if ($presa !== null) {
            $presa->falhar(self::MOTIVO_PRESA);
        }
    }

    /**
     * Mensagem da pessoa e análise `pendente`, na mesma transação. Sem menção, aviso nem resposta_a: é conversa privada.
     *
     * @return array{AnaliseDeInteligencia, int, bool}
     */
    private function registrar(string $texto, Conversa $conversa, User $eu, Tenant $tenant): array
    {
        $agora = $this->clock->now();
        $mensagem = new Mensagem($tenant, $conversa, $eu, $texto, $agora);
        $this->mensagens->salvar($mensagem);
        $conversa->registrarMensagem($agora);

        // O id da mensagem só existe depois do INSERT (identity), e o fluxo do worker exige `mensagemId` no resumo.
        // Dois flushes, mas uma transação só: nada disso aparece para o worker antes do commit.
        $this->entityManager->flush();
        $mensagemId = (int) $mensagem->getId();
        if ($mensagemId <= 0) {
            throw new \LogicException('A mensagem da pergunta ficou sem id.');
        }
        $conversaId = (int) $conversa->getId();

        $analise = new AnaliseDeInteligencia(
            tenant: $tenant,
            solicitante: $eu,
            tipo: TipoDeAnalise::ChatDaCentral,
            alvoTipo: AnaliseDeInteligencia::ALVO_CONVERSA_IA,
            alvoId: $conversaId,
            versaoDoPrompt: PromptDoChatDaCentral::VERSAO,
            contextoHash: hash('sha256', $conversaId . '|' . $mensagemId),
            contextoResumo: ['conversaId' => $conversaId, 'mensagemId' => $mensagemId, 'historico' => 0, 'total' => 1],
        );
        $this->analises->salvar($analise);
        $this->entityManager->flush();

        return [$analise, $mensagemId, true];
    }
}
