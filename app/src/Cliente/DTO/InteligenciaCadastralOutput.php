<?php

declare(strict_types=1);

namespace App\Cliente\DTO;

use App\Cliente\Service\RotulosDaInteligenciaCadastral;

/**
 * Retrato do bloco "Inteligência cadastral" (contrato da spec, §3): `on` falso quando o cadastro não tem CPF/CNPJ
 * válido (o bloco não aparece).
 */
final class InteligenciaCadastralOutput
{
    /**
     * @param list<AlertaDaInteligenciaCadastral> $alertas
     * @param list<DocumentoEncontradoNaPasta>    $encontrados
     */
    public function __construct(
        public readonly bool $on,
        public readonly string $subtitulo = '',
        public readonly array $alertas = [],
        public readonly array $encontrados = [],
        public readonly string $nota = '',
    ) {
    }

    public static function desligado(): self
    {
        return new self(false);
    }

    /**
     * @param \Closure(int): string $urlDoCliente monta o link "abrir" do outro cadastro
     *
     * @return array<string, mixed>
     */
    public function paraResposta(\Closure $urlDoCliente): array
    {
        return [
            'on'          => $this->on,
            'titulo'      => RotulosDaInteligenciaCadastral::TITULO,
            'subtitulo'   => $this->subtitulo,
            'alertas'     => array_map(static fn (AlertaDaInteligenciaCadastral $a): array => array_filter([
                'tipo'     => $a->tipo,
                'ic'       => $a->ic,
                'negrito'  => $a->negrito,
                'texto'    => $a->texto,
                'abrirUrl' => $a->abrirClienteId !== null ? $urlDoCliente($a->abrirClienteId) : null,
            ], static fn (?string $v): bool => $v !== null), $this->alertas),
            'encontrados' => array_map(static fn (DocumentoEncontradoNaPasta $e): array => $e->paraResposta(), $this->encontrados),
            'nota'        => $this->nota,
        ];
    }
}
