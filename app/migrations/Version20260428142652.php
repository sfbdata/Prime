<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260428142652 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona campo demitido_em ao usuário para registrar a data de demissão';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD demitido_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN \"user\".demitido_em IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP demitido_em');
    }
}
