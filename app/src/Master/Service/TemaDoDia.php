<?php

declare(strict_types=1);

namespace App\Master\Service;

use App\Master\Entity\TemaConfig;
use App\Master\Repository\TemaConfigRepository;
use Psr\Clock\ClockInterface;

/**
 * Qual tema comemorativo vale hoje (spec docs/specs/temas-comemorativos.md, "Regra do tema ativo"), como o
 * ativo() do desenho:
 *
 *  1. `desligado`: nenhum.
 *  2. `manual`: o tema escolhido (nenhum, se não houver escolha), mesmo que esteja desligado na lista.
 *  3. `automatico`: dos temas ligados que caem no dia, o primeiro que não é fraco; se não houver, o primeiro
 *     fraco. O dia específico vence o mês inteiro (12/10 dá Crianças, 13/10 dá Outubro Rosa).
 *
 * O dia é o de hoje pelo ClockInterface, no fuso de Brasília. Sem configuração gravada, vale o automático com
 * todos os temas ligados. A prévia do Master não passa por aqui: mora só no navegador dele.
 */
final class TemaDoDia
{
    public function __construct(
        private readonly TemaConfigRepository $repositorio,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function hoje(): \DateTimeImmutable
    {
        return CatalogoDeTemas::diaEmBrasilia($this->relogio->now());
    }

    /** Tema que vale agora, pela configuração gravada (leitura cacheada por requisição). */
    public function ativo(): ?TemaComemorativo
    {
        $config = $this->repositorio->atual();

        return $this->ativoPara($config, $this->hoje());
    }

    public function ativoPara(?TemaConfig $config, \DateTimeImmutable $dia): ?TemaComemorativo
    {
        if ($config === null) {
            return self::escolher(TemaConfig::MODO_AUTOMATICO, null, [], $dia);
        }

        return self::escolher($config->getModo(), $config->getManual(), $config->getDesligados(), $dia);
    }

    /**
     * A regra pura, sem banco nem relógio.
     *
     * @param list<string> $desligados
     */
    public static function escolher(string $modo, ?string $manual, array $desligados, \DateTimeImmutable $dia): ?TemaComemorativo
    {
        if ($modo === TemaConfig::MODO_DESLIGADO) {
            return null;
        }

        if ($modo === TemaConfig::MODO_MANUAL) {
            return $manual === null ? null : CatalogoDeTemas::buscar($manual);
        }

        $noDia = array_values(array_filter(
            CatalogoDeTemas::todos(),
            static fn (TemaComemorativo $t): bool => !in_array($t->id, $desligados, true) && $t->caiNoDia($dia),
        ));
        foreach ($noDia as $tema) {
            if (!$tema->fraco) {
                return $tema;
            }
        }

        return $noDia[0] ?? null;
    }

    /**
     * Estado para o card de controle do Master e para a resposta das rotas: modo, tema fixado, temas e o ativo.
     *
     * @return array{modo: string, manual: ?string, ativo: ?string, temas: list<array<string, mixed>>}
     */
    public function estado(?TemaConfig $config = null): array
    {
        $config ??= $this->repositorio->atual();
        $hoje = $this->hoje();
        $ativo = $this->ativoPara($config, $hoje);

        $temas = [];
        foreach (CatalogoDeTemas::todos() as $tema) {
            $temas[] = $tema->paraJson() + [
                'quando' => $tema->quando($hoje),
                'ligado' => $config === null || $config->estaLigado($tema->id),
            ];
        }

        return [
            'modo' => $config?->getModo() ?? TemaConfig::MODO_AUTOMATICO,
            'manual' => $config?->getManual(),
            'ativo' => $ativo?->id,
            'temas' => $temas,
        ];
    }
}
