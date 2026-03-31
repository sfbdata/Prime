<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260331100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona campo description na tabela access_request';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('access_request')->hasColumn('description'),
            'Coluna description já existe em access_request.'
        );
        $this->addSql('ALTER TABLE access_request ADD description TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE access_request DROP COLUMN description');
    }
}
