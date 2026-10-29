<?php

declare(strict_types=1);

namespace App\ChatIa\UseCase;

use App\Central\Exception\PastaDaIaNaoEncontradaException;
use App\ChatIa\DTO\EnviarMensagemInput;
use App\ChatIa\DTO\RespostaPreparada;
use App\ChatIa\Entity\AnexoDeChat;
use App\ChatIa\Entity\ConversaDeChat;
use App\ChatIa\Entity\MensagemDeChat;
use App\ChatIa\Enum\PapelDaMensagem;
use App\ChatIa\Exception\AnexoDoChatInvalidoException;
use App\ChatIa\Exception\SemAceiteNoChatException;
use App\ChatIa\Exception\SemAcessoAoChatException;
use App\ChatIa\Exception\SemChaveNoChatException;
use App\ChatIa\Exception\TextoDoChatInvalidoException;
use App\ChatIa\Repository\AnexoDeChatRepository;
use App\ChatIa\Repository\MensagemDeChatRepository;
use App\ChatIa\Service\AcessoAoChat;
use App\ChatIa\Service\ContextoDaPastaNoChat;
use App\ChatIa\Service\MontadorDoPedidoDoChat;
use App\ChatIa\Service\ProtecaoDoTextoDoChat;
use App\ChatIa\Service\TetoDeUsoDoChat;
use App\ChatIa\Service\TransacaoDoChat;
use App\ChatIa\Armazenamento\ChavesDeChatIa;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Enum\Disponibilidade;
use App\Inteligencia\Exception\ContextoBloqueadoException;
use App\Inteligencia\Exception\InteligenciaIndisponivelException;
use App\Inteligencia\Service\DisponibilidadeDeInteligencia;
use App\Inteligencia\Service\ProvedorDeLinguagem;
use App\Inteligencia\Service\RegraDaLeituraDeDocumentos;
use App\Pasta\Entity\Pasta;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use Psr\Clock\ClockInterface;

/**
 * A PREPARAÇÃO de uma pergunta do chat (a resposta em fluxo é do {@see TransmitirRespostaDoChatUseCase}). Tudo o que
 * pode recusar acontece AQUI, antes de o servidor abrir o fluxo, para a recusa sair como JSON com o status certo.
 *
 * Ordem: vínculo e módulo → conversa DO DONO (404) → provedor configurado (503 `sem_chave`) → disponibilidade da I.A
 * (escritório, cota) → teto diário de tokens (429) → pasta citada (aceite 409, 404, segredo 409) → anexos (posse,
 * aceite, quantidade) → grava a pergunta (e apaga a resposta que será substituída) numa transação → monta o pedido.
 *
 * Três modos: pergunta nova; `regenerar` (reenvia a última pergunta e SUBSTITUI a última resposta, com a mesma pasta e
 * os mesmos anexos); `editarUltima` (apaga a última pergunta e o que veio depois e grava a nova; por padrão MANTÉM a pasta e
 * os anexos da antiga. Se vier `pastaId`, vale ele; se vier `anexoIds`, valem eles: os da antiga ali listados são
 * reaproveitados, os demais da antiga apagados, e os soltos novos entram, tudo validado como numa pergunta nova). Arquivo apagado só sai do disco depois do COMMIT.
 *
 * @throws SemAcessoAoChatException
 * @throws \App\ChatIa\Exception\ConversaDeChatNaoEncontradaException
 * @throws SemChaveNoChatException
 * @throws InteligenciaIndisponivelException
 * @throws \App\ChatIa\Exception\TetoDiarioDoChatException
 * @throws SemAceiteNoChatException
 * @throws PastaDaIaNaoEncontradaException
 * @throws ContextoBloqueadoException
 * @throws TextoDoChatInvalidoException
 * @throws AnexoDoChatInvalidoException
 */
