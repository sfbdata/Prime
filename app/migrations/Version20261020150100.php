<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Despacho da meta (desenho 04B de Minhas Metas de 22/10; spec `docs/specs/minhas-metas-esteira-2210.md`, Fase 2a):
 * `tarefa_mensagem` ganha a ação (despacho, envio, devolução), natureza, prazo de cumprimento, ciência (quem e
 * quando), certidão (texto, quem e quando) e o código do registro (D1, C13), único no escritório, com o nome e o
 * cargo de quem registrou congelados no momento. As mensagens existentes ficam sem código e sem os demais campos: nada é preenchido para trás.
 */
final class Version20261020150100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Minhas Metas: despacho com natureza, prazo, ciência, certidão e código (tarefa_mensagem)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tarefa_mensagem ADD natureza VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE tarefa_mensagem ADD prazo_cumprimento DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE tarefa_mensagem ADD ciente_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE tarefa_mensagem ADD certidao TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE tarefa_mensagem ADD certificado_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE tarefa_mensagem ADD codigo VARCHAR(9) DEFAULT NULL');
        $this->addSql('ALTER TABLE tarefa_mensagem ADD acao VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE tarefa_mensagem ADD registrado_por_nome VARCHAR(180) DEFAULT NULL');
        $this->addSql('ALTER TABLE tarefa_mensagem ADD registrado_por_cargo VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE tarefa_mensagem ADD ciente_por_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tarefa_mensagem ADD certificado_por_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tarefa_mensagem ADD CONSTRAINT FK_F0B050EB4FB49402 FOREIGN KEY (ciente_por_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE tarefa_mensagem ADD CONSTRAINT FK_F0B050EB622196C5 FOREIGN KEY (certificado_por_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_F0B050EB4FB49402 ON tarefa_mensagem (ciente_por_id)');
        $this->addSql('CREATE INDEX IDX_F0B050EB622196C5 ON tarefa_mensagem (certificado_por_id)');
        // NULL não colide no índice único do PostgreSQL: as mensagens antigas, sem código, convivem.
        $this->addSql('CREATE UNIQUE INDEX uniq_tarefa_mensagem_tenant_codigo ON tarefa_mensagem (tenant_id, codigo)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_tarefa_mensagem_tenant_codigo');
        $this->addSql('ALTER TABLE tarefa_mensagem DROP CONSTRAINT FK_F0B050EB4FB49402');
        $this->addSql('ALTER TABLE tarefa_mensagem DROP CONSTRAINT FK_F0B050EB622196C5');
        $this->addSql('DROP INDEX IDX_F0B050EB4FB49402');
        $this->addSql('DROP INDEX IDX_F0B050EB622196C5');
        $this->addSql('ALTER TABLE tarefa_mensagem DROP natureza, DROP prazo_cumprimento, DROP ciente_em, DROP certidao, DROP certificado_em, DROP codigo, DROP acao, DROP registrado_por_nome, DROP registrado_por_cargo, DROP ciente_por_id, DROP certificado_por_id');
    }
}
