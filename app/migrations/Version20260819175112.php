<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Acrescenta o auto-relacionamento de pai em pasta_secao (pasta dentro de pasta).
 */
final class Version20260819175112 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Acrescenta secao_pai_id em pasta_secao, para pastas aninhadas';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta_secao ADD secao_pai_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pasta_secao ADD CONSTRAINT FK_PASTA_SECAO_PAI FOREIGN KEY (secao_pai_id) REFERENCES pasta_secao (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_pasta_secao_pai ON pasta_secao (secao_pai_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_pasta_secao_pai');
        $this->addSql('ALTER TABLE pasta_secao DROP CONSTRAINT FK_PASTA_SECAO_PAI');
        $this->addSql('ALTER TABLE pasta_secao DROP secao_pai_id');
    }
}
