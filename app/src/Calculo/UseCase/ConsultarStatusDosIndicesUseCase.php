<?php

declare(strict_types=1);

namespace App\Calculo\UseCase;

use App\AtualizacaoMonetaria\Enum\SerieIndice;
use App\AtualizacaoMonetaria\Repository\IndiceMonetarioRepository;
use App\AtualizacaoMonetaria\Service\ImportadorDeIndices;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * O selo "Índices até MM/AAAA" do topo da tela: até onde o IPCA gravado vai, se as três séries do cálculo
 * existem e quando o servidor tentou atualizar pela última vez (o cache de 3 dias do desenho).
 */
final class ConsultarStatusDosIndicesUseCase
{
    public const CHAVE_ULTIMA_TENTATIVA = 'calculo_indices_ultima_tentativa';
    private const VALIDADE_SEGUNDOS = 3 * 86400;

    public function __construct(
        private readonly IndiceMonetarioRepository $indices,
        private readonly CacheInterface $cache,
        private readonly ClockInterface $relogio,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    /**
     * `estado`: ok (as três séries existem) ou erro (falta alguma).
     *
     * @return array{estado: string, texto: string, dica: string, desatualizado: bool}
     */
    public function executar(): array
    {
        $ultimos = array_map(fn (SerieIndice $s): ?\DateTimeImmutable => $this->indices->ultimaCompetenciaPublicada($s), ImportadorDeIndices::SERIES_DO_CALCULO);
        $completo = !\in_array(null, $ultimos, true);
        $tentativa = $this->cache->get(self::CHAVE_ULTIMA_TENTATIVA, static fn (): int => 0);
        $agora = $this->relogio->now();
        $ipca = $this->indices->ultimaCompetenciaPublicada(SerieIndice::IPCA);
        $quando = $tentativa > 0 ? (new \DateTimeImmutable('@' . $tentativa))->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('d/m/Y H:i') : null;

        return [
            'estado' => $completo ? 'ok' : 'erro',
            'texto' => $completo && $ipca !== null
                ? $this->tradutor->trans('calculo.indices.ate', ['mes' => $ipca->format('m/Y')])
                : $this->tradutor->trans('calculo.indices.indisponiveis'),
            'dica' => $quando !== null
                ? $this->tradutor->trans('calculo.indices.dica_consulta', ['quando' => $quando])
                : $this->tradutor->trans('calculo.indices.dica'),
            'desatualizado' => !$completo || $tentativa === 0 || $agora->getTimestamp() - $tentativa > self::VALIDADE_SEGUNDOS,
        ];
    }
}
