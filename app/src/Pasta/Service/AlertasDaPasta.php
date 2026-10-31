<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\AlertaDaPasta;
use App\Pasta\DTO\PastaMetasResumoOutput;
use App\Pasta\DTO\PastaParada;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaPrazoRepository;

/**
 * Alertas da Controladoria da pasta (spec docs/specs/central-documentos-controladoria.md, fatia A). Somente leitura:
 * junta o que o sistema já sabe da pasta, sem gravar nada.
 *
 * Regras ligadas: metas vencidas, prazos vencidos e pasta parada (reusa o {@see AnalisadorDePastasParadas}).
 * Fica para a fatia B, quando o dado existir: mesmo contrato/unidade, titular do extrato diferente do réu e prescrição
 * (os limites já ficam aqui, num lugar só, para o dono mudar sem caçar).
 */
final class AlertasDaPasta
{
    /** Pasta parada: acima disto o alerta é "média"; acima de {@see AnalisadorDePastasParadas::DIAS_CRITICO} é "alta". */
    public const PARADA_MEDIA_ACIMA_DE_DIAS = AnalisadorDePastasParadas::DIAS_ATENCAO;
    /** Prescrição (fatia B): avisa a partir de tantos dias; "alta" com tantos ou menos, ou vencida. */
    public const PRESCRICAO_AVISA_ATE_DIAS = 90;
    public const PRESCRICAO_ALTA_ATE_DIAS = 30;

    private const SUGESTAO_VENCIDO = 'Registre a providência ou justifique.';
    private const ORDEM = [AlertaDaPasta::ALTA => 0, AlertaDaPasta::MEDIA => 1, AlertaDaPasta::BAIXA => 2];

    public function __construct(
        private readonly PastaPrazoRepository $prazos,
        private readonly ColetorDePastasParadas $coletorDeParadas,
        private readonly AnalisadorDePastasParadas $analisadorDeParadas,
    ) {
    }

    /** @return list<AlertaDaPasta> da mais grave para a menos */
    public function montar(Pasta $pasta, Tenant $tenant, \DateTimeImmutable $hoje): array
    {
        $alertas = array_values(array_filter([
            $this->metasVencidas($pasta, $hoje),
            $this->prazosVencidos($pasta, $tenant, $hoje),
            $this->pastaParada($pasta, $tenant, $hoje),
        ]));
        usort($alertas, static fn (AlertaDaPasta $a, AlertaDaPasta $b): int => self::ORDEM[$a->nivel] <=> self::ORDEM[$b->nivel]);

        return $alertas;
    }

    private function metasVencidas(Pasta $pasta, \DateTimeImmutable $hoje): ?AlertaDaPasta
    {
        $atencao = PastaMetasResumoOutput::montar($pasta, $hoje)->atencao;
        if ($atencao === []) {
            return null;
        }
        $itens = array_map(static fn (array $m): string => $m['titulo'] . ' em ' . $m['prazo'], array_slice($atencao, 0, 2));

        return new AlertaDaPasta(AlertaDaPasta::ALTA, count($atencao) . ' meta(s) vencida(s)', implode('; ', $itens) . '.', self::SUGESTAO_VENCIDO, 'metas');
    }

    private function prazosVencidos(Pasta $pasta, Tenant $tenant, \DateTimeImmutable $hoje): ?AlertaDaPasta
    {
        $dia = $hoje->setTime(0, 0);
        $vencidos = array_values(array_filter(
            $this->prazos->abertosDaPasta($pasta, $tenant),
            static fn ($p): bool => $p->getData()->setTime(0, 0) < $dia,
        ));
        if ($vencidos === []) {
            return null;
        }
        $itens = array_map(static fn ($p): string => $p->getTitulo() . ' em ' . $p->getData()->format('d/m/Y'), array_slice($vencidos, 0, 2));

        return new AlertaDaPasta(AlertaDaPasta::ALTA, count($vencidos) . ' prazo(s) vencido(s)', implode('; ', $itens) . '.', self::SUGESTAO_VENCIDO, 'prazos');
    }

    private function pastaParada(Pasta $pasta, Tenant $tenant, \DateTimeImmutable $hoje): ?AlertaDaPasta
    {
        $coletadas = $this->coletorDeParadas->coletar($tenant, [(int) $pasta->getId()], $hoje);
        $parada = $this->analisadorDeParadas->analisar($coletadas, $hoje)[0] ?? null;
        if (!$parada instanceof PastaParada) {
            return null;
        }
        $nivel = $parada->critica() ? AlertaDaPasta::ALTA : ($parada->dias > self::PARADA_MEDIA_ACIMA_DE_DIAS ? AlertaDaPasta::MEDIA : AlertaDaPasta::BAIXA);
        $detalhe = $parada->ultima !== null ? 'Última movimentação em ' . $parada->ultima->format('d/m/Y') . '.' : 'Sem movimentação desde a abertura.';

        return new AlertaDaPasta($nivel, 'Pasta parada há ' . $parada->dias . ' dias', $detalhe, $parada->sugestoes[0] ?? 'Registre a providência.', 'parada');
    }
}
