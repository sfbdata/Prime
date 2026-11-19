<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Suporte: `mensagem_suporte.respondida_em` (NULL = pendente). Coluna nova e opcional: as mensagens que já existem
 * entram como pendentes e contam no item "Suporte" do menu até alguém marcar a resposta.
 */
final class Version20261119140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Suporte: data da resposta de cada mensagem (pendente quando nula).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mensagem_suporte ADD respondida_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mensagem_suporte DROP respondida_em');
    }
}
