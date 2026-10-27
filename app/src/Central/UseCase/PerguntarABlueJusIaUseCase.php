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
use App\Central\Service\PastaDoContextoDaIa;
use App\Central\Service\PastasDosClientesDaIa;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Exception\ContextoBloqueadoException;
use App\Inteligencia\Exception\CruzamentoDePastasSemAceiteException;
use App\Inteligencia\Exception\FilaIndisponivelException;
use App\Inteligencia\Exception\InteligenciaIndisponivelException;
use App\Inteligencia\Exception\PerguntaInvalidaException;
use App\Inteligencia\Prompt\PromptDoChatDaCentral;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
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
 * Contexto da tela (PR 4a): com `$pastaId`, DEPOIS da disponibilidade e ANTES da transação travada, a pasta é validada por
 * {@see PastaDoContextoDaIa} (404 igual para inexistente, de outro escritório ou oculta pelo cadeado; 409 para segredo de
 * justiça). A recusa sai antes de `beginTransaction`: nada gravado, nada travado, EntityManager aberto. O resumo guarda SÓ
 * `pastaId`, `financeiro` e `abasOcultas` (ids e flags, nunca texto nem NUP) e o hash passa a incluir a pasta. Se já há
 * pergunta em andamento, ela é devolvida como está e o `pastaId` novo é ignorado. Sem `$pastaId`, tudo como antes.
 *
 * Escopo `clientes` (PR 4c, D12): exige `pastaId`. Antes de tudo confere o aceite do escritório
 * ({@see CruzamentoDePastasSemAceiteException}, 409) e então {@see PastasDosClientesDaIa} escolhe as outras pastas (visíveis à
 * pessoa, teto 5 com a aberta, segredo omitido e contado). O resumo guarda o escopo, os ids e as decisões por pasta; o prompt é
 * o `chat-central-v3` e o hash inclui o escopo e as pastas. A pasta aberta com segredo segue 409.
 *
 * A transação é pela conexão (e não `wrapInTransaction`): a recusa do limitador não pode fechar o EntityManager.
 * A mensagem fica gravada mesmo que a IA falhe depois (nenhuma resposta é fabricada). O texto nunca vai para a análise
 * (`contexto_resumo` guarda só ids e contagens), nem para log.
 *
 * @throws \App\Central\Exception\SemAcessoACentralException
 * @throws PerguntaInvalidaException
 * @throws InteligenciaIndisponivelException
 * @throws FilaIndisponivelException
 * @throws \App\Central\Exception\PastaDaIaNaoEncontradaException
 * @throws ContextoBloqueadoException
 * @throws CruzamentoDePastasSemAceiteException
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
        private readonly PastaDoContextoDaIa $pastaDoContexto,
        private readonly PastasDosClientesDaIa $pastasDosClientes,
        private readonly ConfiguracaoDeInteligenciaRepository $configuracoes,
    ) {
    }

    public function executar(User $eu, ?Tenant $tenant, string $texto, ?callable $antesDeCriar = null, ?int $pastaId = null, ?string $escopo = null): PerguntaDaIaOutput
    {
        $tenant = $this->acesso->exigirVinculoAtivo($eu, $tenant);
        $input = new PerguntarABlueJusIaInput($texto, $pastaId, $escopo);
        $conversa = $this->conversaDe($eu, $tenant);

        $disponibilidade = $this->disponibilidade->para($eu, $tenant);
        if (!$disponibilidade->estaDisponivel()) {
            throw new InteligenciaIndisponivelException($disponibilidade);
        }

        // Fora da trava: a recusa da pasta (404/409) não pode deixar transação aberta nem fechar o EntityManager.
        $comClientes = $input->escopo === PerguntarABlueJusIaInput::ESCOPO_CLIENTES;
        if ($comClientes && !($this->configuracoes->findDoTenant($tenant)?->aceiteDeEnvioDado() ?? false)) {
            throw new CruzamentoDePastasSemAceiteException();
        }
        $pasta = $input->pastaId !== null ? $this->pastaDoContexto->liberar($eu, $tenant, $input->pastaId) : null;
        $adicionais = $pasta !== null && $comClientes ? $this->pastasDosClientes->adicionais($eu, $tenant, $pasta['pastaId']) : null;

        [$analise, $mensagemId, $nova] = $this->gravarTravado($input->texto, $conversa, $eu, $tenant, $antesDeCriar, $pasta, $adicionais);

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
     * @param array{pastaId: int, financeiro: bool, abasOcultas: list<string>}|null $pasta a decisão da pasta da tela
 * @param array{pastas: list<array{pastaId: int, financeiro: bool, abasOcultas: list<string>}>, naoConsultadas: int}|null $adicionais as outras pastas (escopo `clientes`)
     *
     * @return array{AnaliseDeInteligencia, int, bool} a análise, o id da mensagem da pergunta e se foi criada agora
     */
    private function gravarTravado(string $texto, Conversa $conversa, User $eu, Tenant $tenant, ?callable $antesDeCriar, ?array $pasta, ?array $adicionais): array
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
                $resultado = $this->registrar($texto, $conversa, $eu, $tenant, $pasta, $adicionais);
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
     * @param array{pastaId: int, financeiro: bool, abasOcultas: list<string>}|null $pasta
 * @param array{pastas: list<array{pastaId: int, financeiro: bool, abasOcultas: list<string>}>, naoConsultadas: int}|null $adicionais
     *
     * @return array{AnaliseDeInteligencia, int, bool}
     */
    private function registrar(string $texto, Conversa $conversa, User $eu, Tenant $tenant, ?array $pasta, ?array $adicionais): array
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

        $analise = $this->novaAnalise($eu, $tenant, $conversaId, $mensagemId, $pasta, $adicionais);
        $this->analises->salvar($analise);
        $this->entityManager->flush();

        return [$analise, $mensagemId, true];
    }

    /**
     * A análise `pendente` da pergunta. O resumo guarda só ids e flags (o texto e os NUPs da pasta o worker relê; aqui nunca se gravam).
     *
     * @param array{pastaId: int, financeiro: bool, abasOcultas: list<string>}|null                                       $pasta
     * @param array{pastas: list<array{pastaId: int, financeiro: bool, abasOcultas: list<string>}>, naoConsultadas: int}|null $adicionais
     */
    private function novaAnalise(User $eu, Tenant $tenant, int $conversaId, int $mensagemId, ?array $pasta, ?array $adicionais): AnaliseDeInteligencia
    {
        $resumo = ['conversaId' => $conversaId, 'mensagemId' => $mensagemId, 'historico' => 0, 'total' => 1];
        if ($pasta !== null) {
            $resumo += ['pastaId' => $pasta['pastaId'], 'financeiro' => $pasta['financeiro'], 'abasOcultas' => $pasta['abasOcultas']];
        }
        if ($pasta !== null && $adicionais !== null) {
            $resumo += $this->resumoDosClientes($pasta, $adicionais);
        }

        return new AnaliseDeInteligencia(
            tenant: $tenant,
            solicitante: $eu,
            tipo: TipoDeAnalise::ChatDaCentral,
            alvoTipo: AnaliseDeInteligencia::ALVO_CONVERSA_IA,
            alvoId: $conversaId,
            versaoDoPrompt: match (true) {
                $adicionais !== null => PromptDoChatDaCentral::VERSAO_COM_CLIENTES,
                $pasta !== null => PromptDoChatDaCentral::VERSAO_COM_PASTA,
                default => PromptDoChatDaCentral::VERSAO,
            },
            contextoHash: hash('sha256', $conversaId . '|' . $mensagemId . ($pasta !== null ? '|pasta:' . $pasta['pastaId'] : '') . ($adicionais !== null ? '|clientes:' . implode(',', $resumo['pastasIds']) : '')),
            contextoResumo: $resumo,
        );
    }

    /**
     * O escopo `clientes` no resumo: a flag, os ids (a aberta primeiro) e a decisão de cada pasta. Nunca texto nem NUP.
     *
     * @param array{pastaId: int, financeiro: bool, abasOcultas: list<string>}                                                 $aberta
     * @param array{pastas: list<array{pastaId: int, financeiro: bool, abasOcultas: list<string>}>, naoConsultadas: int} $adicionais
     *
     * @return array{escopo: string, pastasIds: list<int>, pastas: list<array{pastaId: int, financeiro: bool, abasOcultas: list<string>}>, naoConsultadas: int}
     */
    private function resumoDosClientes(array $aberta, array $adicionais): array
    {
        $pastas = [$aberta, ...$adicionais['pastas']];

        return [
            'escopo' => PerguntarABlueJusIaInput::ESCOPO_CLIENTES,
            'pastasIds' => array_map(static fn (array $p): int => $p['pastaId'], $pastas),
            'pastas' => $pastas,
            'naoConsultadas' => $adicionais['naoConsultadas'],
        ];
    }
}
