<?php

declare(strict_types=1);

namespace App\Tarefa\Enum;

/**
 * Tipo de demanda da meta (desenho 04 de 22/10, dc L150 `TIPOS`): decide por quais caixas da esteira a pasta
 * passa. Judicial e Administrativo passam pelas 8; Consultoria pula Documentação e Acompanhamento.
 */
enum TipoDeDemanda: string
{
    case Judicial       = 'judicial';
    case Administrativo = 'administrativo';
    case Consultoria    = 'consultoria';

    /** @return list<EtapaDaPasta> */
    public function sequencia(): array
    {
        if ($this === self::Consultoria) {
            return [
                EtapaDaPasta::Comercial,
                EtapaDaPasta::Abertura,
                EtapaDaPasta::Controladoria,
                EtapaDaPasta::Juridico,
                EtapaDaPasta::Protocolo,
                EtapaDaPasta::Encerramento,
            ];
        }

        return EtapaDaPasta::cases();
    }

    /** Posição da etapa na sequência deste tipo, a partir de 1; `null` se o tipo não passa por ela. */
    public function posicaoDe(EtapaDaPasta $etapa): ?int
    {
        $indice = array_search($etapa, $this->sequencia(), true);

        return $indice === false ? null : $indice + 1;
    }

    /** A etapa seguinte na sequência; `null` na última ou se o tipo não passa pela etapa. */
    public function proximaDepoisDe(EtapaDaPasta $etapa): ?EtapaDaPasta
    {
        $posicao = $this->posicaoDe($etapa);

        return $posicao === null ? null : ($this->sequencia()[$posicao] ?? null);
    }

    public function rotulo(): string
    {
        return 'metas.tipo_de_demanda.' . $this->value;
    }
}
