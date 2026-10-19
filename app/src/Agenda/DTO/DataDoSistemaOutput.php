<?php

declare(strict_types=1);

namespace App\Agenda\DTO;

/**
 * Uma data que a Agenda mostra sem ninguém cadastrar: feriado, ponto facultativo, data dos
 * tribunais, recesso forense ou data comemorativa. É calculada em memória (CalendarioDoSistema)
 * ou lida da tabela `feriado` do Ponto, nunca gravada pela Agenda.
 */
final readonly class DataDoSistemaOutput
{
    public const CAL_NACIONAL    = 'fer';
    public const CAL_ESTADUAL    = 'est';
    public const CAL_FORENSE     = 'forense';
    public const CAL_COMEMORATIVA = 'com';
    public const CAL_TRIBUNAL    = 'trib';

    public const TIPO_FERIADO           = 'feriado';
    public const TIPO_PONTO_FACULTATIVO = 'ponto_facultativo';
    public const TIPO_ESTADUAL          = 'estadual';
    public const TIPO_FORENSE           = 'forense';
    public const TIPO_COMEMORATIVA      = 'comemorativa';
    public const TIPO_TRIBUNAL          = 'tribunal';
    public const TIPO_ELEICAO           = 'eleicao';
    public const TIPO_ESCRITORIO        = 'escritorio';

    public function __construct(
        public string $id,
        public string $data,
        public string $titulo,
        public string $calendario,
        public string $tipo,
        public ?string $fonte = null,
        public ?string $observacao = null,
        public ?string $uf = null,
        public bool $contaNoPonto = false,
    ) {
    }

    public function comContaNoPonto(): self
    {
        return new self(
            $this->id,
            $this->data,
            $this->titulo,
            $this->calendario,
            $this->tipo,
            $this->fonte,
            $this->observacao,
            $this->uf,
            true,
        );
    }

    /** @return array<string, string|bool|null> */
    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'data'         => $this->data,
            'titulo'       => $this->titulo,
            'calendario'   => $this->calendario,
            'tipo'         => $this->tipo,
            'fonte'        => $this->fonte,
            'observacao'   => $this->observacao,
            'uf'           => $this->uf,
            'contaNoPonto' => $this->contaNoPonto,
        ];
    }
}
