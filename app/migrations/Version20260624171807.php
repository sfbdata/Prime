<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260624171807 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona coluna editada_em em pasta_mensagem (edição da mensagem pelo autor)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta_mensagem ADD editada_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta_mensagem DROP editada_em');
    }
}
