<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Ponto\Entity\RegistroPonto;

/**
 * As batidas de um dia que valem: uma por tipo, escolhidas por {@see EscolhaDasBatidasDoDia}.
 *
 * É a ÚNICA fonte do que a folha mostra e conta. As células, os links de editar e excluir, o
 * intervalo, os minutos, o saldo e o quadro "suas batidas de hoje" leem estas mesmas quatro batidas
 * (`docs/specs/ponto-folha-uma-batida-por-tipo.md` §9.3). Até 21/09/2026 a folha escolhia de um jeito
 * e a calculadora de outro, e a regra da calculadora nunca chegava à tela.
 *
 * `aConferir` lista o que torna o dia ambíguo. A marca não muda número nenhum: o dia continua com a
 * escolha conservadora, e quem resolve é um humano corrigindo a batida.
 */
final readonly class BatidasEscolhidas
{
    public const REGRA_LEGADA = 'legada';
    public const REGRA_UNICA  = 'unica';

    public const MARCA_ENTRADAS_DISTINTAS      = 'entradas_distintas';
    public const MARCA_REPOUSOS_DISTINTOS      = 'repousos_distintos';
    public const MARCA_RETORNOS_DISTINTOS      = 'retornos_distintos';
    public const MARCA_SAIDAS_DISTINTAS        = 'saidas_distintas';
    public const MARCA_RETORNO_ANTES_DO_REPOUSO = 'retorno_antes_do_repouso';

    /**
     * @param list<RegistroPonto> $desconsideradas as batidas do dia que não foram escolhidas, em ordem de horário
     * @param list<string>        $aConferir       as marcas `MARCA_*`, na ordem em que estão declaradas
     */
    public function __construct(
        public string $regra,
        public ?RegistroPonto $entrada,
        public ?RegistroPonto $repouso,
        public ?RegistroPonto $retorno,
        public ?RegistroPonto $saida,
        public array $desconsideradas,
        public array $aConferir,
    ) {
    }

    public function doTipo(string $tipo): ?RegistroPonto
    {
        return match ($tipo) {
            RegistroPonto::TIPO_ENTRADA => $this->entrada,
            RegistroPonto::TIPO_REPOUSO => $this->repouso,
            RegistroPonto::TIPO_RETORNO => $this->retorno,
            RegistroPonto::TIPO_SAIDA   => $this->saida,
            default                     => null,
        };
    }

    /**
     * As escolhidas, na ordem entrada, repouso, retorno e saída, sem as ausentes.
     *
     * @return list<RegistroPonto>
     */
    public function escolhidas(): array
    {
        return array_values(array_filter(
            [$this->entrada, $this->repouso, $this->retorno, $this->saida],
            static fn (?RegistroPonto $batida): bool => $batida !== null,
        ));
    }

    /**
     * Há intervalo mensurável: repouso e retorno escolhidos, e o repouso antes do retorno.
     *
     * Sem isso o dia cai no span inteiro, a regra aprovada em 31/08/2026 para quem não bateu almoço.
     */
    public function temIntervalo(): bool
    {
        return $this->repouso !== null
            && $this->retorno !== null
            && $this->repouso->getDataHora() < $this->retorno->getDataHora();
    }
}
