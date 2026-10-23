<?php

declare(strict_types=1);

namespace App\Master\Notificacoes;

use App\Entity\Auth\User;
use App\Master\DTO\DestinoDaResposta;
use App\Master\DTO\MensagemDaCentral;
use App\Master\DTO\RespostaNaOrigem;
use App\Shared\Scheduler\Entity\FalhaAgendada;
use App\Shared\Scheduler\Repository\FalhaAgendadaRepository;

/**
 * Origem `falha_agendada`: as falhas das tarefas agendadas (spec scheduler-tarefas-agendadas, "Aviso de
 * falha"). Vem do "Sistema BlueJus", sem escritório, e não aceita resposta. Lista as recentes, dispensadas
 * ou não: dispensar tira o aviso da moldura, não a mensagem da Central. Só o comando e a hora; o detalhe
 * fica no log do agendador.
 */
final class LeitorDeFalhasAgendadas implements OrigemDaCentral
{
    public const ORIGEM = 'falha_agendada';

    public function __construct(
        private readonly FalhaAgendadaRepository $falhas,
    ) {
    }

    public function origem(): string
    {
        return self::ORIGEM;
    }

    public function todas(): array
    {
        return array_map($this->emMensagem(...), $this->falhas->recentes(self::LIMITE));
    }

    public function uma(int $idOrigem): ?MensagemDaCentral
    {
        $falha = $this->falhas->uma($idOrigem);

        return $falha === null ? null : $this->emMensagem($falha);
    }

    public function destinoDaResposta(int $idOrigem): ?DestinoDaResposta
    {
        return null;
    }

    public function respostaNaOrigem(int $idOrigem): ?RespostaNaOrigem
    {
        return null;
    }

    public function registrarResposta(int $idOrigem, string $texto, User $master): void
    {
        throw new \DomainException('Falha de tarefa agendada não aceita resposta.');
    }

    private function emMensagem(FalhaAgendada $falha): MensagemDaCentral
    {
        $em = $falha->getOcorridaEm();

        return new MensagemDaCentral(
            origem: self::ORIGEM,
            idOrigem: (int) $falha->getId(),
            tipo: 'alerta',
            rotuloDoTipo: 'Alerta',
            deNome: 'Sistema BlueJus',
            deEmail: null,
            escritorio: '',
            tenantId: null,
            assunto: 'Tarefa agendada falhou',
            texto: sprintf(
                'Tarefa agendada falhou: %s às %s de %s. Detalhe no log do agendador.',
                $falha->getComando(),
                $em->format('H:i'),
                $em->format('d/m'),
            ),
            em: $em,
            podeResponder: false,
        );
    }
}