final class EnviarMensagemNoChatUseCase
{
    public function __construct(
        private readonly AcessoAoChat $acesso,
        private readonly ProvedorDeLinguagem $provedor,
        private readonly DisponibilidadeDeInteligencia $disponibilidade,
        private readonly TetoDeUsoDoChat $teto,
        private readonly ContextoDaPastaNoChat $contextoDaPasta,
        private readonly RegraDaLeituraDeDocumentos $regraDosDocumentos,
        private readonly MensagemDeChatRepository $mensagens,
        private readonly AnexoDeChatRepository $anexos,
        private readonly MontadorDoPedidoDoChat $montador,
        private readonly ProtecaoDoTextoDoChat $protecao,
        private readonly TransacaoDoChat $transacao,
        private readonly RemocaoAposTransacao $remocao,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(User $eu, ?Tenant $tenant, int $conversaId, EnviarMensagemInput $input): RespostaPreparada
    {
        $tenant = $this->acesso->exigir($eu, $tenant);
        $conversa = $this->acesso->conversaDoDono($conversaId, $eu, $tenant);
        $this->conferirADisponibilidade($eu, $tenant);
        $this->teto->conferirODia($eu, $tenant);

        $plano = $this->planejar($conversa, $tenant, $input);
        $pasta = $this->lerAPasta($eu, $tenant, $input->regenerar ? $plano['pastaId'] : ($input->pastaId ?? $plano['pastaId']));
        $anexos = $this->anexosDoEnvio($eu, $tenant, $conversa, $input, $plano);
        $primeiraResposta = $conversa->getTitulo() === null;

        $pergunta = $this->gravar($conversa, $tenant, $plano, $pasta['pasta'] ?? null, $anexos);
        $pedido = $this->montador->montar(
            $eu,
            $tenant,
            $conversa->getModelo()->value,
            $this->anteriores($conversa, $tenant, $pergunta),
            $plano['texto'],
            $pasta['blocos'] ?? [],
            $anexos,
            $this->anexosAnteriores($conversa, $tenant, $pergunta),
        );

        return new RespostaPreparada($conversa, $tenant, $conversa->getModelo(), $pedido, $this->protecao->livre($tenant, $plano['texto']), $primeiraResposta);
    }

    /** @throws SemChaveNoChatException|InteligenciaIndisponivelException */
    private function conferirADisponibilidade(User $eu, Tenant $tenant): void
    {
        if (!$this->provedor->estaConfigurado()) {
            throw new SemChaveNoChatException();
        }
        $disponibilidade = $this->disponibilidade->para($eu, $tenant);
        if ($disponibilidade === Disponibilidade::NaoConfiguradaNaPlataforma) {
            throw new SemChaveNoChatException();
        }
        if (!$disponibilidade->estaDisponivel()) {
            throw new InteligenciaIndisponivelException($disponibilidade);
        }
    }

    /**
     * O que este envio faz com o histórico: o texto da pergunta, a pasta que ela já tinha (só na regeneração), as
     * mensagens a apagar e os anexos da pergunta antiga.
     *
     * @return array{texto: string, nova: bool, pastaId: ?int, pergunta: ?MensagemDeChat, apagar: list<MensagemDeChat>, antigos: list<AnexoDeChat>}
     */
    private function planejar(ConversaDeChat $conversa, Tenant $tenant, EnviarMensagemInput $input): array
    {
        $plano = ['texto' => $input->texto, 'nova' => true, 'pastaId' => null, 'pergunta' => null, 'apagar' => [], 'antigos' => []];
        if (!$input->regenerar && !$input->editarUltima) {
            return $plano;
        }

        $ultima = $this->mensagens->ultimaDoPapel($conversa, $tenant, PapelDaMensagem::Usuario)
            ?? throw new TextoDoChatInvalidoException('Não há pergunta anterior nesta conversa.');
        $depois = array_values(array_filter(
            $this->mensagens->daConversa($conversa, $tenant),
            static fn (MensagemDeChat $m): bool => $m->getId() > $ultima->getId(),
        ));
        $plano['antigos'] = $this->anexos->daMensagem($ultima, $tenant);
        if ($input->editarUltima) {
            return ['apagar' => [$ultima, ...$depois], 'pastaId' => $ultima->getPasta()?->getId()] + $plano;
        }

        return [
            'texto' => $ultima->getConteudo(),
            'nova' => false,
            'pastaId' => $ultima->getPasta()?->getId(),
            'pergunta' => $ultima,
            'apagar' => $depois,
        ] + $plano;
    }

    /**
     * @return array{pasta: Pasta, blocos: list<string>}|null
     */
    private function lerAPasta(User $eu, Tenant $tenant, ?int $pastaId): ?array
    {
        return $pastaId === null ? null : $this->contextoDaPasta->ler($eu, $tenant, $pastaId);
    }

    /**
     * Os anexos desta pergunta. Regenerar mantém os da pergunta, e a edição também quando `anexoIds` não veio; nos
     * outros casos valem os `anexoIds` (soltos, do dono, desta conversa; na edição, também os que já eram da antiga). Com qualquer anexo, o aceite precisa cobrir
     * a leitura de documentos.
     *
     * @param array{texto: string, nova: bool, pastaId: ?int, pergunta: ?MensagemDeChat, apagar: list<MensagemDeChat>, antigos: list<AnexoDeChat>} $plano
     *
     * @return list<AnexoDeChat>
     */
    private function anexosDoEnvio(User $eu, Tenant $tenant, ConversaDeChat $conversa, EnviarMensagemInput $input, array $plano): array
    {
        if (!$plano['nova'] || ($input->editarUltima && $input->anexoIds === null)) {
            $anexos = $plano['antigos'];
        } else {
            $ids = $input->anexoIds ?? [];
            $antigos = $input->editarUltima ? array_values(array_filter($plano['antigos'], static fn (AnexoDeChat $a): bool => \in_array($a->getId(), $ids, true))) : [];
            $idsSoltos = array_values(array_diff($ids, array_map(static fn (AnexoDeChat $a): int => (int) $a->getId(), $antigos)));
            $soltos = $this->anexos->soltosDoDono($idsSoltos, $tenant, $eu, $conversa);
            if (\count($soltos) !== \count($idsSoltos)) {
                throw new AnexoDoChatInvalidoException('Um dos anexos não foi encontrado. Anexe de novo.');
            }
            $anexos = [...$antigos, ...$soltos];
        }

        if ($anexos !== [] && !$this->regraDosDocumentos->aceiteCobre($tenant)) {
            throw new SemAceiteNoChatException('O escritório precisa renovar o aceite da I.A (leitura de documentos) para anexar arquivos.');
        }

        return $anexos;
    }

    /**
     * Grava, numa transação: apaga o que a regeneração/edição substitui, grava a pergunta nova (ou mantém a reenviada),
     * amarra os anexos e marca a atividade. Arquivo dos anexos apagados sai depois do COMMIT.
     *
     * @param array{texto: string, nova: bool, pastaId: ?int, pergunta: ?MensagemDeChat, apagar: list<MensagemDeChat>, antigos: list<AnexoDeChat>} $plano
     * @param list<AnexoDeChat>                                                                                                                    $anexos
     */
    private function gravar(ConversaDeChat $conversa, Tenant $tenant, array $plano, ?Pasta $pasta, array $anexos): MensagemDeChat
    {
        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());
        $mantidos = array_map(static fn (AnexoDeChat $a): ?int => $a->getId(), $anexos);
        $chavesApagadas = [];
        foreach ($plano['antigos'] as $antigo) {
            if (!\in_array($antigo->getId(), $mantidos, true)) {
                $chavesApagadas[] = ChavesDeChatIa::anexo($antigo);
            }
        }

        $pergunta = $this->transacao->executar(function () use ($conversa, $plano, $pasta, $anexos, $agora): MensagemDeChat {
            foreach ($plano['antigos'] as $antigo) {
                if (!\in_array($antigo, $anexos, true)) {
                    $this->anexos->remover($antigo);
                }
            }
            $pergunta = $plano['pergunta'] ?? MensagemDeChat::doUsuario($conversa, $plano['texto'], $pasta, $agora);
            foreach ($plano['apagar'] as $mensagem) {
                if ($mensagem->guardaCustoAoSerSubstituida()) {
                    $mensagem->substituir();   // o texto sai, o custo fica no teto do dia
                } else {
                    $this->mensagens->remover($mensagem);
                }
            }
            if ($plano['nova']) {
                $this->mensagens->salvar($pergunta);
            }
            foreach ($anexos as $anexo) {
                $anexo->anexarA($pergunta);
            }
            $conversa->registrarAtividade($agora);

            return $pergunta;
        });

        $this->remocao->remover($chavesApagadas, 'chat_ia_pergunta_refeita');

        return $pergunta;
    }

    /**
     * Os anexos das perguntas ANTERIORES, para o arquivo seguir no contexto (quem decide quantos cabem é o montador).
     *
     * @return list<AnexoDeChat>
     */
    private function anexosAnteriores(ConversaDeChat $conversa, Tenant $tenant, MensagemDeChat $pergunta): array
    {
        return array_values(array_filter(
            $this->anexos->daConversa($conversa, $tenant),
            static fn (AnexoDeChat $a): bool => $a->getMensagem() !== null && $a->getMensagem()->getId() < $pergunta->getId(),
        ));
    }

    /**
     * As falas ANTES da pergunta (o histórico do pedido). Lê depois da gravação, então a regeneração/edição já não
     * enxerga o que foi apagado.
     *
     * @return list<MensagemDeChat>
     */
    private function anteriores(ConversaDeChat $conversa, Tenant $tenant, MensagemDeChat $pergunta): array
    {
        return array_values(array_filter(
            $this->mensagens->daConversa($conversa, $tenant),
            static fn (MensagemDeChat $m): bool => $m->getId() < $pergunta->getId(),
        ));
    }
}
