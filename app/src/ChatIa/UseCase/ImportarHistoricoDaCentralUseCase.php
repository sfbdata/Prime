<?php

declare(strict_types=1);

namespace App\ChatIa\UseCase;

use App\Central\Repository\ConversaRepository;
use App\Central\Repository\MensagemRepository;
use App\Central\Repository\ParticipanteRepository;
use App\ChatIa\Entity\ConversaDeChat;
use App\ChatIa\Entity\MensagemDeChat;
use App\ChatIa\Enum\OrigemDaConversa;
use App\ChatIa\Repository\ConversaDeChatRepository;
use App\ChatIa\Repository\MensagemDeChatRepository;
use App\ChatIa\Service\AcessoAoChat;
use App\ChatIa\Service\TransacaoDoChat;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Enum\ModeloDeIa;

/**
 * Traz UMA vez o histórico antigo da conversa "BlueJus IA" da Central (`central_mensagem` da conversa tipo `ia` desta pessoa)
 * para o Chat I.A, como uma ConversaDeChat "Conversa anterior com a BlueJus IA" (`origem = importada`). A fala da IA
 * (`de_ia`) vira resposta do assistente; as demais, pergunta da pessoa. Tokens nulos (o consumo antigo não entra no teto).
 *
 * Idempotente: se a pessoa já tem a conversa importada, ou não há nada a trazer, devolve sem criar. Só o DONO (a conversa
 * IA é de uma pessoa) e só o que ela não limpou ("Excluir" na Central). Nada é apagado na Central. O texto não vai a log nem a audit.
 */
final class ImportarHistoricoDaCentralUseCase
{
    public const TITULO = 'Conversa anterior com a BlueJus IA';
    public const MAXIMO_DE_MENSAGENS = 1000;

    public function __construct(
        private readonly AcessoAoChat $acesso,
        private readonly ConversaRepository $conversasDaCentral,
        private readonly MensagemRepository $mensagensDaCentral,
        private readonly ParticipanteRepository $participantes,
        private readonly ConversaDeChatRepository $conversas,
        private readonly MensagemDeChatRepository $mensagens,
        private readonly TransacaoDoChat $transacao,
    ) {
    }

    /** @return int|null o id da conversa importada (nova ou já existente); nulo quando não havia histórico */
    public function executar(User $eu, ?Tenant $tenant): ?int
    {
        $tenant = $this->acesso->exigir($eu, $tenant);
        $jaImportada = $this->conversas->importadaDoDono($tenant, $eu);
        if ($jaImportada !== null) {
            return (int) $jaImportada->getId();
        }

        $antiga = $this->conversasDaCentral->daIaDe($tenant, $eu);
        if ($antiga === null) {
            return null;
        }
        $participacao = $this->participantes->daConversa((int) $antiga->getId(), $eu, $tenant);
        $falas = $this->mensagensDaCentral->historico($antiga, $tenant, null, self::MAXIMO_DE_MENSAGENS, $participacao?->getLimpaAteId() ?? 0);
        $falas = array_values(array_filter($falas, static fn ($m): bool => !$m->isSistema() && trim($m->getConteudo()) !== ''));
        if ($falas === []) {
            return null;
        }

        $conversa = new ConversaDeChat($tenant, $eu, ModeloDeIa::PADRAO, $falas[0]->getCriadaEm());
        $conversa->nascerEm(OrigemDaConversa::Importada);
        $conversa->renomear(self::TITULO);

        $this->transacao->executar(function () use ($conversa, $falas): void {
            $this->conversas->salvar($conversa);
            foreach ($falas as $fala) {
                $this->mensagens->salvar($fala->isDeIa()
                    ? MensagemDeChat::doAssistente($conversa, $fala->getConteudo(), ModeloDeIa::PADRAO->value, null, null, false, $fala->getCriadaEm())
                    : MensagemDeChat::doUsuario($conversa, $fala->getConteudo(), null, $fala->getCriadaEm()));
            }
            $conversa->registrarAtividade($falas[array_key_last($falas)]->getCriadaEm());
        });

        return (int) $conversa->getId();
    }
}
