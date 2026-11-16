<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/**
 * Uma votação (pauta de assembleia publicada) como a aba "Votar" de /voto a mostra à equipe: a pauta, a assembleia
 * dela (para o chip e o menu de ciclo) e o endereço público do evento (o mesmo do link e do QR Code, nunca o token
 * de uma unidade).
 */
final readonly class VotacaoParaVotar
{
    public function __construct(
        public AssembleiaNoPainel $assembleia,
        public PautaNoPainel $pauta,
        public DivulgacaoDaAssembleiaOutput $divulgacao,
    ) {
    }

    /** O botão "Votar" só vale com a votação aberta (desenho: `disabled = !aberta`). */
    public function podeVotar(): bool
    {
        return $this->pauta->aberta();
    }

    /**
     * Chave da frase de situação (voto.votar.aviso.*), na ordem do desenho: suspensa, cancelada, encerrada, aberta e,
     * por fim, aguardando a mesa.
     */
    public function aviso(): string
    {
        return match (true) {
            $this->pauta->aberta() && $this->pauta->suspensa() => 'suspensa',
            $this->pauta->cancelada() => 'cancelada',
            $this->pauta->encerrada() => 'encerrada',
            $this->pauta->aberta() => 'aberta',
            default => 'aguardando',
        };
    }

    /**
     * Cor e ícone da frase: âmbar para suspensa e cancelada, azul nas demais (cores do desenho).
     *
     * @return array{cor: string, icone: string}
     */
    public function estiloDoAviso(): array
    {
        return match ($this->aviso()) {
            'suspensa' => ['cor' => '#8a6200', 'icone' => 'bi-pause-circle'],
            'cancelada' => ['cor' => '#8a6200', 'icone' => 'bi-x-circle'],
            default => ['cor' => '#0a6fa0', 'icone' => 'bi-info-circle'],
        };
    }

    /** Fim do período: encerramento, senão o prazo da mesa; null sem nenhum dos dois. */
    public function fim(): ?\DateTimeImmutable
    {
        return $this->pauta->encerradaEm ?? $this->pauta->prazoAte;
    }

    /** Chave da modalidade (desenho: `MODR`): pauta, consulta, individual ou chapa, conforme o cenário e o tipo da pauta. */
    public function modalidade(): string
    {
        return $this->assembleia->cenario->modalidade($this->pauta->tipo);
    }
}
