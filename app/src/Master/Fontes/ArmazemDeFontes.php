<?php

declare(strict_types=1);

namespace App\Master\Fontes;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Último retrato de cada fonte pública do mapa (IBGE, Quadro da Advocacia, tabela enviada pelo
 * Master), com a FONTE e a DATA da busca (spec perfis-master D8).
 *
 * Mora no pool `cache.master_fontes` (config/packages/cache.yaml): filesystem SEM expiração em
 * `var/master-fontes/<ambiente>`. Sem expiração de propósito: a idade é decidida aqui, pela data
 * gravada, e um retrato velho continua valendo quando a fonte falha (a OAB fora do ar não apaga o
 * mapa). Se `var/` não sobreviver ao deploy, o comando `app:master:atualizar-fontes-publicas`
 * refaz o retrato; a malha e a população do IBGE são buscadas de novo na primeira consulta.
 */
final class ArmazemDeFontes
{
    public function __construct(
        #[Autowire(service: 'cache.master_fontes')]
        private readonly CacheItemPoolInterface $pool,
        private readonly ClockInterface $relogio,
    ) {
    }

    /**
     * @return array{dados: mixed, fonte: string, buscadoEm: string}|null
     */
    public function ler(string $chave): ?array
    {
        $item = $this->pool->getItem(self::chave($chave));
        if (!$item->isHit()) {
            return null;
        }

        $valor = $item->get();

        return is_array($valor) && array_key_exists('dados', $valor) ? $valor : null;
    }

    public function gravar(string $chave, mixed $dados, string $fonte): void
    {
        $item = $this->pool->getItem(self::chave($chave));
        $item->set(['dados' => $dados, 'fonte' => $fonte, 'buscadoEm' => $this->relogio->now()->format(\DATE_ATOM)]);
        $this->pool->save($item);
    }

    /** O retrato existe e foi buscado há menos de `$dias` dias? */
    public function fresco(?array $retrato, int $dias): bool
    {
        if ($retrato === null) {
            return false;
        }

        try {
            $buscado = new \DateTimeImmutable((string) $retrato['buscadoEm']);
        } catch (\Exception) {
            return false;
        }

        return $buscado > $this->relogio->now()->modify(sprintf('-%d days', $dias));
    }

    private static function chave(string $chave): string
    {
        return str_replace(['{', '}', '(', ')', '/', '\\', '@', ':'], '_', $chave);
    }
}
