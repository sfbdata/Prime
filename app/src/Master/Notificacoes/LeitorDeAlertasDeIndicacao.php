<?php

declare(strict_types=1);

namespace App\Master\Notificacoes;

use App\Entity\Auth\User;
use App\Indicacao\Entity\IndicacaoAlerta;
use App\Indicacao\Repository\IndicacaoAlertaRepository;
use App\Master\DTO\DestinoDaResposta;
use App\Master\DTO\MensagemDaCentral;
use App\Master\DTO\RespostaNaOrigem;

/**
 * Origem `alerta_indicacao`: o "Disparo alto" do Indicar o BlueJus (decisão do dono no #185: o alerta aparece aqui).
 * Vem do "Sistema BlueJus" e não aceita resposta (D7); a janela leva à seção Indicações, onde o Master pausa a pessoa.
 *
 * A gravação (`RegistroDeIndicacao`) guarda "Fulano enviou 35 convites em 24h"; a Central mostra no padrão pedido pelo
 * dono, "Fulano enviou 35 indicações nas últimas 24 horas", sem mudar o que está gravado. Texto fora desse formato
 * aparece como foi gravado.
 */
final class LeitorDeAlertasDeIndicacao implements OrigemDaCentral
{
    public const ORIGEM = 'alerta_indicacao';
    public const ASSUNTO = 'Disparo alto de indicações';

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

    public function respostaNaOrigem(int $idOrigem): ?RespostaNaOrigem
    {
        return null;
    }

    public function registrarResposta(int $idOrigem, string $texto, User $master): void
    {
        throw new \DomainException('Alerta não aceita resposta.');
    }

    public static function textoDoAlerta(string $gravado): string
    {
        if (preg_match('/^(.+) enviou (\d+) convites em 24h$/u', trim($gravado), $m) !== 1) {
            return $gravado;
        }

        return sprintf('%s enviou %d %s nas últimas 24 horas', $m[1], (int) $m[2], (int) $m[2] === 1 ? 'indicação' : 'indicações');
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
            assunto: self::ASSUNTO,
            texto: self::textoDoAlerta($a->getTexto()),
            em: $a->getEm(),
            podeResponder: false,
        );
    }
}
