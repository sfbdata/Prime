<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Assinatura de documentos, etapa 3, M2 (contrato docs/specs/assinatura-documentos-etapa-3.md, seção 2, item 1): o
 * código de validação, o PDF final com selo e o vínculo à pasta no documento.
 *
 * - `codigo`: o `BJA-XXXX-XXXX-XXXX` gerado no envio, único entre todos os escritórios (o índice único garante a
 *   colisão; o Postgres aceita vários nulos, os rascunhos);
 * - `arquivo_final_chave` e `hash_final`: a chave e o sha256 do PDF final, guardado uma vez depois da conclusão;
 * - `pasta_id`, `vinculo_pendente`, `vinculado_por_id`, `vinculado_em` e `pasta_documento_id`: o vínculo à pasta,
 *   sempre por id (o NUP não é único), quem o definiu e o arquivo que foi para a pasta.
 *
 * As três chaves estrangeiras são `ON DELETE SET NULL`: a purga do escritório já apaga as tabelas da assinatura antes
 * de `pasta`, e um usuário ou documento da pasta apagado não leva o documento assinado junto. Sem preenchimento
 * retroativo do código (E3-6): em produção nenhum documento foi enviado ainda.
 */
final class Version20261109290000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Assinatura de documentos (etapa 3): código de validação, PDF final com selo e vínculo à pasta no documento';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assinatura_eletronica_documento ADD codigo VARCHAR(18) DEFAULT NULL');
        $this->addSql('ALTER TABLE assinatura_eletronica_documento ADD arquivo_final_chave VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE assinatura_eletronica_documento ADD hash_final VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE assinatura_eletronica_documento ADD vinculo_pendente BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE assinatura_eletronica_documento ADD vinculado_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE assinatura_eletronica_documento ADD pasta_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE assinatura_eletronica_documento ADD vinculado_por_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE assinatura_eletronica_documento ADD pasta_documento_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE assinatura_eletronica_documento ADD CONSTRAINT FK_DD0858A77FCDBC8C FOREIGN KEY (pasta_id) REFERENCES pasta (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE assinatura_eletronica_documento ADD CONSTRAINT FK_DD0858A7EDD250C1 FOREIGN KEY (vinculado_por_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE assinatura_eletronica_documento ADD CONSTRAINT FK_DD0858A72183FA6D FOREIGN KEY (pasta_documento_id) REFERENCES pasta_documento (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_DD0858A720332D99 ON assinatura_eletronica_documento (codigo)');
        $this->addSql('CREATE INDEX IDX_DD0858A77FCDBC8C ON assinatura_eletronica_documento (pasta_id)');
        $this->addSql('CREATE INDEX IDX_DD0858A7EDD250C1 ON assinatura_eletronica_documento (vinculado_por_id)');
        $this->addSql('CREATE INDEX IDX_DD0858A72183FA6D ON assinatura_eletronica_documento (pasta_documento_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assinatura_eletronica_documento DROP CONSTRAINT FK_DD0858A77FCDBC8C');
        $this->addSql('ALTER TABLE assinatura_eletronica_documento DROP CONSTRAINT FK_DD0858A7EDD250C1');
        $this->addSql('ALTER TABLE assinatura_eletronica_documento DROP CONSTRAINT FK_DD0858A72183FA6D');
        $this->addSql('DROP INDEX UNIQ_DD0858A720332D99');
        $this->addSql('DROP INDEX IDX_DD0858A77FCDBC8C');
        $this->addSql('DROP INDEX IDX_DD0858A7EDD250C1');
        $this->addSql('DROP INDEX IDX_DD0858A72183FA6D');
        $this->addSql('ALTER TABLE assinatura_eletronica_documento DROP codigo');
        $this->addSql('ALTER TABLE assinatura_eletronica_documento DROP arquivo_final_chave');
        $this->addSql('ALTER TABLE assinatura_eletronica_documento DROP hash_final');
        $this->addSql('ALTER TABLE assinatura_eletronica_documento DROP vinculo_pendente');
        $this->addSql('ALTER TABLE assinatura_eletronica_documento DROP vinculado_em');
        $this->addSql('ALTER TABLE assinatura_eletronica_documento DROP pasta_id');
        $this->addSql('ALTER TABLE assinatura_eletronica_documento DROP vinculado_por_id');
        $this->addSql('ALTER TABLE assinatura_eletronica_documento DROP pasta_documento_id');
    }
}
