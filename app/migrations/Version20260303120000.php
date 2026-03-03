<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260303120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria tabela notificacao para sistema de notificações';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE notificacao (
            id SERIAL NOT NULL,
            usuario_id INT NOT NULL,
            tarefa_id INT DEFAULT NULL,
            tipo VARCHAR(50) NOT NULL,
            titulo VARCHAR(255) NOT NULL,
            mensagem TEXT DEFAULT NULL,
            lida BOOLEAN NOT NULL DEFAULT FALSE,
            criada_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            lida_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            PRIMARY KEY(id)
        )');
        
        $this->addSql('CREATE INDEX idx_notificacao_usuario ON notificacao (usuario_id)');
        $this->addSql('CREATE INDEX idx_notificacao_lida ON notificacao (lida)');
        $this->addSql('CREATE INDEX idx_notificacao_criada_em ON notificacao (criada_em)');
        
        $this->addSql('ALTER TABLE notificacao ADD CONSTRAINT fk_notificacao_usuario FOREIGN KEY (usuario_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE notificacao ADD CONSTRAINT fk_notificacao_tarefa FOREIGN KEY (tarefa_id) REFERENCES tarefa (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE notificacao');
    }
}
