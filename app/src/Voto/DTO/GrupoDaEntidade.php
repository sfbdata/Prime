<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/**
 * Um grupo `.vGr` do painel: a entidade (nome e CNPJ) com as assembleias dela (cada uma é um evento do desenho).
 */
final readonly class GrupoDaEntidade
{
    /**
     * @param string                $chave       nome sem acento em minúsculas, "|" e os dígitos do CNPJ (a chave do filtro "Entidade")
     * @param string                $nome        nome da entidade ou "Sem entidade informada" (a tradução é da tela: vem vazio)
     * @param string                $cnpj        com máscara; vazio sem CNPJ
     * @param list<AssembleiaNoPainel> $assembleias
     * @param array<string, int>    $resumo      pautas por situação (aberta, publicada, rascunho, encerrada)
     */
    public function __construct(
        public string $chave,
        public string $nome,
        public string $cnpj,
        public array $assembleias,
        public array $resumo,
        public bool $aberto,
    ) {
    }

    public function semNome(): bool
    {
        return '' === $this->nome;
    }
}
