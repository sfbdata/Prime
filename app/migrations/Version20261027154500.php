<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Push, Diário da OAB: vínculo da publicação com a pasta e com a meta criada a partir do prazo
 * (spec `docs/specs/push-diario-oab-pasta-prazo.md`). Só aditiva: duas colunas nulas e suas FKs.
 */
final class Version20261027154500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Push: pasta_id e tarefa_id em djen_diario_oab';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE djen_diario_oab ADD pasta_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE djen_diario_oab ADD tarefa_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_djen_diario_oab_pasta ON djen_diario_oab (pasta_id)');
        $this->addSql('ALTER TABLE djen_diario_oab ADD CONSTRAINT fk_djen_diario_oab_pasta FOREIGN KEY (pasta_id) REFERENCES pasta (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE djen_diario_oab ADD CONSTRAINT fk_djen_diario_oab_tarefa FOREIGN KEY (tarefa_id) REFERENCES tarefa (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE djen_diario_oab DROP CONSTRAINT fk_djen_diario_oab_tarefa');
        $this->addSql('ALTER TABLE djen_diario_oab DROP CONSTRAINT fk_djen_diario_oab_pasta');
        $this->addSql('DROP INDEX idx_djen_diario_oab_pasta');
        $this->addSql('ALTER TABLE djen_diario_oab DROP COLUMN tarefa_id');
        $this->addSql('ALTER TABLE djen_diario_oab DROP COLUMN pasta_id');
    }
}
