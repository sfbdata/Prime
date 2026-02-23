<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260223123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona anexo opcional em mensagem do chat da tarefa';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tarefa_mensagem ADD arquivo_anexo VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tarefa_mensagem DROP COLUMN arquivo_anexo');
    }
}
