<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Selo "Assinado" por arquivo na aba Financeiro (desenho 02 VS 18.10.26 L1974-1976): três colunas nulas em
 * `pasta_documento`. `assinatura_origem` guarda o `OrigemDaAssinatura` ('digital' visto no upload, 'manual' pelo ⋮);
 * `assinado_por_id` com `ON DELETE SET NULL`, como `enviado_por_id`: o usuário sair não apaga o selo.
 *
 * Sem backfill: os arquivos anteriores ficam sem selo (pendência registrada no `mudancas/`).
 *
 * Escrita à mão, com os nomes do índice e da FK que o mapeamento gera (o diff traria ruído de outras frentes).
 */
final class Version20261020151000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pasta: selo "Assinado" por documento (assinado_em, assinatura_origem, assinado_por_id)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta_documento ADD assinado_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE pasta_documento ADD assinatura_origem VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE pasta_documento ADD assinado_por_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pasta_documento ADD CONSTRAINT FK_69D2B381A1F5FD58 FOREIGN KEY (assinado_por_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_69D2B381A1F5FD58 ON pasta_documento (assinado_por_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta_documento DROP CONSTRAINT FK_69D2B381A1F5FD58');
        $this->addSql('DROP INDEX IDX_69D2B381A1F5FD58');
        $this->addSql('ALTER TABLE pasta_documento DROP assinado_por_id');
        $this->addSql('ALTER TABLE pasta_documento DROP assinatura_origem');
        $this->addSql('ALTER TABLE pasta_documento DROP assinado_em');
    }
}
