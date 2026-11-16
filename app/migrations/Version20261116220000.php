<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Painel do Voto por entidade (redesign de 14 e 15/11): a assembleia ganha o nome da entidade e o CNPJ (14 dígitos, sem
 * máscara), ambos opcionais. Linha antiga fica sem entidade, como o desenho trata ("Sem entidade informada").
 */
final class Version20261116220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona o nome da entidade e o CNPJ à assembleia do Voto.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voto.assembleia ADD entidade_nome VARCHAR(160) DEFAULT NULL');
        $this->addSql('ALTER TABLE voto.assembleia ADD cnpj VARCHAR(14) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voto.assembleia DROP entidade_nome');
        $this->addSql('ALTER TABLE voto.assembleia DROP cnpj');
    }
}
