<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Central de Comunicação B (`docs/specs/central-comunicacao-b.md`): conversas em grupo e preferências individuais.
 *
 * Só acrescenta: `central_conversa.nome` e `administrador_id` (grupo), `central_participante.saiu_em`, `fixada`,
 * `silenciada`, `arquivada`, `central_mensagem.sistema` e o índice `(tenant_id, criada_em)` da janela do polling. Os
 * dois `DROP NOT NULL` só afrouxam o par (grupo não tem par): nenhuma linha existente muda — toda conversa que já existe
 * é individual, com o par preenchido, e a unicidade do par continua valendo para ela.
 *
 * DDL do `--dump-sql` filtrado por `central_` no banco da frente; o resto do dump é de outras frentes.
 */
final class Version20261015180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Central B: grupos (nome, administrador, saída) e preferências individuais (fixar, silenciar, arquivar)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE central_mensagem ADD sistema BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('CREATE INDEX idx_central_mensagem_tenant_criada ON central_mensagem (tenant_id, criada_em)');
        $this->addSql('ALTER TABLE central_conversa ADD nome VARCHAR(60) DEFAULT NULL');
        $this->addSql('ALTER TABLE central_conversa ADD administrador_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE central_conversa ALTER usuario_menor_id DROP NOT NULL');
        $this->addSql('ALTER TABLE central_conversa ALTER usuario_maior_id DROP NOT NULL');
        $this->addSql('ALTER TABLE central_conversa ADD CONSTRAINT FK_8D4759DC48DFEBB7 FOREIGN KEY (administrador_id) REFERENCES "user" (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_8D4759DC48DFEBB7 ON central_conversa (administrador_id)');
        $this->addSql('ALTER TABLE central_participante ADD saiu_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE central_participante ADD fixada BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE central_participante ADD silenciada BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE central_participante ADD arquivada BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Só volta se não houver grupo: um grupo tem o par nulo e quebraria o NOT NULL de volta.
        $this->abortIf(
            (int) $this->connection->fetchOne("SELECT COUNT(*) FROM central_conversa WHERE tipo = 'grupo'") > 0,
            'Há conversas em grupo: o down apagaria o nome e deixaria o par nulo. Remova-as conscientemente antes.'
        );
        $this->addSql('DROP INDEX idx_central_mensagem_tenant_criada');
        $this->addSql('ALTER TABLE central_mensagem DROP sistema');
        $this->addSql('ALTER TABLE central_participante DROP arquivada');
        $this->addSql('ALTER TABLE central_participante DROP silenciada');
        $this->addSql('ALTER TABLE central_participante DROP fixada');
        $this->addSql('ALTER TABLE central_participante DROP saiu_em');
        $this->addSql('ALTER TABLE central_conversa DROP CONSTRAINT FK_8D4759DC48DFEBB7');
        $this->addSql('DROP INDEX IDX_8D4759DC48DFEBB7');
        $this->addSql('ALTER TABLE central_conversa DROP administrador_id');
        $this->addSql('ALTER TABLE central_conversa DROP nome');
        $this->addSql('ALTER TABLE central_conversa ALTER usuario_menor_id SET NOT NULL');
        $this->addSql('ALTER TABLE central_conversa ALTER usuario_maior_id SET NOT NULL');
    }
}
