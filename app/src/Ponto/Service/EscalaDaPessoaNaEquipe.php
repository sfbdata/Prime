<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\OpcaoDeEscalaOutput;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\JornadaTenant;
use App\Ponto\Repository\JornadaColaboradorRepository;

/**
 * As opções da coluna "Escala" da aba Equipe para uma pessoa (spec `docs/specs/ponto-desenho-0811.md`, P56 a: a coluna
 * mostra a jornada atual). Vale a jornada de hoje no escritório: a pessoal com blocos ou, sem ela, a do escritório.
 * Os minutos de cada dia saem da mesma cascata da folha (`JornadaResolver::resolverMetaDia`); a escala marcada é a do
 * catálogo com os mesmos minutos em cada dia. Nenhuma bate: entra antes uma opção que descreve a atual.
 */
final class EscalaDaPessoaNaEquipe
{
    /** Uma segunda-feira qualquer: o dia N da semana é esta data mais N - 1 dias (molde de IntervalosDeTrabalho). */
    private const UMA_SEGUNDA = '2024-01-01';

    public function __construct(
        private readonly JornadaColaboradorRepository $jornadas,
        private readonly JornadaResolver $resolver,
    ) {
    }

    /** @return list<OpcaoDeEscalaOutput> */
    public function opcoes(User $pessoa, Tenant $escritorio): array
    {
        $pessoal = $this->jornadas->findByUserETenant($pessoa, $escritorio);
        $padrao = $escritorio->getJornadaTenant();
        $minutos = $this->minutosDaSemana($pessoal, $padrao);
        $marcada = CatalogoDeEscalas::correspondente($minutos);

        $opcoes = $marcada === null ? [new OpcaoDeEscalaOutput('', $this->rotuloDaAtual($pessoal, $padrao, $minutos), true)] : [];
        foreach (CatalogoDeEscalas::todas() as $escala) {
            $opcoes[] = new OpcaoDeEscalaOutput($escala->id, $escala->rotulo, $escala->id === $marcada?->id);
        }

        return $opcoes;
    }

    /** @return array<int, int> */
    private function minutosDaSemana(?JornadaColaborador $pessoal, ?JornadaTenant $padrao): array
    {
        $minutos = [];
        for ($dia = 1; $dia <= 7; ++$dia) {
            $data = (new \DateTimeImmutable(self::UMA_SEGUNDA))->modify('+' . ($dia - 1) . ' days');
            $minutos[$dia] = $this->resolver->resolverMetaDia($pessoal, $data, $padrao);
        }

        return $minutos;
    }

    /**
     * "Personalizada (44h)" ou "Jornada do escritório (40h)": a carga semanal da própria jornada; sem ela, a soma dos dias.
     *
     * @param array<int, int> $minutos
     */
    private function rotuloDaAtual(?JornadaColaborador $pessoal, ?JornadaTenant $padrao, array $minutos): string
    {
        if ($pessoal !== null && !$pessoal->getBlocos()->isEmpty()) {
            return sprintf('Personalizada (%s)', CatalogoDeEscalas::horas($pessoal->getCargaHorariaSemanal() ?? array_sum($minutos)));
        }

        return sprintf('Jornada do escritório (%s)', CatalogoDeEscalas::horas($padrao?->getCargaHorariaSemanal() ?? array_sum($minutos)));
    }
}
