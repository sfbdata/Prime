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
        #[Autowire('%kernel.project_dir%/var/master-fontes/%kernel.environment%')]
        private readonly string $diretorioDeTravas,
    ) {
    }

    /**
     * Executa `$trabalho` com trava exclusiva de arquivo (flock) chamada `$nome`: evita duas buscas
     * simultâneas na OAB/IBGE e protege a leitura-modificação-gravação do retrato.
     * Com `$esperar = false`, se outra execução já segura a trava, não executa e devolve [false, null].
     *
     * @return array{0: bool, 1: mixed}
     */
    public function comTrava(string $nome, callable $trabalho, bool $esperar = true): array
    {
        if (!is_dir($this->diretorioDeTravas) && !@mkdir($this->diretorioDeTravas, 0775, true) && !is_dir($this->diretorioDeTravas)) {
            throw new \RuntimeException('Não foi possível criar o diretório de travas do Painel do Master.');
        }

        $arquivo = fopen($this->diretorioDeTravas . '/' . preg_replace('/[^a-z0-9_.-]/i', '_', $nome) . '.lock', 'c');
        if ($arquivo === false) {
            throw new \RuntimeException('Não foi possível abrir a trava ' . $nome . '.');
        }

        try {
            if (!flock($arquivo, \LOCK_EX | ($esperar ? 0 : \LOCK_NB))) {
                return [false, null];
            }

            try {
                return [true, $trabalho()];
            } finally {
                flock($arquivo, \LOCK_UN);
            }
        } finally {
            fclose($arquivo);
        }
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
