<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\DTO\RetratoDoPainel;
use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Repository\CandidaturaRepository;
use App\TrabalhoExterno\Repository\InteressadoEmVagasRepository;
use App\TrabalhoExterno\Repository\MetricaPublicaRepository;
use App\TrabalhoExterno\Repository\TermoDeVoluntariadoRepository;
use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Service\ApresentadorDeTermo;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\EngajamentoDoTrabalhoExterno;
use App\TrabalhoExterno\Service\LocalizadorDeCurriculo;
use App\TrabalhoExterno\Service\LocalizadorDeVaga;
use App\TrabalhoExterno\Service\PainelDoBancoDeTalentos;
use App\TrabalhoExterno\Service\PoliticaDoCurriculo;

/**
 * `GET /trabalho-externo/api/curriculos/painel` (spec VS 18.10.26 §4.9): o Painel do banco de talentos.
 *
 * Quem: só o Master de verdade. A Equipe vê "Estatísticas disponíveis só para o Master" e só a Lista
 * (desenho 06 l.171); a prévia (`?como=`) também é negada, porque o painel não existe para nenhum dos
 * perfis que ela simula. Lê o banco inteiro, as vagas da Central, o status e o envio das candidaturas,
 * os inscritos nos alertas e os contadores anônimos da página pública. Consulta: não grava nada.
 */
final class MontarPainelDeCurriculosUseCase
{
    public function __construct(
        private readonly PoliticaDoCurriculo $politica,
        private readonly LocalizadorDeCurriculo $curriculos,
        private readonly LocalizadorDeVaga $vagas,
        private readonly CandidaturaRepository $candidaturas,
        private readonly InteressadoEmVagasRepository $interessados,
        private readonly MetricaPublicaRepository $metricas,
        private readonly PainelDoBancoDeTalentos $painel,
        private readonly TermoDeVoluntariadoRepository $termos,
    ) {
    }

    /** @return array<string, mixed> */
    public function executar(Contexto $ctx): array
    {
        $this->exigirMaster($ctx);
        $agora = new \DateTimeImmutable();
        $vagas = $this->vagas->visiveis($ctx, $agora);
        $publicas = $this->metricas->totais();
        $curriculos = $this->curriculos->visiveis($ctx);

        return $this->painel->montar(new RetratoDoPainel(
            agora: $agora,
            curriculos: $curriculos,
            vagas: $vagas,
            candidaturas: $this->candidaturas->statusEEnvios(),
            aceitesPorVaga: $this->aceitesDasOfertas($vagas),
            minutosAteOAceite: EngajamentoDoTrabalhoExterno::minutosAteOAceite($this->candidaturas->primeirosAceitesDasRodadas()),
            inscritos: $this->interessados->contar(),
            visitas: $publicas['visitas'],
            cliques: $publicas['cliques'],
            termos: !$ctx->configuracao->termoLigado() ? null : array_map(static fn ($t): string => ApresentadorDeTermo::estado($t), $this->termos->maisRecentesDe(array_map(static fn (Curriculo $c): int => (int) $c->getId(), $curriculos))),
        ));
    }

    private function exigirMaster(Contexto $ctx): void
    {
        if (!$this->politica->ehMaster($ctx) || !$this->politica->veAba($ctx)) {
            throw new AcaoNaoPermitida('Estatísticas disponíveis só para o Master.');
        }
    }

    /**
     * @param list<Vaga> $vagas
     *
     * @return array<int, int> id da oferta → aceites da rodada atual
     */
    private function aceitesDasOfertas(array $vagas): array
    {
        $ofertas = array_values(array_map(static fn (Vaga $v): int => (int) $v->getId(), array_filter($vagas, static fn (Vaga $v): bool => $v->isOferta())));

        return array_map(static fn (array $resumo): int => $resumo['aceites'], $this->candidaturas->resumoPorVaga($ofertas));
    }
}
