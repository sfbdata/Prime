<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Início da contagem dos 30 dias da lixeira (decisão do Farlei, 03/11/2026): o que já estava na
 * lixeira antes do deploy da purga agendada conta os 30 dias a partir de agora, a hora em que esta
 * migration roda, no fuso de São Paulo (o mesmo do PHP que grava `excluido_em`). Uma linha só
 * (id 1); ver `App\Pasta\Entity\InicioDaPurgaDaLixeira`.
 */
final class Version20261103170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tabela lixeira_purga_inicio: a lixeira começa a contar os 30 dias no deploy';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE lixeira_purga_inicio (id INT NOT NULL, inicia_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql("INSERT INTO lixeira_purga_inicio (id, inicia_em) VALUES (1, DATE_TRUNC('second', NOW() AT TIME ZONE 'America/Sao_Paulo'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE lixeira_purga_inicio');
    }
}
