<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration para remover a tabela modelo_contrato
 */
final class Version20260306150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove a tabela modelo_contrato e limpa versões de migrations antigas';
    }

    public function up(Schema $schema): void
    {
        // Drop the table
        $this->addSql('DROP TABLE IF EXISTS modelo_contrato');
        
        // Remove versões antigas de migrations que não existem mais
        $this->addSql("DELETE FROM doctrine_migration_versions WHERE version LIKE '%Version20260306100000%'");
        $this->addSql("DELETE FROM doctrine_migration_versions WHERE version LIKE '%Version20260306120000%'");
        $this->addSql("DELETE FROM doctrine_migration_versions WHERE version LIKE '%Version20260306130000%'");
    }

    public function down(Schema $schema): void
    {
        // Recriar a tabela se necessário (opcional)
        $this->addSql('CREATE TABLE modelo_contrato (
            id SERIAL PRIMARY KEY,
            nome VARCHAR(255) NOT NULL,
            descricao TEXT DEFAULT NULL,
            conteudo TEXT DEFAULT NULL,
            ativo BOOLEAN NOT NULL DEFAULT TRUE,
            arquivo_path VARCHAR(255) DEFAULT NULL,
            arquivo_nome_original VARCHAR(255) DEFAULT NULL,
            tipo VARCHAR(20) NOT NULL DEFAULT \'docx\',
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
        )');
    }
}
