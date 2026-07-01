<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260701204435 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona status de verificacao de OAB ao User (+ backfill: donos existentes -> confirmada)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD oab_status VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD oab_nome_oficial VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD oab_verificada_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        // Backfill (grandfather): quem já é dono de algum escritório é advogado operando →
        // confirmada (não o travar quando o gate ligar). Demais com OAB → nao_verificada.
        $this->addSql('UPDATE "user" SET oab_status = \'confirmada\' WHERE id IN (SELECT DISTINCT criado_por_id FROM tenant WHERE criado_por_id IS NOT NULL)');
        $this->addSql('UPDATE "user" SET oab_status = \'nao_verificada\' WHERE oab_status IS NULL AND oab_numero IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE "user" DROP oab_status');
        $this->addSql('ALTER TABLE "user" DROP oab_nome_oficial');
        $this->addSql('ALTER TABLE "user" DROP oab_verificada_em');
    }
}
