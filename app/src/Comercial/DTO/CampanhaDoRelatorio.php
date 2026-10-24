<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

/**
 * O visualizador de uma campanha (desenho 05 VS 21.10.26, L502-514): o assunto com o estado, o remetente, o público,
 * o modelo e a data, os 4 KPIs (Destinatários, Enviados, Lidos, Taxa de leitura), o HTML remontado do conteúdo
 * congelado (null se a cópia não vale mais) e os destinatários com o estado (até {@see self::LIMITE}; `mais` conta os
 * que ficaram de fora). `cancelavel` diz se o "Cancelar envio" cabe (o que o EncerrarCampanhaUseCase aceita).
 */
final class CampanhaDoRelatorio
{
    public const LIMITE = 200;

    /** @param list<DestinatarioDoRelatorio> $destinatarios */
    public function __construct(
        public readonly int $id,
        public readonly string $assunto,
        public readonly string $titulo,
        public readonly string $estado,
        public readonly string $situacao,
        public readonly string $de,
        public readonly string $publico,
        public readonly string $modelo,
        public readonly string $quando,
        public readonly bool $cancelavel,
        public readonly bool $iniciada,
        public readonly MetricasDaCampanha $metricas,
        public readonly ?string $html,
        public readonly array $destinatarios,
        public readonly int $mais,
    ) {
    }

    /** @return list<array{rotulo: string, valor: string, icone: string}> os 4 KPIs, na ordem e com os ícones do dc (L509) */
    public function kpis(): array
    {
        return [
            ['rotulo' => 'Destinatários', 'valor' => (string) $this->metricas->destinatarios, 'icone' => 'bi-people'],
            ['rotulo' => 'Enviados', 'valor' => (string) $this->metricas->enviados, 'icone' => 'bi-check2'],
            ['rotulo' => 'Lidos', 'valor' => $this->metricas->lidosNoKpi(), 'icone' => 'bi-check2-all'],
            ['rotulo' => 'Taxa de leitura', 'valor' => $this->metricas->taxaDeLeitura(), 'icone' => 'bi-graph-up'],
        ];
    }

    /** @return array<string, mixed> */
    public function paraArray(): array
    {
        return [
            'id' => $this->id, 'assunto' => $this->assunto, 'titulo' => $this->titulo, 'estado' => $this->estado,
            'situacao' => $this->situacao, 'de' => $this->de, 'publico' => $this->publico, 'modelo' => $this->modelo,
            'quando' => $this->quando, 'cancelavel' => $this->cancelavel, 'iniciada' => $this->iniciada, 'kpis' => $this->kpis(),
            'metricas' => $this->metricas->paraArray(), 'html' => $this->html, 'mais' => $this->mais,
            'destinatarios' => array_map(static fn (DestinatarioDoRelatorio $d): array => $d->paraArray(), $this->destinatarios),
        ];
    }
}
