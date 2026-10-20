<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Encaminhar via Chat I.A (`docs/specs/encaminhar-via-chat-ia.md` §3): a mensagem de despacho guarda a referência da
 * pasta (`despacho_pasta_id`, `ON DELETE SET NULL`) e o tipo do item (`despacho_tipo`), as duas nulas na mensagem
 * comum. A leitura confere, com elas, se quem lê ainda vê a pasta.
 *
 * SQL do `doctrine:schema:update --dump-sql` filtrado a `central_mensagem`: ficou de fora a divergência que já existia
 * antes desta entrega (DROP de `messenger_messages`, os DROP INDEX de índices funcionais de cobrança e os DROP DEFAULT
 * de `cobranca_pessoa_*`), que não é desta frente.
 */
final class Version20261021180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Central: referência da pasta e tipo do item no despacho (central_mensagem.despacho_pasta_id e despacho_tipo)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE central_mensagem ADD despacho_tipo VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE central_mensagem ADD despacho_pasta_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE central_mensagem ADD CONSTRAINT FK_74FC94E08602AA9C FOREIGN KEY (despacho_pasta_id) REFERENCES pasta (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_74FC94E08602AA9C ON central_mensagem (despacho_pasta_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE central_mensagem DROP CONSTRAINT FK_74FC94E08602AA9C');
        $this->addSql('DROP INDEX IDX_74FC94E08602AA9C');
        $this->addSql('ALTER TABLE central_mensagem DROP despacho_pasta_id');
        $this->addSql('ALTER TABLE central_mensagem DROP despacho_tipo');
    }
}
