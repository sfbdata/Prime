<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lastro do Painel (spec dashboard-parte-1, PR-F): a foto diária por colaborador (`dashboard_foto`)
 * passa a guardar também as metas em revisão, as pastas urgentes e o total de metas. Com elas saem
 * as setas de Metas ativas, Demandas ativas, Em revisão e Pastas urgentes e o histórico mensal dos
 * cards Metas ativas, Demandas urgentes e Meta global.
 *
 * Só ADD COLUMN, tudo NULL: a foto antiga fica com NULL ("sem lastro") e NADA é preenchido
 * retroativamente. O passado não se reconstrói; a série só vale daqui para a frente.
 */
final class Version20261026152200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lastro do Painel: dashboard_foto ganha metas_em_revisao, pastas_urgentes e metas_total (anuláveis, sem backfill)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dashboard_foto ADD metas_em_revisao INT DEFAULT NULL');
        $this->addSql('ALTER TABLE dashboard_foto ADD pastas_urgentes INT DEFAULT NULL');
        $this->addSql('ALTER TABLE dashboard_foto ADD metas_total INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dashboard_foto DROP metas_em_revisao');
        $this->addSql('ALTER TABLE dashboard_foto DROP pastas_urgentes');
        $this->addSql('ALTER TABLE dashboard_foto DROP metas_total');
    }
}
