<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Base dos "arquivos duplicados" do explorador de Documentos (Trilha B, inventário §5):
 * `pasta_documento.sha256` guarda o SHA-256 dos bytes que estão no armazenamento (depois da
 * compressão, quando houve), e o índice `(tenant_id, sha256)` serve a consulta "já existe um
 * arquivo idêntico neste escritório?" feita a cada upload.
 *
 * Escrita à mão (worktree sem container). Nome do índice é o EXPLÍCITO do mapeamento
 * (`#[ORM\Index(name: 'idx_pasta_documento_tenant_sha256')]`) para o `doctrine:schema:validate`
 * bater sem hash. `CHAR(64)` vem de `options: ['fixed' => true]` na coluna; `DEFAULT NULL` é a
 * forma que o Doctrine gera para coluna anulável.
 *
 * A coluna nasce NULL em todo o acervo (22.808 linhas em prod, 24/09): quem preenche é
 * `app:documentos:calcular-hash`, em lotes, lendo pelo armazenamento. NULL significa "ainda não
 * calculado" e nunca participa da busca de duplicado.
 *
 * `cliente_documento` NÃO recebe a coluna nesta migration: o upload do cliente é outro fluxo
 * (`ClienteController`, categoria própria no armazenamento) e o aviso de duplicado é do explorador
 * de Documentos da pasta. Entra quando houver consumidor.
 */
final class Version20261006134500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona pasta_documento.sha256 (hash do arquivo armazenado) e o índice (tenant_id, sha256)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta_documento ADD sha256 CHAR(64) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_pasta_documento_tenant_sha256 ON pasta_documento (tenant_id, sha256)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_pasta_documento_tenant_sha256');
        $this->addSql('ALTER TABLE pasta_documento DROP sha256');
    }
}
