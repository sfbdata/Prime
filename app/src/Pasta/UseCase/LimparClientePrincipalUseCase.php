<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\Entity\Pasta;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Desfaz a escolha de cliente principal e devolve a pasta ao critério automático.
 *
 * Quem: usuário com permissão de edição na pasta. O quê: apagar a marcação, de modo que a
 * "Média por CPF" volte a seguir o cliente de cadastro mais antigo. Por quê: marcar era via de
 * mão única — escolhido um cliente, não havia porta de volta pela tela, e a única saída era
 * desvincular e revincular o cliente, que é destrutivo e nada óbvio.
 *
 * Erros: nenhum. Limpar uma pasta que já está no automático é operação idempotente, não é engano
 * do usuário — a coluna simplesmente continua nula.
 *
 * Diferença deliberada do precedente: o bloco de processos da pasta NÃO tem desmarcar (só existe
 * `pasta_definir_processo_principal`). Aqui existe por decisão do dono em 2026-08-18.
 */
final class LimparClientePrincipalUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(Pasta $pasta): void
    {
        $pasta->limparClientePrincipal();
        $this->em->flush();
    }
}
