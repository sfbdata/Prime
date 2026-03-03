<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260303150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Criar tabela evento e evento_participante para sistema de agenda';
    }

    public function up(Schema $schema): void
    {
        // Tabela principal de eventos
        $this->addSql('CREATE TABLE evento (
            id SERIAL PRIMARY KEY,
            criador_id INT NOT NULL,
            titulo VARCHAR(255) NOT NULL,
            descricao TEXT DEFAULT NULL,
            data_inicio TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            data_fim TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            local VARCHAR(255) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'agendado\',
            cor VARCHAR(20) NOT NULL DEFAULT \'#0073b7\',
            dia_inteiro BOOLEAN NOT NULL DEFAULT FALSE,
            recorrente BOOLEAN NOT NULL DEFAULT FALSE,
            tipo_recorrencia VARCHAR(50) DEFAULT NULL,
            fim_recorrencia TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            criado_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            modificado_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            CONSTRAINT fk_evento_criador FOREIGN KEY (criador_id) REFERENCES "user" (id) ON DELETE CASCADE
        )');

        // Comentários para campos imutáveis
        $this->addSql('COMMENT ON COLUMN evento.data_inicio IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN evento.data_fim IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN evento.fim_recorrencia IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN evento.criado_em IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN evento.modificado_em IS \'(DC2Type:datetime_immutable)\'');

        // Índices
        $this->addSql('CREATE INDEX idx_evento_criador ON evento (criador_id)');
        $this->addSql('CREATE INDEX idx_evento_data_inicio ON evento (data_inicio)');
        $this->addSql('CREATE INDEX idx_evento_status ON evento (status)');

        // Tabela de participantes (ManyToMany)
        $this->addSql('CREATE TABLE evento_participante (
            evento_id INT NOT NULL,
            user_id INT NOT NULL,
            PRIMARY KEY (evento_id, user_id),
            CONSTRAINT fk_evento_participante_evento FOREIGN KEY (evento_id) REFERENCES evento (id) ON DELETE CASCADE,
            CONSTRAINT fk_evento_participante_user FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE
        )');

        // Índices para a tabela de participantes
        $this->addSql('CREATE INDEX idx_evento_participante_evento ON evento_participante (evento_id)');
        $this->addSql('CREATE INDEX idx_evento_participante_user ON evento_participante (user_id)');

        // Adicionar coluna url na tabela notificacao
        $this->addSql('ALTER TABLE notificacao ADD COLUMN url VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS evento_participante');
        $this->addSql('DROP TABLE IF EXISTS evento');
        $this->addSql('ALTER TABLE notificacao DROP COLUMN IF EXISTS url');
    }
}
