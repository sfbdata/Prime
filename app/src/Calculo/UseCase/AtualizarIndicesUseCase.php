<?php

declare(strict_types=1);

namespace App\Calculo\UseCase;

use App\AtualizacaoMonetaria\Service\ImportadorDeIndices;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Botão "Atualizar índices": busca INPC, IPCA e Selic no Banco Central e grava na tabela global.
 * Duas tentativas em menos de um minuto não vão ao BCB de novo: devolvem o estado atual.
 */
final class AtualizarIndicesUseCase
{
    private const INTERVALO_MINIMO_SEGUNDOS = 60;

    public function __construct(
        private readonly ImportadorDeIndices $importador,
        private readonly ConsultarStatusDosIndicesUseCase $status,
        private readonly CacheInterface $cache,
        private readonly ClockInterface $relogio,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    /** @return array{status: array{estado: string, texto: string, dica: string, desatualizado: bool}, erro: string} */
    public function executar(): array
    {
        $agora = $this->relogio->now()->getTimestamp();
        $ultima = (int) $this->cache->get(ConsultarStatusDosIndicesUseCase::CHAVE_ULTIMA_TENTATIVA, static fn (): int => 0);
        if ($agora - $ultima < self::INTERVALO_MINIMO_SEGUNDOS) {
            return ['status' => $this->status->executar(), 'erro' => ''];
        }

        $resultado = $this->importador->atualizar();
        if ($resultado['ocupado']) {
            return ['status' => $this->status->executar(), 'erro' => $this->tradutor->trans('calculo.erro.indices_ocupado')];
        }

        $this->cache->delete(ConsultarStatusDosIndicesUseCase::CHAVE_ULTIMA_TENTATIVA);
        $this->cache->get(ConsultarStatusDosIndicesUseCase::CHAVE_ULTIMA_TENTATIVA, static fn (): int => $agora);

        return [
            'status' => $this->status->executar(),
            'erro' => $resultado['falhas'] === [] ? '' : $this->tradutor->trans('calculo.erro.indices_falha', ['series' => implode(', ', $resultado['falhas'])]),
        ];
    }
}
