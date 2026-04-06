<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260406000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona batch_id à justificativa_ponto para agrupar dias de uma mesma submissão';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE justificativa_ponto ADD COLUMN batch_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_JUST_BATCH ON justificativa_ponto (batch_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_JUST_BATCH');
        $this->addSql('ALTER TABLE justificativa_ponto DROP COLUMN batch_id');
    }
}
