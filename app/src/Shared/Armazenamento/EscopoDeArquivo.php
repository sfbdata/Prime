<?php

declare(strict_types=1);

namespace App\Shared\Armazenamento;

use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;

/**
 * A quem o arquivo pertence — **semanticamente**, não fisicamente (D1).
 *
 * Duas formas, e só duas:
 *
 *  - `deTenant(id)` — o arquivo é do escritório. É o caso de oito das nove categorias.
 *  - `global()`     — o arquivo não pertence a escritório nenhum. Hoje só a foto de perfil, que é
 *                     do `User`: é exatamente por isso que a purga de escritório a poupa de
 *                     propósito (`PurgarEscritorioUseCase.php:344`).
 *
 * **O escopo é semântico e o disco local pode ignorá-lo.** Sete das nove categorias moram hoje em
 * diretório plano, compartilhado entre escritórios; nelas o `ResolvedorDeCaminhoLocal` não usa o
 * escopo para montar o caminho. Isso é deliberado: o escopo existe no contrato para que a chave
 * carregue a informação que um backend remoto vai precisar, **sem** que a E2 tenha de mover um
 * arquivo sequer.
 *
 * O efeito colateral disso é o risco R1 da spec: um escopo errado é INVISÍVEL no backend local.
 * Por isso a prova de isolamento nunca deve sair do caminho resultante — sai dos testes das
 * fábricas de chave e do dublê `ArmazenamentoEmMemoria`, que materializa o escopo na chave interna
 * e por isso quebra quando o tenant está errado.
 *
 * **Nenhum formato remoto é fixado aqui** (D1). Como `t/{tenant_id}/…` e o equivalente global
 * viram caminho de objeto é decisão da E3/E4, e mora no adapter daquele backend — nunca neste VO.
 */
final readonly class EscopoDeArquivo
{
    private function __construct(
        private ?int $tenantId,
    ) {
    }

    public static function deTenant(int $tenantId): self
    {
        if ($tenantId <= 0) {
            throw new ChaveDeArquivoInvalida(
                sprintf('Escopo de tenant exige id positivo; recebido %d.', $tenantId),
            );
        }

        return new self($tenantId);
    }

    /** Arquivo que não pertence a escritório nenhum (hoje: foto de perfil, que é do User). */
    public static function global(): self
    {
        return new self(null);
    }

    public function ehGlobal(): bool
    {
        return $this->tenantId === null;
    }

    /** Null quando global. Quem monta caminho com escopo precisa tratar os dois casos. */
    public function tenantIdOuNull(): ?int
    {
        return $this->tenantId;
    }

    /**
     * Id do tenant, ou exceção quando global.
     *
     * Existe para o resolvedor das duas categorias com isolamento físico: ali um escopo global
     * seria um defeito de programação, não um caso de uso, e falhar alto é melhor que montar
     * `uploads/cobrancas//<nome>`.
     */
    public function tenantIdObrigatorio(): int
    {
        if ($this->tenantId === null) {
            throw new ChaveDeArquivoInvalida(
                'Esta categoria isola por tenant no disco, mas o escopo informado é global.',
            );
        }

        return $this->tenantId;
    }

    public function ehIgualA(self $outro): bool
    {
        return $this->tenantId === $outro->tenantId;
    }

    /** Forma estável para chave de mapa e mensagem de erro. Não é formato de armazenamento. */
    public function comoTexto(): string
    {
        return $this->tenantId === null ? 'global' : 'tenant:' . $this->tenantId;
    }
}
