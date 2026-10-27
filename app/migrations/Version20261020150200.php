<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Passagem de etapa da meta (desenhos 04 e 04B de Minhas Metas de 22/10; spec `docs/specs/minhas-metas-esteira-2210.md`,
 * Fase 3): `tarefa` guarda as etapas que a pasta saltou até chegar à meta (o trilho as risca) e `tarefa_mensagem`
 * guarda para quem foi o ato (o "quem → para" da passagem de etapa e da devolução). As metas e mensagens existentes
 * ficam sem etapa saltada e sem destinatário: nada é preenchido para trás.
 */
final class Version20261020150200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Minhas Metas: etapas saltadas da meta e destinatário do despacho (passagem de etapa)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE tarefa ADD etapas_saltadas JSON DEFAULT '[]' NOT NULL");
        $this->addSql('ALTER TABLE tarefa_mensagem ADD destinatario_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tarefa_mensagem ADD CONSTRAINT FK_F0B050EBB564FBC1 FOREIGN KEY (destinatario_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_F0B050EBB564FBC1 ON tarefa_mensagem (destinatario_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tarefa_mensagem DROP CONSTRAINT FK_F0B050EBB564FBC1');
        $this->addSql('DROP INDEX IDX_F0B050EBB564FBC1');
        $this->addSql('ALTER TABLE tarefa_mensagem DROP destinatario_id');
        $this->addSql('ALTER TABLE tarefa DROP etapas_saltadas');
    }
}
