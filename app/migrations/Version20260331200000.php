<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260331200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona snapshot do nome da sede em registro_ponto para preservar histórico após exclusão da sede.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE registro_ponto ADD sede_nome_snapshot VARCHAR(255) DEFAULT NULL');
        $this->addSql('UPDATE registro_ponto rp SET sede_nome_snapshot = s.nome FROM sede s WHERE rp.sede_id = s.id AND rp.sede_nome_snapshot IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE registro_ponto DROP sede_nome_snapshot');
    }
}
