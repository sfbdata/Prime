<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;

/**
 * Faixa "histórico" sob a linha do tempo da pasta (desenho 02 de 31/10/2026, `hist`): as 8 ocorrências mais recentes entre
 * as metas da pasta (aberta ou respondida) e as movimentações do Push que são decisão, sentença, despacho ou intimação.
 * Cada ocorrência leva à aba de onde veio. Só junta dados que a pasta já tem; nada é gravado.
 */
final class HistoricoDaTrilhaDaPasta
{
    public const LIMITE = 8;
    private const PUBLICACOES_LIDAS = 50;
    private const PUSH_QUE_ENTRA = '/decis|senten|despach|intima/u';

    public function __construct(
        private readonly PastaRepository $pastas,
        private readonly PublicacaoDjenRepository $publicacoes,
    ) {
    }

    /**
     * @param list<string> $abasOcultas abas que o usuário não vê: a ocorrência cuja aba some também não aparece
     *
     * @return list<array{aba: string, icone: string, tom: string, data: string, chave: string, tipo: string, detalhe: string}>
     */
    public function montar(Pasta $pasta, Tenant $tenant, array $abasOcultas = []): array
    {
        $itens = [];
        if (!in_array('metas', $abasOcultas, true)) {
            foreach ($pasta->getTarefas() as $meta) {
                $itens[] = $this->deMeta($meta);
            }
        }
        if (!in_array('push', $abasOcultas, true)) {
            $itens = array_merge($itens, $this->doPush($pasta, $tenant));
        }

        usort($itens, static fn (array $a, array $b): int => $b['_quando'] <=> $a['_quando']);

        return array_map(static function (array $i): array {
            unset($i['_quando']);

            return $i;
        }, array_slice($itens, 0, self::LIMITE));
    }

    /** @return array<string, mixed> */
    private function deMeta(Tarefa $meta): array
    {
        $respondida = $meta->getStatus() === Tarefa::STATUS_CONCLUIDA && $meta->getDataConclusao() !== null;
        $quando = $respondida ? $meta->getDataConclusao() : $meta->getDataCriacao();

        return [
            'aba' => 'metas', 'icone' => $respondida ? 'bi-check2-circle' : 'bi-flag', 'tom' => $respondida ? 'respondida' : 'aberta',
            'data' => $quando->format('d/m/y'), 'chave' => $respondida ? 'meta_respondida' : 'meta_aberta', 'tipo' => '',
            'detalhe' => $meta->getTitulo(), '_quando' => $quando->getTimestamp(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function doPush(Pasta $pasta, Tenant $tenant): array
    {
        $numeros = $this->pastas->numerosDosProcessosVinculados($pasta, $tenant);
        if ($numeros === []) {
            return [];
        }

        $itens = [];
        foreach ($this->publicacoes->listarItensPorNumerosDoTenant($tenant, $numeros, self::PUBLICACOES_LIDAS) as $pub) {
            $tipo = trim(($pub->tipoComunicacao ?? '') . ' ' . ($pub->tipoDocumento ?? ''));
            if ($pub->dataIso === null || preg_match(self::PUSH_QUE_ENTRA, mb_strtolower($tipo)) !== 1) {
                continue;
            }
            $decisao = preg_match('/decis|senten/u', mb_strtolower($tipo)) === 1;
            $dia = new \DateTimeImmutable($pub->dataIso);
            $itens[] = [
                'aba' => 'push', 'icone' => $decisao ? 'bi-hammer' : 'bi-broadcast', 'tom' => 'push',
                'data' => $dia->format('d/m/y'), 'chave' => 'push', 'tipo' => (string) ($pub->tipoComunicacao ?: $pub->tipoDocumento ?: ''),
                'detalhe' => $pub->textoResumo ?? '', '_quando' => $dia->getTimestamp(),
            ];
        }

        return $itens;
    }
}
