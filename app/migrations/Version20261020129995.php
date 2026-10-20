<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Indicar o BlueJus, cadastro pelo link curto (docs/specs/indicar-bluejus.md, comportamento 7): `indicacao.indicado_user_id`
 * liga a conta criada pelo `/i/{codigo}` a quem indicou. Índice único parcial: uma atribuição por conta nova.
 * `por_cadastro` marca a atribuição para sempre: depois do SET NULL (conta nova apagada) ela continua fora dos envios.
 *
 * Gerada pelo diff do mapeamento e limpa do ruído de base (messenger_messages, DROP DEFAULT de cobranca_pessoa_*,
 * DROP INDEX dos índices funcionais da Cobrança e a recriação do uq_confirmacao_email_lembrete), que não é desta frente.
 */
final class Version20261020129995 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Indicar o BlueJus: conta criada pelo link curto ligada a quem indicou';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE indicacao ADD indicado_user_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE indicacao ADD por_cadastro BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE indicacao ADD CONSTRAINT FK_35D86911989FB5E0 FOREIGN KEY (indicado_user_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_35D86911989FB5E0 ON indicacao (indicado_user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_indicacao_indicado_user ON indicacao (indicado_user_id) WHERE (indicado_user_id IS NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_indicacao_indicado_user');
        $this->addSql('DROP INDEX IDX_35D86911989FB5E0');
        $this->addSql('ALTER TABLE indicacao DROP CONSTRAINT FK_35D86911989FB5E0');
        $this->addSql('ALTER TABLE indicacao DROP indicado_user_id');
        $this->addSql('ALTER TABLE indicacao DROP por_cadastro');
    }
}
