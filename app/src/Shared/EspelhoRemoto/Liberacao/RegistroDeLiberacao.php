<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Liberacao;

use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\EspelhoRemoto\LayoutDeChaveRemota;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * O registro de liberação do disco, tabela `armazenamento_liberacao` (spec
 * `leitura-pelo-r2-e-liberacao-de-disco.md` §4, etapa B). Por DBAL, como a outbox: é infraestrutura,
 * lida sem escritório na sessão, e o SQL cru não passa pelo `TenantFilter`. O escritório está dentro
 * da chave remota (`core/t/{tenant}/…`), então a consulta pela chave nunca mistura escritórios.
 *
 * Uma linha ABERTA (`restaurado_em` nulo) diz que a cópia local saiu do disco depois de o R2 ter sido
 * provado com o SHA e o tamanho dela. É a prova que a leitura de recuperação aceita quando a outbox já
 * não tem o envio `verificado` (retenção de 90 dias), e é o que a reconciliação usa para separar
 * "liberado" de "perdido". O índice único parcial impede duas linhas abertas da mesma chave.
 *
 * Nesta etapa ninguém abre linha em produção: `registrar()` é da etapa C (liberar) e dos testes. A
 * exclusão já fecha a linha (`fechar()`, `fecharPrefixo()`, pelo `ArmazenamentoEspelhado`).
 */
final class RegistroDeLiberacao
{
    private const TABELA = 'armazenamento_liberacao';

    public function __construct(
        private readonly Connection $conexao,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** A linha aberta da chave remota, ou null quando a chave não está liberada (ou a linha não prova nada). */
    public function abertaDe(string $chaveRemota): ?LiberacaoAberta
    {
        $linha = $this->conexao->fetchAssociative(
            'SELECT id, lote, sha256, tamanho_bytes, liberado_em FROM ' . self::TABELA . ' WHERE chave_remota = ? AND restaurado_em IS NULL',
            [$chaveRemota],
        );
        if ($linha === false) {
            return null;
        }

        $sha256  = (string) $linha['sha256'];
        $tamanho = (int) $linha['tamanho_bytes'];
        if (!self::shaValido($sha256) || $tamanho < 0) {
            return null;
        }

        return new LiberacaoAberta(
            (int) $linha['id'],
            (string) $linha['lote'],
            $chaveRemota,
            $sha256,
            $tamanho,
            new \DateTimeImmutable((string) $linha['liberado_em'], new \DateTimeZone('UTC')),
        );
    }

    /**
     * Abre uma linha para a chave. Uma segunda linha aberta da mesma chave é recusada pelo índice único
     * parcial (a exceção do DBAL sobe: liberar duas vezes o mesmo arquivo é erro de quem chama).
     */
    public function registrar(string $lote, ChaveDeArquivo $chave, string $sha256, int $tamanhoBytes): int
    {
        if (trim($lote) === '' || \strlen($lote) > 64) {
            throw new \InvalidArgumentException('O lote precisa de 1 a 64 caracteres.');
        }
        if (!self::shaValido($sha256) || $tamanhoBytes < 0) {
            throw new \InvalidArgumentException('SHA-256 em hex minúsculo de 64 caracteres e tamanho não negativo.');
        }

        return (int) $this->conexao->fetchOne(
            'INSERT INTO ' . self::TABELA . ' (lote, categoria, escopo_tenant_id, nome, chave_remota, sha256, tamanho_bytes, liberado_em)
             VALUES (:lote, :categoria, :tenant, :nome, :chave, :sha, :tamanho, :agora)
             RETURNING id',
            [
                'lote' => $lote, 'categoria' => $chave->categoria->value, 'tenant' => $chave->escopo->tenantIdOuNull(),
                'nome' => $chave->nome, 'chave' => LayoutDeChaveRemota::chaveDe($chave), 'sha' => $sha256,
                'tamanho' => $tamanhoBytes, 'agora' => $this->agora(),
            ],
        );
    }

    /** Fecha a linha aberta da chave (`restaurado_em` = agora). Devolve quantas fechou: 0 ou 1. */
    public function fechar(string $chaveRemota): int
    {
        return (int) $this->conexao->executeStatement(
            'UPDATE ' . self::TABELA . ' SET restaurado_em = :agora WHERE chave_remota = :chave AND restaurado_em IS NULL',
            ['agora' => $this->agora(), 'chave' => $chaveRemota],
        );
    }

    /** Fecha as linhas abertas de um escritório numa categoria (a purga por prefixo). Devolve quantas fechou. */
    public function fecharPrefixo(EscopoDeArquivo $escopo, CategoriaDeArquivo $categoria): int
    {
        $tenant = $escopo->tenantIdOuNull();

        return (int) $this->conexao->executeStatement(
            'UPDATE ' . self::TABELA . ' SET restaurado_em = :agora WHERE categoria = :categoria AND restaurado_em IS NULL AND '
            . ($tenant === null ? 'escopo_tenant_id IS NULL' : 'escopo_tenant_id = :tenant'),
            ['agora' => $this->agora(), 'categoria' => $categoria->value] + ($tenant === null ? [] : ['tenant' => $tenant]),
        );
    }

    private static function shaValido(string $sha256): bool
    {
        return preg_match('/\A[0-9a-f]{64}\z/', $sha256) === 1;
    }

    /** Sempre em UTC, como a outbox: as colunas são `TIMESTAMP WITHOUT TIME ZONE`. */
    private function agora(): string
    {
        return $this->relogio->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
