<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260330000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add description field to access_request table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE access_request ADD description TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE access_request DROP COLUMN description');
    }
}
