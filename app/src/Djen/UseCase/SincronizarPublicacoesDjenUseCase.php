<?php

declare(strict_types=1);

namespace App\Djen\UseCase;

use App\Djen\DTO\ConsultaComunicacoesQuery;
use App\Djen\DTO\PeriodoConsulta;
use App\Djen\DTO\ResultadoSincronizacao;
use App\Djen\Entity\OabMonitorada;
use App\Djen\Entity\PublicacaoDjen;
use App\Djen\Exception\ConsultaDjenException;
use App\Djen\Repository\OabMonitoradaRepository;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Djen\Service\DjenClientInterface;
use App\Djen\Service\DjenPublicacaoMapper;
use App\Djen\Service\NotificadorPublicacoesDjenInterface;
use App\Entity\Tenant\Tenant;
use App\Processo\Repository\ProcessoRepository;

/**
 * Sincroniza as publicações do DJEN de UM escritório: para cada OAB ativa monitorada, consulta o DJEN
 * no período, deduplica (por escritório + dentro da execução), persiste as novas vinculando ao Processo
 * quando o número CNJ já existe no escritório, e notifica os usuários com acesso ao módulo.
 *
 * Recebe o Tenant EXPLICITAMENTE — roda tanto no request (botão "sincronizar agora") quanto no comando
 * CLI (TenantFilter desligado). Toda query e criação é escopada por esse tenant; nunca depende do filtro.
 *
 * Idempotente: reexecutar o mesmo período não duplica (dedupe por (tenant, djen_id)) nem renotifica
 * publicações já existentes (só as realmente novas contam).
 */
final class SincronizarPublicacoesDjenUseCase
{
    private const ITENS_POR_PAGINA = 100;
    private const MAX_PAGINAS = 100; // teto de 10.000 resultados da API

    public function __construct(
        private readonly DjenClientInterface $djenClient,
        private readonly DjenPublicacaoMapper $mapper,
        private readonly OabMonitoradaRepository $oabRepository,
        private readonly PublicacaoDjenRepository $publicacaoRepository,
        private readonly ProcessoRepository $processoRepository,
        private readonly NotificadorPublicacoesDjenInterface $notificador,
    ) {
    }

    public function executar(Tenant $tenant, PeriodoConsulta $periodo, bool $dryRun = false): ResultadoSincronizacao
    {
        $resultado = new ResultadoSincronizacao();
        $vistosNesteRun = [];

        foreach ($this->oabRepository->listarAtivasDoTenant($tenant) as $oab) {
            try {
                $itens = $this->consultarTodosItens($oab, $periodo);
            } catch (ConsultaDjenException $e) {
                // Falha de uma OAB não derruba as demais — registra o motivo e segue.
                $resultado->registrarFalha($oab->getRotulo(), $e->getMotivo());

                continue;
            }

            $resultado->registrarRecebidas(\count($itens));
            $this->processarItens($oab, $tenant, $itens, $dryRun, $vistosNesteRun, $resultado);
        }

        if (!$dryRun && $resultado->getNovas() > 0) {
            $this->publicacaoRepository->flush();
            $resultado->definirNotificados($this->notificador->notificar($tenant, $resultado->getNovas()));
        }

        return $resultado;
    }

    /**
     * @param list<array<string, mixed>> $itens
     * @param array<string, true>        $vistosNesteRun djenId já criados nesta execução (dedupe cross-OAB)
     */
    private function processarItens(
        OabMonitorada $oab,
        Tenant $tenant,
        array $itens,
        bool $dryRun,
        array &$vistosNesteRun,
        ResultadoSincronizacao $resultado,
    ): void {
        // Dedupe intra-lote e contra o que já foi criado nesta execução (evita violar o unique).
        $itensPorId = [];
        foreach ($itens as $item) {
            $djenId = DjenPublicacaoMapper::djenIdDoItem($item);
            if ($djenId === null || isset($vistosNesteRun[$djenId])) {
                continue;
            }

            $itensPorId[$djenId] = $item;
        }

        if ($itensPorId === []) {
            return;
        }

        // Dedupe contra o banco (uma query) — escopado por tenant.
        $existentes = array_flip(
            $this->publicacaoRepository->filtrarDjenIdsExistentesDoTenant(array_keys($itensPorId), $tenant)
        );

        foreach ($itensPorId as $djenId => $item) {
            if (isset($existentes[$djenId])) {
                continue;
            }

            $vistosNesteRun[$djenId] = true;

            $publicacao = new PublicacaoDjen();
            $this->mapper->mapearItem($publicacao, $item);
            $publicacao->setTenant($tenant);
            $publicacao->setOabMonitorada($oab);
            $vinculada = $this->vincularProcesso($publicacao, $tenant);

            if (!$dryRun) {
                $this->publicacaoRepository->salvar($publicacao);
            }

            $resultado->registrarNova($vinculada);
        }
    }

    /**
     * Busca todas as páginas da OAB no período (até o teto), acumulando os itens brutos.
     *
     * @return list<array<string, mixed>>
     */
    private function consultarTodosItens(OabMonitorada $oab, PeriodoConsulta $periodo): array
    {
        $todos = [];

        for ($pagina = 1; $pagina <= self::MAX_PAGINAS; $pagina++) {
            $resposta = $this->djenClient->consultarComunicacoes(new ConsultaComunicacoesQuery(
                numeroOab: $oab->getNumero(),
                ufOab: $oab->getUf(),
                dataDisponibilizacaoInicio: $periodo->inicio,
                dataDisponibilizacaoFim: $periodo->fim,
                pagina: $pagina,
                itensPorPagina: self::ITENS_POR_PAGINA,
            ));

            foreach ($resposta->items as $item) {
                $todos[] = $item;
            }

            if ($resposta->quantidadeNaPagina() < self::ITENS_POR_PAGINA || \count($todos) >= $resposta->count) {
                break;
            }
        }

        return $todos;
    }

    /**
     * Vincula ao Processo do escritório quando o número CNJ casa. Sempre escopa pelo tenant
     * explícito (CLI roda com o TenantFilter desligado — homônimo de outro escritório NÃO pode casar).
     */
    private function vincularProcesso(PublicacaoDjen $publicacao, Tenant $tenant): bool
    {
        $numero = $publicacao->getNumeroProcesso();
        if ($numero === '') {
            return false;
        }

        $processo = $this->processoRepository->findByNumeroProcessoDoTenant($numero, $tenant);
        if ($processo === null) {
            return false;
        }

        $publicacao->setProcesso($processo);

        return true;
    }
}
