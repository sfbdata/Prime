<?php

declare(strict_types=1);

namespace App\Pasta\Armazenamento;

use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use Doctrine\DBAL\Connection;

/**
 * Quem ainda aponta para um arquivo físico de documento de pasta (fatia C).
 *
 * Um arquivo só pode sair do disco quando NENHUMA linha de `pasta_documento` (viva ou na lixeira)
 * do mesmo escritório o referencia. A consulta é SQL cru de propósito: o filtro de lixeira do
 * Doctrine esconderia justamente as linhas que ainda seguram o arquivo.
 *
 * Chamado DEPOIS do COMMIT que removeu as linhas (INV-6): duas exclusões simultâneas nunca
 * apagam cedo, porque uma linha só deixa de contar quando o COMMIT dela acontece; no pior caso
 * as duas veem zero e as duas removem (idempotente).
 */
final class ReferenciasDeArquivoDaPasta
{
    public function __construct(private readonly Connection $conexao)
    {
    }

    /**
     * @param list<ChaveDeArquivo> $chaves
     *
     * @return list<ChaveDeArquivo> só as que nenhum documento referencia mais
     */
    public function semReferencia(array $chaves): array
    {
        $livres = [];
        foreach ($chaves as $chave) {
            if ($chave->categoria !== CategoriaDeArquivo::PASTA_DOCUMENTO) {
                $livres[] = $chave;

                continue;
            }
            if ($this->contar($chave) === 0) {
                $livres[] = $chave;
            }
        }

        return $livres;
    }

    public function contar(ChaveDeArquivo $chave): int
    {
        return (int) $this->conexao->fetchOne(
            'SELECT COUNT(*) FROM pasta_documento WHERE tenant_id = :t AND caminho_arquivo = :c',
            ['t' => $chave->escopo->tenantIdObrigatorio(), 'c' => $chave->nome],
        );
    }
}
