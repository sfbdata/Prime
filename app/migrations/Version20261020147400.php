<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Temas comemorativos (spec docs/specs/temas-comemorativos.md): tabela GLOBAL `tema_config`, linha única (id 1)
 * com o modo, o tema fixado e os temas desligados. Sem linha vale o automático com todos ligados, então nada é
 * semeado. O CHECK garante a linha única no banco (o Doctrine não compara CHECK, então o schema:validate não acusa).
 *
 * SQL do doctrine:schema:update --dump-sql (skill criar-migration): a divergência que já existia no banco da
 * frente (messenger_messages, índices funcionais de cobrança, DROP DEFAULT de cobranca_pessoa_*,
 * uq_confirmacao_email_lembrete) não é desta frente e ficou de fora. Só a tabela nova.
 */
final class Version20261020147400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Temas comemorativos: tabela tema_config (linha única, configurada pelo Master)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE tema_config (id INT NOT NULL, modo VARCHAR(12) DEFAULT 'automatico' NOT NULL, manual VARCHAR(20) DEFAULT NULL, desligados JSON NOT NULL, alterado_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, alterado_por_id INT DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT chk_tema_config_linha_unica CHECK (id = 1))");
        $this->addSql('CREATE INDEX idx_tema_config_alterado_por ON tema_config (alterado_por_id)');
        $this->addSql('ALTER TABLE tema_config ADD CONSTRAINT FK_130A8EFE4D466817 FOREIGN KEY (alterado_por_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tema_config');
    }
}
