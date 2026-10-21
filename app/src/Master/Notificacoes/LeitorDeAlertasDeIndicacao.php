<?php

declare(strict_types=1);

namespace App\Master\Notificacoes;

use App\Entity\Auth\User;
use App\Indicacao\Entity\IndicacaoAlerta;
use App\Indicacao\Repository\IndicacaoAlertaRepository;
use App\Master\DTO\DestinoDaResposta;
use App\Master\DTO\MensagemDaCentral;

/**
 * Origem `alerta_indicacao`: o "Disparo alto de convites" do Indicar o BlueJus. Vem do "Sistema BlueJus" e não
 * aceita resposta (D7): o assunto é o texto até os dois-pontos, como no desenho.
 */
final class LeitorDeAlertasDeIndicacao implements OrigemDaCentral
{
    public const ORIGEM = 'alerta_indicacao';

    public function __construct(
        private readonly IndicacaoAlertaRepository $alertas,
    ) {
    }

    public function origem(): string
    {
        return self::ORIGEM;
    }

    public function todas(): array
    {
        return array_map($this->emMensagem(...), $this->alertas->ultimos(self::LIMITE));
    }

    public function uma(int $idOrigem): ?MensagemDaCentral
    {
        $alerta = $this->alertas->find($idOrigem);

        return $alerta instanceof IndicacaoAlerta ? $this->emMensagem($alerta) : null;
    }

    public function destinoDaResposta(int $idOrigem): ?DestinoDaResposta
    {
        return null;
    }

    public function registrarResposta(int $idOrigem, string $texto, User $master): void
    {
        throw new \DomainException('Alerta não aceita resposta.');
    }

    private function emMensagem(IndicacaoAlerta $a): MensagemDaCentral
    {
        return new MensagemDaCentral(
            origem: self::ORIGEM,
            idOrigem: (int) $a->getId(),
            tipo: 'alerta',
            rotuloDoTipo: 'Alerta',
            deNome: 'Sistema BlueJus',
            deEmail: null,
            escritorio: '',
            tenantId: null,
            assunto: trim(explode(':', $a->getTexto())[0]),
            texto: $a->getTexto(),
            em: $a->getEm(),
            podeResponder: false,
        );
    }
}
