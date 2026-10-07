<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Pasta\Service\RegrasDaTimelineInteligente;

/**
 * O que o endpoint da Timeline inteligente devolve: os eventos já filtrados e cortados no limite,
 * mais o que a tela mostra sobre a lista INTEIRA (contagem dos chips, pessoas, "Precisa de atenção" e
 * resumo.
 *
 * `truncado`: alguma fonte atingiu o teto da consulta, ou a lista filtrada passou do limite — a tela
 * avisa e oferece carregar mais, em vez de fingir que mostrou tudo.
 */
final readonly class TimelineInteligenteOutput
{
    /**
     * @param list<EventoDaTimelineOutput> $eventos
     * @param array<string, int>           $contagens
     * @param list<string>                 $pessoas
     * @param list<EventoDaTimelineOutput> $atencao
     */
    public function __construct(
        public array $eventos,
        public int $totalFiltrado,
        public bool $truncado,
        public int $limite,
        public array $contagens,
        public array $pessoas,
        public array $atencao,
        public string $resumo,
    ) {
    }

    /** @return array<string, mixed> */
    public function paraArray(): array
    {
        $categorias = [];
        foreach (RegrasDaTimelineInteligente::CATEGORIAS as $chave => [$rotulo, $icone, $cor]) {
            $categorias[$chave] = ['rotulo' => $rotulo, 'icone' => $icone, 'cor' => $cor];
        }
        $prioridades = [];
        foreach (RegrasDaTimelineInteligente::PRIORIDADES as $chave => [$rotulo, $cor]) {
            $prioridades[$chave] = ['rotulo' => $rotulo, 'cor' => $cor];
        }

        return [
            'eventos'       => array_map(static fn (EventoDaTimelineOutput $e) => $e->paraArray(), $this->eventos),
            'totalFiltrado' => $this->totalFiltrado,
            'truncado'      => $this->truncado,
            'limite'        => $this->limite,
            'contagens'     => $this->contagens,
            'pessoas'       => $this->pessoas,
            'atencao'       => array_map(static fn (EventoDaTimelineOutput $e) => $e->paraArray(), $this->atencao),
            'resumo'        => $this->resumo,
            'categorias'    => $categorias,
            'prioridades'   => $prioridades,
        ];
    }
}
