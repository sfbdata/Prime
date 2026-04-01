<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260401140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ativar todos os usuários existentes para contornar erro de login do UserChecker';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE "user" SET is_active = true WHERE is_active = false');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE "user" SET is_active = false WHERE is_active = true');
    }
}
