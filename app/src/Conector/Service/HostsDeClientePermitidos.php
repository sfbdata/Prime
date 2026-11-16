<?php

declare(strict_types=1);

namespace App\Conector\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Os hosts de onde o `client_id` (a URL do documento CIMD) pode vir (`docs/specs/mcp-remoto-oauth.md`, 3.4, 9.5 e 14.d).
 * Só a busca a host desta lista acontece, o que fecha o SSRF pela raiz.
 *
 * A lista é o parâmetro `conector.cimd_hosts` (variável `CONECTOR_CIMD_HOSTS`, separada por vírgula, lida em tempo de
 * execução): trocar a variável muda a lista sem deploy de código. A comparação é exata, em minúsculas, sem subdomínio
 * implícito: `claude.ai` não libera `api.claude.ai` nem `claude.ai.evil.com`.
 */
final class HostsDeClientePermitidos
{
    /** @var list<string> */
    private readonly array $hosts;

    /**
     * @param array<mixed> $hosts o parâmetro `conector.cimd_hosts`
     */
    public function __construct(#[Autowire(param: 'conector.cimd_hosts')] array $hosts)
    {
        $normalizados = [];
        foreach ($hosts as $host) {
            if (!\is_string($host)) {
                continue;
            }
            $host = strtolower(trim($host));
            if ($host !== '') {
                $normalizados[] = $host;
            }
        }

        $this->hosts = array_values(array_unique($normalizados));
    }

    public function permite(?string $host): bool
    {
        return $host !== null && \in_array(strtolower($host), $this->hosts, true);
    }

    /** @return list<string> */
    public function hosts(): array
    {
        return $this->hosts;
    }
}
