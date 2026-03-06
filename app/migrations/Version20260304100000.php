<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260304100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Criar tabelas chamado, chamado_interacao e chamado_anexo para sistema de Service Desk';
    }

    public function up(Schema $schema): void
    {
        // Tabela principal de chamados
        $this->addSql('CREATE TABLE chamado (
            id SERIAL PRIMARY KEY,
            solicitante_id INT NOT NULL,
            responsavel_id INT DEFAULT NULL,
            titulo VARCHAR(255) NOT NULL,
            descricao TEXT NOT NULL,
            categoria VARCHAR(50) NOT NULL DEFAULT \'outros\',
            prioridade VARCHAR(20) NOT NULL DEFAULT \'media\',
            status VARCHAR(20) NOT NULL DEFAULT \'aberto\',
            departamento VARCHAR(100) DEFAULT NULL,
            criado_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            atualizado_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            resolvido_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            fechado_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            CONSTRAINT fk_chamado_solicitante FOREIGN KEY (solicitante_id) REFERENCES "user" (id) ON DELETE CASCADE,
            CONSTRAINT fk_chamado_responsavel FOREIGN KEY (responsavel_id) REFERENCES "user" (id) ON DELETE SET NULL
        )');

        // Comentários para campos imutáveis
        $this->addSql('COMMENT ON COLUMN chamado.criado_em IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN chamado.atualizado_em IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN chamado.resolvido_em IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN chamado.fechado_em IS \'(DC2Type:datetime_immutable)\'');

        // Índices para chamado
        $this->addSql('CREATE INDEX idx_chamado_solicitante ON chamado (solicitante_id)');
        $this->addSql('CREATE INDEX idx_chamado_responsavel ON chamado (responsavel_id)');
        $this->addSql('CREATE INDEX idx_chamado_status ON chamado (status)');
        $this->addSql('CREATE INDEX idx_chamado_categoria ON chamado (categoria)');
        $this->addSql('CREATE INDEX idx_chamado_prioridade ON chamado (prioridade)');
        $this->addSql('CREATE INDEX idx_chamado_criado_em ON chamado (criado_em)');

        // Tabela de interações/histórico do chamado
        $this->addSql('CREATE TABLE chamado_interacao (
            id SERIAL PRIMARY KEY,
            chamado_id INT NOT NULL,
            usuario_id INT DEFAULT NULL,
            tipo VARCHAR(50) NOT NULL DEFAULT \'comentario\',
            mensagem TEXT NOT NULL,
            interno BOOLEAN NOT NULL DEFAULT FALSE,
            criado_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            CONSTRAINT fk_chamado_interacao_chamado FOREIGN KEY (chamado_id) REFERENCES chamado (id) ON DELETE CASCADE,
            CONSTRAINT fk_chamado_interacao_usuario FOREIGN KEY (usuario_id) REFERENCES "user" (id) ON DELETE SET NULL
        )');

        $this->addSql('COMMENT ON COLUMN chamado_interacao.criado_em IS \'(DC2Type:datetime_immutable)\'');

        // Índices para chamado_interacao
        $this->addSql('CREATE INDEX idx_chamado_interacao_chamado ON chamado_interacao (chamado_id)');
        $this->addSql('CREATE INDEX idx_chamado_interacao_usuario ON chamado_interacao (usuario_id)');
        $this->addSql('CREATE INDEX idx_chamado_interacao_tipo ON chamado_interacao (tipo)');
        $this->addSql('CREATE INDEX idx_chamado_interacao_criado_em ON chamado_interacao (criado_em)');

        // Tabela de anexos do chamado
        $this->addSql('CREATE TABLE chamado_anexo (
            id SERIAL PRIMARY KEY,
            chamado_id INT NOT NULL,
            usuario_id INT DEFAULT NULL,
            nome_original VARCHAR(255) NOT NULL,
            nome_arquivo VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            tamanho INT NOT NULL,
            criado_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            CONSTRAINT fk_chamado_anexo_chamado FOREIGN KEY (chamado_id) REFERENCES chamado (id) ON DELETE CASCADE,
            CONSTRAINT fk_chamado_anexo_usuario FOREIGN KEY (usuario_id) REFERENCES "user" (id) ON DELETE SET NULL
        )');

        $this->addSql('COMMENT ON COLUMN chamado_anexo.criado_em IS \'(DC2Type:datetime_immutable)\'');

        // Índices para chamado_anexo
        $this->addSql('CREATE INDEX idx_chamado_anexo_chamado ON chamado_anexo (chamado_id)');
        $this->addSql('CREATE INDEX idx_chamado_anexo_usuario ON chamado_anexo (usuario_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS chamado_anexo');
        $this->addSql('DROP TABLE IF EXISTS chamado_interacao');
        $this->addSql('DROP TABLE IF EXISTS chamado');
    }
}
