<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\Candidatura;
use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Enum\StatusCandidatura;
use App\TrabalhoExterno\Repository\CandidaturaRepository;
use App\TrabalhoExterno\Repository\CurriculoRepository;

/**
 * Junta, para as vagas que quem lê já alcançou, o que elas mostram das candidaturas: na lista, uma
 * consulta de números para todas e as candidaturas do currículo de quem lê; no detalhe, também os
 * candidatos e os aceites da rodada. O recorte (o dono vê só os enviados, o externo não vê os outros)
 * fica no {@see ApresentadorDeVaga}.
 */
final class ResumoDasCandidaturas
{
    public function __construct(
        private readonly CandidaturaRepository $candidaturas,
        private readonly CurriculoRepository $curriculos,
        private readonly CompatibilidadeDaVaga $compatibilidade,
    ) {
    }

    /**
     * @param list<Vaga> $vagas
     *
     * @return array<int, CandidaturasDaVaga> id da vaga → resumo
     */
    public function paraLista(Contexto $ctx, array $vagas): array
    {
        $numeros = $this->candidaturas->resumoPorVaga(array_map(static fn (Vaga $v): int => (int) $v->getId(), $vagas));
        $meu = $this->meuCurriculo($ctx);
        $minhas = [];
        foreach ($meu !== null ? $this->candidaturas->doCurriculo($meu) : [] as $c) {
            $minhas[(int) $c->getVaga()->getId()] = $c;
        }
        $resumos = [];
        foreach ($vagas as $v) {
            $id = (int) $v->getId();
            $n = $numeros[$id] ?? ['total' => 0, 'recebidas' => 0, 'enviados' => 0, 'aceites' => 0];
            $resumos[$id] = new CandidaturasDaVaga($n['total'], $n['recebidas'], $n['enviados'], $n['aceites'], $minhas[$id] ?? null, $this->compatibilidade->calcular($v, $meu));
        }

        return $resumos;
    }

    public function paraDetalhe(Contexto $ctx, Vaga $v): CandidaturasDaVaga
    {
        $meu = $this->meuCurriculo($ctx);
        $todas = $this->candidaturas->daVaga($v);
        $minha = $meu !== null ? array_values(array_filter($todas, static fn (Candidatura $c): bool => $c->getCurriculo()->getId() === $meu->getId()))[0] ?? null : null;
        $aceites = $this->candidaturas->aceitesDaRodada($v);

        return new CandidaturasDaVaga(
            count($todas),
            count(array_filter($todas, static fn (Candidatura $c): bool => $c->getStatus() === StatusCandidatura::Recebida)),
            count(array_filter($todas, static fn (Candidatura $c): bool => $c->isEnviadoAoEscritorio())),
            count($aceites),
            $minha,
            $this->compatibilidade->calcular($v, $meu),
            $todas,
            array_map(static fn (Candidatura $c): string => $c->getCurriculo()->texto('nome'), $aceites),
        );
    }

    /** O currículo de quem lê: só o externo tem (o interno não tem "Meu currículo"). */
    private function meuCurriculo(Contexto $ctx): ?Curriculo
    {
        return $ctx->ehInterno() ? null : $this->curriculos->doUsuario($ctx->usuario);
    }
}
