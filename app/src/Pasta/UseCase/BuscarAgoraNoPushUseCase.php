<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Djen\DTO\PeriodoConsulta;
use App\Djen\UseCase\SincronizarPublicacoesDjenUseCase;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\BuscaNoPushOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\BuscaNoPushMuitoFrequenteException;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use Psr\Clock\ClockInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * "Buscar agora" do painel do Push (desenho 1.2.3, dc L.2626; spec push-painel-monitoramento §2).
 *
 * História: o advogado sabe que saiu algo no diário e não quer esperar a captação diária. Para
 * cada processo da pasta com monitoramento ativo, consulta o DJEN pelo número nos últimos 30 dias,
 * com a mesma deduplicação, vinculação, notificação e reconciliação da captação, e grava a última
 * verificação de cada número consultado. No limite do DJEN para e conta o que ficou para depois.
 *
 * DataJud fica fora (o mapper dele apaga e regrava as movimentações: spec própria). A leitura
 * completa do processo, com as 12 etapas, é do P4e.
 */
final class BuscarAgoraNoPushUseCase
{
    private const DIAS_DA_BUSCA = 30;

    public function __construct(
        private readonly SincronizarPublicacoesDjenUseCase $sincronizar,
        private readonly ClockInterface $relogio,
        private readonly RateLimiterFactoryInterface $pastaPushBuscarLimiter,
    ) {
    }

    /**
     * @throws PastaDeOutroEscritorioException pasta de outro escritório
     * @throws \DomainException                sem processo ou com o monitoramento pausado
     * @throws BuscaNoPushMuitoFrequenteException a pasta já buscou no último minuto
     */
    public function executar(Pasta $pasta, Tenant $tenant): BuscaNoPushOutput
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }

        $principal = $pasta->getProcessoPrincipal();
        if ($principal === null || !$principal->monitoramentoAtivo()) {
            throw new \DomainException('Ative o monitoramento para buscar.');
        }

        $this->exigirCotaDaPasta($pasta, $tenant);

        $hoje = $this->relogio->now()->setTime(0, 0);
        $resultado = $this->sincronizar->sincronizarNumeros(
            $tenant,
            PeriodoConsulta::ultimosDias(self::DIAS_DA_BUSCA, $hoje),
            $this->numerosMonitorados($pasta),
        );

        return BuscaNoPushOutput::doResultado($resultado);
    }

    /**
     * Uma busca por pasta a cada minuto (limitador `pasta_push_buscar`, chave escritório + pasta).
     * Consome só DEPOIS das recusas acima: pedido recusado por pausa não gasta a cota.
     */
    private function exigirCotaDaPasta(Pasta $pasta, Tenant $tenant): void
    {
        if (!$this->pastaPushBuscarLimiter->create($tenant->getId() . '-' . $pasta->getId())->consume()->isAccepted()) {
            throw new BuscaNoPushMuitoFrequenteException('Aguarde um minuto para buscar de novo.');
        }
    }

    /** @return list<string> */
    private function numerosMonitorados(Pasta $pasta): array
    {
        $numeros = [];
        foreach ($pasta->getPastaProcessos() as $vinculo) {
            $processo = $vinculo->getProcesso();
            if ($processo->monitoramentoAtivo()) {
                $numeros[] = $processo->getNumeroProcesso();
            }
        }

        return $numeros;
    }
}
