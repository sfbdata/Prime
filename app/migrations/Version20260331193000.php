<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260331193000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Permite excluir sede sem bloquear histórico de ponto (FK registro_ponto.sede_id com ON DELETE SET NULL).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE registro_ponto DROP CONSTRAINT IF EXISTS fk_2ed7d752e19f41bf');
        $this->addSql('ALTER TABLE registro_ponto DROP CONSTRAINT IF EXISTS fk_registro_sede');
        $this->addSql('ALTER TABLE registro_ponto ADD CONSTRAINT fk_2ed7d752e19f41bf FOREIGN KEY (sede_id) REFERENCES sede (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE registro_ponto DROP CONSTRAINT IF EXISTS fk_2ed7d752e19f41bf');
        $this->addSql('ALTER TABLE registro_ponto ADD CONSTRAINT fk_2ed7d752e19f41bf FOREIGN KEY (sede_id) REFERENCES sede (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
