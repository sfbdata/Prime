<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\BancoDeHorasOutput;
use App\Ponto\DTO\LoteDoBancoDeHorasOutput;
use App\Ponto\Repository\FeriadoRepository;

/**
 * O banco de horas de uma pessoa num escritório, por lotes (spec ponto-desenho-0811, P51 a, P52, P53 e P73; desenho 16
 * L774 a L776 e L817). Serve o topo do cartão "Registrar Ponto" e a aba "Banco de Horas" da própria pessoa, e a visão
 * da equipe (cada pessoa do escritório) depois.
 *
 * Cada competência é um lote: o saldo do mês positivo é crédito, o negativo é débito. A soma de todos os débitos abate
 * os créditos do mais antigo para o mais novo, como no desenho. O crédito vence no último dia do mês "competência mais
 * prazo"; vencido, continua no saldo (o desenho soma tudo). Não zera em 1º de janeiro, e as horas pagas continuam no
 * saldo, na própria competência. Os valores de cada mês vêm de `FolhaPontoBuilder::saldosDoBancoPorCompetencia`, a
 * mesma conta do "saldo anterior" das exportações.
 *
 * Sem lote "Anterior a" (o "Sem data de origem" do desenho): os lotes nascem no mês do início da contagem, então não
 * há saldo antes do primeiro lote.
 */
final class BancoDeHorasPorLotes
{
    private const COR_NEUTRA = '#5b7383';
    private const COR_VENCIDO = '#a3232b';
    private const COR_VENCENDO = '#8a6200';
    private const COR_NO_PRAZO = '#1e7b45';

    /** "Vence em N dias" até este número de dias (desenho L776). */
    private const DIAS_DO_AVISO = 30;

    public function __construct(
        private readonly FolhaPontoBuilder $folha,
        private readonly InicioContagemResolver $inicioContagem,
        private readonly FeriadoRepository $feriados,
        private readonly LimitesDoBancoDeHoras $limites,
    ) {
    }

    /** @param \DateTimeInterface $hoje o dia de referência dos vencimentos (só a data conta) */
    public function calcular(User $user, Tenant $tenant, \DateTimeInterface $hoje): BancoDeHorasOutput
    {
        $saldos = $this->folha->saldosDoBancoPorCompetencia(
            $user,
            $tenant,
            $this->feriados->findByTenant($tenant),
            $tenant->getJornadaTenant(),
            $this->inicioContagem->resolver($user, $tenant),
        );
        $saldoAtual = array_sum($saldos);
        $limite = $this->limites->limiteEmMinutos($tenant);
        $prazo = $this->limites->prazoEmMeses($tenant);
        $dia = \DateTimeImmutable::createFromInterface($hoje)->setTime(0, 0);

        return new BancoDeHorasOutput($saldoAtual, $limite, $prazo, $saldoAtual > $limite, $this->lotes($saldos, $prazo, $dia));
    }

    /**
     * Um lote por competência com crédito ou débito, do mais antigo para o mais novo.
     *
     * @param array<string, int> $saldos 'AAAA-MM' => minutos, em ordem
     * @return list<LoteDoBancoDeHorasOutput>
     */
    private function lotes(array $saldos, int $prazo, \DateTimeImmutable $hoje): array
    {
        $debito = array_sum(array_map(static fn (int $valor): int => max(0, -$valor), $saldos));
        $lotes = [];
        foreach ($saldos as $competencia => $valor) {
            if ($valor === 0) {
                continue;
            }
            $credito = max(0, $valor);
            $usado = min($debito, $credito);
            $debito -= $usado;
            $lotes[] = $credito === 0
                ? new LoteDoBancoDeHorasOutput($this->rotulo($competencia), 0, -$valor, 0, null, 'Compensação', self::COR_NEUTRA)
                : $this->loteDeCredito($competencia, $credito, $usado, $prazo, $hoje);
        }

        return $lotes;
    }

    private function loteDeCredito(string $competencia, int $credito, int $usado, int $prazo, \DateTimeImmutable $hoje): LoteDoBancoDeHorasOutput
    {
        [$ano, $mes] = array_map('intval', explode('-', $competencia));
        $venceEm = $hoje->setDate($ano, $mes, 1)->modify(sprintf('+%d months', $prazo))->modify('last day of this month');
        $saldo = $credito - $usado;
        [$situacao, $cor] = $this->situacao($saldo, (int) $hoje->diff($venceEm)->format('%r%a'));

        return new LoteDoBancoDeHorasOutput($this->rotulo($competencia), $credito, $usado, $saldo, $venceEm, $situacao, $cor);
    }

    /** @return array{string, string} a situação do lote com crédito e a cor do selo (desenho L776) */
    private function situacao(int $saldo, int $diasAteVencer): array
    {
        return match (true) {
            $saldo === 0 => ['Compensado', self::COR_NEUTRA],
            $diasAteVencer < 0 => ['Vencido', self::COR_VENCIDO],
            $diasAteVencer <= self::DIAS_DO_AVISO => [sprintf('Vence em %d dias', $diasAteVencer), self::COR_VENCENDO],
            default => ['No prazo', self::COR_NO_PRAZO],
        };
    }

    /** 'AAAA-MM' vira "MM/AAAA", como a coluna Competência do desenho. */
    private function rotulo(string $competencia): string
    {
        [$ano, $mes] = explode('-', $competencia);

        return $mes . '/' . $ano;
    }
}
