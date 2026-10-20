<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Inteligência cadastral do cliente (F-A, spec docs/specs/cliente-inteligencia-cadastral.md): o documento do cadastro
 * pode ser um VÍNCULO com o documento da pasta (`pasta_documento_id`), sem cópia do arquivo.
 *
 * `ON DELETE CASCADE`: a purga do documento da pasta (lixeira vencida, purga do escritório) derruba o vínculo, que
 * não tem arquivo próprio; nenhum link quebrado fica no cadastro. Enquanto o documento está só na lixeira, o vínculo
 * fica e a tela o esconde. O índice único impede vincular o mesmo documento duas vezes ao mesmo cliente (NULL não
 * conta: os anexos com arquivo próprio não entram).
 *
 * SQL do `doctrine:schema:update --dump-sql` filtrado a `cliente_documento`: ficou de fora a divergência que já
 * existia (DROP de `messenger_messages`, DROP INDEX de índices funcionais de cobrança, DROP DEFAULT de
 * `cobranca_pessoa_*`), que não é desta frente.
 */
final class Version20261021150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cliente: documento do cadastro vinculado ao documento da pasta (cliente_documento.pasta_documento_id)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cliente_documento ADD pasta_documento_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE cliente_documento ADD CONSTRAINT FK_A1BCA1032183FA6D FOREIGN KEY (pasta_documento_id) REFERENCES pasta_documento (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_A1BCA1032183FA6D ON cliente_documento (pasta_documento_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_cliente_documento_vinculo ON cliente_documento (cliente_id, pasta_documento_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_cliente_documento_vinculo');
        $this->addSql('DROP INDEX IDX_A1BCA1032183FA6D');
        $this->addSql('ALTER TABLE cliente_documento DROP CONSTRAINT FK_A1BCA1032183FA6D');
        $this->addSql('ALTER TABLE cliente_documento DROP pasta_documento_id');
    }
}
