<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260519100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove tabela pre_cadastro (módulo descontinuado)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pre_cadastro DROP CONSTRAINT FK_CD10B6C1DE734E51');
        $this->addSql('DROP INDEX UNIQ_CD10B6C1DE734E51');
        $this->addSql('DROP TABLE pre_cadastro');
    }

    public function down(Schema $schema): void
    {
        throw new \LogicException(
            'Migração irreversível: módulo PreCadastro descontinuado. '
            . 'Para restaurar, ver commit e790cf8 e migrations '
            . 'Version20260218213217 → Version20260219034356.'
        );
    }
}
