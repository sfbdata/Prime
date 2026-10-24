<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * "Mover para outra carteira" do menu ⋮ da pasta: a coluna `pasta.carteira` (enum `CarteiraDaPasta`, a área do
 * direito). Nula = "Sem carteira", o estado de todas as pastas que já existem: nada é preenchido para trás
 * (decisão do dono). Não confundir com a Carteira da Cobrança, que é outra tabela.
 */
final class Version20261020151500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pasta: coluna carteira (área do direito), nula para as pastas existentes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta ADD carteira VARCHAR(30) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta DROP carteira');
    }
}
