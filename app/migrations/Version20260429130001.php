<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260429130001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Renomear coluna FK escala_trabalho_id para jornada_colaborador_id em bloco_jornada_colaborador';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bloco_jornada_colaborador DROP CONSTRAINT fk_4f31c3b9e78b85af');
        $this->addSql('DROP INDEX idx_4f31c3b9e78b85af');
        $this->addSql('ALTER TABLE bloco_jornada_colaborador RENAME COLUMN escala_trabalho_id TO jornada_colaborador_id');
        $this->addSql('ALTER TABLE bloco_jornada_colaborador ADD CONSTRAINT FK_79973F80CD16C33D FOREIGN KEY (jornada_colaborador_id) REFERENCES jornada_colaborador (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_79973F80CD16C33D ON bloco_jornada_colaborador (jornada_colaborador_id)');
        $this->addSql('ALTER INDEX uniq_3863840ea76ed395 RENAME TO UNIQ_3E2FE347A76ED395');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bloco_jornada_colaborador DROP CONSTRAINT FK_79973F80CD16C33D');
        $this->addSql('DROP INDEX IDX_79973F80CD16C33D');
        $this->addSql('ALTER TABLE bloco_jornada_colaborador RENAME COLUMN jornada_colaborador_id TO escala_trabalho_id');
        $this->addSql('ALTER TABLE bloco_jornada_colaborador ADD CONSTRAINT fk_4f31c3b9e78b85af FOREIGN KEY (escala_trabalho_id) REFERENCES jornada_colaborador (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_4f31c3b9e78b85af ON bloco_jornada_colaborador (escala_trabalho_id)');
        $this->addSql('ALTER INDEX UNIQ_3E2FE347A76ED395 RENAME TO uniq_3863840ea76ed395');
    }
}
