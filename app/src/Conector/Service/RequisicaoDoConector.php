<?php

declare(strict_types=1);

namespace App\Conector\Service;

use App\Conector\DTO\ChamadaDoConector;
use Symfony\Contracts\Service\ResetInterface;

/**
 * A requisição do `/mcp` em curso (`docs/specs/mcp-remoto-oauth.md`, 4.1 e 6.2): guarda a {@see ChamadaDoConector}
 * que o middleware do portão abriu, para o despacho das ferramentas ler, e as anotações da chamada (ferramenta, ação,
 * ids, contagens, resultado) para a linha do log `mcp`.
 *
 * Falha fechada: {@see atual()} sem requisição aberta lança, e abrir de novo na mesma requisição também. Serviço por
 * requisição: zera no `reset()` (tag `kernel.reset`, entre requisições e entre mensagens do worker).
 */
final class RequisicaoDoConector implements ResetInterface
{
    private ?ChamadaDoConector $chamada = null;

    /** @var array<string, int|string|bool|array<array-key, mixed>|null> */
    private array $anotacoes = [];

    /** @throws \LogicException requisição já aberta */
    public function abrir(ChamadaDoConector $chamada): void
    {
        if ($this->chamada !== null) {
            throw new \LogicException('A requisição do conector já está aberta.');
        }

        $this->chamada = $chamada;
    }

    public function estaAberta(): bool
    {
        return $this->chamada !== null;
    }

    /** @throws \LogicException nenhuma requisição aberta pelo portão */
    public function atual(): ChamadaDoConector
    {
        return $this->chamada ?? throw new \LogicException('Nenhuma requisição do conector aberta pelo portão.');
    }

    /**
     * Anota um dado da chamada para o log `mcp`. Só nome de ferramenta, ação, ids, contagens e resultado; nunca texto,
     * título, nome ou valor de campo (6.2, regra 9).
     *
     * @param int|string|bool|array<array-key, mixed>|null $valor
     */
    public function anotar(string $chave, int|string|bool|array|null $valor): void
    {
        $this->anotacoes[$chave] = $valor;
    }

    /** @return int|string|bool|array<array-key, mixed>|null */
    public function anotacao(string $chave): int|string|bool|array|null
    {
        return $this->anotacoes[$chave] ?? null;
    }

    /** @return array<string, int|string|bool|array<array-key, mixed>|null> */
    public function anotacoes(): array
    {
        return $this->anotacoes;
    }

    public function reset(): void
    {
        $this->chamada = null;
        $this->anotacoes = [];
    }
}
