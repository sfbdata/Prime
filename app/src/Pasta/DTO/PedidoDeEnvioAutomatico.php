<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Cliente\Entity\Cliente;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;

/**
 * Um e-mail automático ao cliente de UMA pasta (spec push-envio-automatico §3), pronto para sair: o
 * assunto, o miolo já escapado, a cópia da Controladoria e as linhas que o envio deixa no histórico
 * da pasta (uma por movimentação, com a publicação em `referencia`, ou uma só no resumo semanal).
 */
final readonly class PedidoDeEnvioAutomatico
{
    /**
     * @param list<string>                                     $cc
     * @param non-empty-list<array{descricao: string, referencia: ?int}> $registros
     */
    public function __construct(
        public Tenant $tenant,
        public Pasta $pasta,
        public Cliente $cliente,
        public string $assunto,
        public string $corpoHtml,
        public array $cc,
        public array $registros,
    ) {
    }
}
