<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\Feriado;
use App\Repository\UserTenantRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Os feriados que valem para uma pessoa (spec `docs/specs/ponto-desenho-0811.md`, E6, P71 com o desvio da E6): a
 * regra da abrangência mora só aqui.
 *
 * - feriado sem lotação ("Todas as sedes"): vale para todos, como sempre;
 * - feriado com lotação: vale só para quem tem AQUELA lotação no escritório do feriado (o vínculo da pessoa com o
 *   escritório, ativo ou não: a folha de quem saiu continua contando o feriado que valia para ela).
 *
 * Quem aplica: o `FolhaPontoBuilder` (folha na tela, ficha do colaborador, PDF, XLSX, saldo do ano, saldo até o mês,
 * horas pagas, "Regularizar ponto de ontem"), e a lista de pendências de registro, que lê o feriado sem a folha.
 *
 * Sem N+1: sem feriado por lotação (o caso de hoje), nenhuma consulta; com ele, uma por pessoa e escritório, guardada
 * até o fim do request ou da mensagem do worker (`kernel.reset`). O saldo do ano monta a folha mês a mês e não volta
 * ao banco a cada mês.
 */
final class FeriadosDaPessoa implements ResetInterface
{
    /** @var array<string, ?int> "pessoa:escritório" => id da lotação da pessoa naquele escritório */
    private array $lotacoes = [];

    public function __construct(
        private readonly UserTenantRepository $vinculos,
    ) {
    }

    /**
     * @param iterable<Feriado> $feriados os do escritório (ou mais de um escritório: cada feriado é julgado pelo seu)
     *
     * @return list<Feriado> na mesma ordem
     */
    public function filtrar(iterable $feriados, ?User $pessoa): array
    {
        $valem = [];
        foreach ($feriados as $feriado) {
            if ($this->valePara($feriado, $pessoa)) {
                $valem[] = $feriado;
            }
        }

        return $valem;
    }

    public function valePara(Feriado $feriado, ?User $pessoa): bool
    {
        $lotacao = $feriado->getLotacao();
        if ($lotacao === null) {
            return true;
        }
        $escritorio = $feriado->getTenant();
        if ($pessoa === null || $escritorio === null) {
            return false;
        }

        return $lotacao->getId() !== null && $this->lotacaoNoEscritorio($pessoa, $escritorio) === $lotacao->getId();
    }

    /** Zera a memória entre requests e entre mensagens do worker: a lotação da pessoa pode mudar. */
    public function reset(): void
    {
        $this->lotacoes = [];
    }

    private function lotacaoNoEscritorio(User $pessoa, Tenant $escritorio): ?int
    {
        if ($pessoa->getId() === null || $escritorio->getId() === null) {
            return $this->consultar($pessoa, $escritorio);
        }
        $chave = $pessoa->getId() . ':' . $escritorio->getId();
        if (!\array_key_exists($chave, $this->lotacoes)) {
            $this->lotacoes[$chave] = $this->consultar($pessoa, $escritorio);
        }

        return $this->lotacoes[$chave];
    }

    private function consultar(User $pessoa, Tenant $escritorio): ?int
    {
        return $this->vinculos->findPorUserETenant($pessoa, $escritorio)?->getLotacao()?->getId();
    }
}
