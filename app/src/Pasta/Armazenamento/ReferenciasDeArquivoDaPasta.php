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
 * o referencia. A contagem é por `caminho_arquivo` SEM filtro de escritório: no disco local a
 * categoria é plana (sem subpasta por escritório), então a linha de outro escritório com o mesmo
 * nome também segura o arquivo, como a purga do escritório trata o `compartilhado`. É SQL cru de
 * propósito: o filtro de lixeira do Doctrine esconderia justamente as linhas que ainda seguram.
 *
 * ## Ordem (INV-6) e trava
 *
 * {@see semReferencia()} roda DENTRO da transação que apagou as linhas (depois do `flush`, antes
 * do COMMIT): trava com `SELECT … FOR UPDATE` o registro de `arquivo_armazenado` da chave e só
 * então conta. Quem quiser criar outra referência (C2) trava o mesmo registro: ou entra antes e a
 * contagem o enxerga, ou espera o COMMIT e vê zero referências, e então não une. A remoção física
 * vem depois do COMMIT, e o registro só é apagado ({@see descartarRegistros()}) para o que
 * realmente saiu do disco.
 */
final class ReferenciasDeArquivoDaPasta
{
    public function __construct(private readonly Connection $conexao)
    {
    }

    /**
     * Chamar dentro da transação aberta; trava o registro de cada chave antes de contar.
     *
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
            $this->travar($chave);
            if ($this->contar($chave) === 0) {
                $livres[] = $chave;
            }
        }

        return $livres;
    }

    /**
     * Apaga a linha de `arquivo_armazenado` das chaves que SAÍRAM do disco, para a C2 nunca unir um
     * upload novo a um registro cujo arquivo já não existe. Quem falhou na remoção física mantém o
     * registro (e o arquivo segue recuperável pelo log da remoção).
     *
     * @param list<ChaveDeArquivo> $livres
     * @param list<string>         $naoRemovidas descrições devolvidas pela remoção ({@see ChaveDeArquivo::comoTexto()} dentro)
     */
    public function descartarRegistros(array $livres, array $naoRemovidas = []): void
    {
        foreach ($livres as $chave) {
            if ($chave->categoria !== CategoriaDeArquivo::PASTA_DOCUMENTO || self::ficouNoDisco($chave, $naoRemovidas)) {
                continue;
            }
            $this->conexao->executeStatement(
                'DELETE FROM arquivo_armazenado a WHERE a.tenant_id = :t AND a.chave_fisica = :c
                 AND NOT EXISTS (SELECT 1 FROM pasta_documento d WHERE d.arquivo_id = a.id)',
                ['t' => $chave->escopo->tenantIdObrigatorio(), 'c' => $chave->nome],
            );
        }
    }

    public function contar(ChaveDeArquivo $chave): int
    {
        return (int) $this->conexao->fetchOne(
            'SELECT COUNT(*) FROM pasta_documento WHERE caminho_arquivo = :c',
            ['c' => $chave->nome],
        );
    }

    private function travar(ChaveDeArquivo $chave): void
    {
        $this->conexao->fetchOne(
            'SELECT id FROM arquivo_armazenado WHERE tenant_id = :t AND chave_fisica = :c FOR UPDATE',
            ['t' => $chave->escopo->tenantIdObrigatorio(), 'c' => $chave->nome],
        );
    }

    /** @param list<string> $naoRemovidas */
    private static function ficouNoDisco(ChaveDeArquivo $chave, array $naoRemovidas): bool
    {
        foreach ($naoRemovidas as $descricao) {
            if (str_contains($descricao, $chave->nome)) {
                return true;
            }
        }

        return false;
    }
}
