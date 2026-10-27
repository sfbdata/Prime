<?php

declare(strict_types=1);

namespace App\Central\Service;

use App\Central\Entity\Conversa;
use App\Central\Entity\Mensagem;
use App\Central\Entity\TipoDeConversa;
use App\Central\Repository\ConversaRepository;
use App\Central\Repository\MensagemRepository;
use App\Central\Repository\ParticipanteRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Contract\ConversaDaIaNaCentral;
use App\Repository\UserTenantRepository;

/**
 * A ponta da Central do chat com o BlueJus IA (F3, `docs/specs/ia-chat-central.md`): relê a pergunta e o histórico
 * para o worker e grava a resposta. Roda sem sessão, então toda consulta leva o escritório EXPLÍCITO (quem prova é
 * `IsolamentoDosRepositoriosDaCentralTest`, com o filtro desligado).
 *
 * Devolve null (e nunca lança) quando algo não confere: o fluxo trata como análise inconsistente e o handler não
 * chama o provedor. O texto das mensagens não vai para log nem exceção.
 */
final class ConversaDaIaDaCentral implements ConversaDaIaNaCentral
{
    /** Mensagens anteriores que voltam ao modelo. */
    public const HISTORICO = 12;

    /** Teto de cada mensagem do histórico (corte seco: o fluxo marca com "…"). */
    public const TAMANHO_DA_MENSAGEM_NO_HISTORICO = 400;

    public function __construct(
        private readonly ConversaRepository $conversas,
        private readonly MensagemRepository $mensagens,
        private readonly ParticipanteRepository $participantes,
        private readonly UserTenantRepository $vinculos,
    ) {
    }

    public function contextoDaPergunta(Tenant $tenant, int $conversaId, int $mensagemId, User $solicitante): ?array
    {
        if (!$this->vinculos->existeVinculoAtivo($solicitante, $tenant)) {
            return null;
        }

        $conversa = $this->conversaDoDono($tenant, $conversaId, $solicitante);
        if ($conversa === null) {
            return null;
        }

        $participacao = $this->participantes->deQualquerSituacao($conversa, $solicitante, $tenant);
        if ($participacao === null || $participacao->getSaiuEm() !== null) {
            return null;
        }

        $pergunta = $this->mensagens->daConversa($mensagemId, $conversa, $tenant);
        if ($pergunta === null || $pergunta->isDeIa() || $pergunta->isSistema() || $pergunta->getAutor()->getId() !== $solicitante->getId()) {
            return null;
        }

        $historico = [];
        foreach ($this->mensagens->historico($conversa, $tenant, $mensagemId, self::HISTORICO, $participacao->getLimpaAteId()) as $anterior) {
            if ($anterior->isSistema()) {
                continue;
            }
            $historico[] = [
                'de' => $anterior->isDeIa() ? 'ia' : 'usuario',
                'texto' => self::cortar($anterior->getConteudo(), self::TAMANHO_DA_MENSAGEM_NO_HISTORICO),
            ];
        }

        return [
            'texto' => $pergunta->getConteudo(),
            'nomeDaPessoa' => PrimeiroNome::de($solicitante),
            'historico' => $historico,
        ];
    }

    public function gravarResposta(Tenant $tenant, int $conversaId, User $dono, string $texto, \DateTimeImmutable $agora): void
    {
        $conversa = $this->conversaDoDono($tenant, $conversaId, $dono)
            ?? throw new \LogicException('Conversa com a IA não encontrada para este escritório e usuário.');

        // Revalida o que a leitura conferiu: a pessoa pode ter saído do escritório ou da conversa durante a chamada.
        // (O provedor já cobrou; a análise termina `falhou` e nenhuma fala é gravada.)
        if (!$this->vinculos->existeVinculoAtivo($dono, $tenant)) {
            throw new \LogicException('Vínculo encerrado.');
        }
        if (!$this->participantes->participaAtivamente($conversa, $dono, $tenant)) {
            throw new \LogicException('Participação encerrada.');
        }

        // Menção só entre pessoas: a IA não "chama" ninguém (regra 13 da Central), então o token fica inerte.
        $texto = trim(str_replace('@[', '@ [', $texto));
        $texto = self::cortar($texto, Mensagem::TAMANHO_MAXIMO);

        $this->mensagens->salvar(Mensagem::daIA($tenant, $conversa, $dono, $texto, $agora));
        $conversa->registrarMensagem($agora);
    }

    public function apagarMensagensDaIaAntesDe(\DateTimeImmutable $limite): int
    {
        // Varre todos os escritórios de propósito (rotina do agendador, sem sessão). `resposta_a_id` é SET NULL.
        return (int) $this->mensagens->createQueryBuilder('m')
            ->delete()
            ->andWhere('m.criadaEm < :limite')
            ->andWhere('m.conversa IN (SELECT c.id FROM ' . Conversa::class . ' c WHERE c.tipo = :ia)')
            ->setParameter('limite', $limite)
            ->setParameter('ia', TipoDeConversa::Ia)
            ->getQuery()
            ->execute();
    }

    /** A conversa IA de `$dono` neste escritório, SE for a de id `$conversaId`. */
    private function conversaDoDono(Tenant $tenant, int $conversaId, User $dono): ?Conversa
    {
        $conversa = $this->conversas->daIaDe($tenant, $dono);

        return $conversa !== null && $conversa->getId() === $conversaId ? $conversa : null;
    }

    private static function cortar(string $texto, int $maximo): string
    {
        return mb_strlen($texto) > $maximo ? mb_substr($texto, 0, $maximo - 1) . '…' : $texto;
    }
}
