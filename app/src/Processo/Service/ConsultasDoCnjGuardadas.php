<?php

declare(strict_types=1);

namespace App\Processo\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Psr\Cache\CacheItemPoolInterface;

/**
 * O `_source` da última consulta ao CNJ ("Buscar no CNJ"), guardado no servidor por 30 minutos para o cadastro
 * pela janela da pasta aplicar o que a janela não carrega (assuntos, sigilo, formato, sistema, códigos, id e o
 * bruto do Datajud). O navegador nunca manda esses dados: viriam forjáveis.
 *
 * A chave prende a consulta ao escritório, à pessoa, à sessão de login e ao número: ninguém aproveita a
 * consulta de outra pessoa, e uma sessão nova consulta de novo.
 */
final class ConsultasDoCnjGuardadas
{
    public const VALIDADE_EM_SEGUNDOS = 1800;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /** @param array<mixed> $source o `_source` do Datajud, como veio */
    public function guardar(Tenant $tenant, User $usuario, string $sessao, string $numeroSoDigitos, array $source): void
    {
        $item = $this->cache->getItem($this->chave($tenant, $usuario, $sessao, $numeroSoDigitos));
        $item->set($source);
        $item->expiresAfter(self::VALIDADE_EM_SEGUNDOS);
        $this->cache->save($item);
    }

    /** @return array<mixed>|null o `_source` guardado, ou nulo (não consultou, venceu, outra sessão) */
    public function buscar(Tenant $tenant, User $usuario, string $sessao, string $numeroSoDigitos): ?array
    {
        $item = $this->cache->getItem($this->chave($tenant, $usuario, $sessao, $numeroSoDigitos));
        $source = $item->isHit() ? $item->get() : null;

        return is_array($source) ? $source : null;
    }

    private function chave(Tenant $tenant, User $usuario, string $sessao, string $numeroSoDigitos): string
    {
        return 'cnj_consulta_' . hash('sha256', implode('|', [(string) $tenant->getId(), (string) $usuario->getId(), $sessao, $numeroSoDigitos]));
    }
}
