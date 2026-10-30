<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Chat I.A: origem da conversa (pagina, central, importada)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE chat_ia_conversa ADD origem VARCHAR(16) DEFAULT 'pagina' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chat_ia_conversa DROP origem');
    }
}
