<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Voto, portal do eleitor por nome e CPF e nomes dos campos da lista: `voto.assembleia_unidade.cpf` (só dígitos, opcional)
 * e `voto.assembleia.rotulo_unidade` / `rotulo_bloco` (NULL = "Unidade" / "Bloco"). Colunas novas e opcionais: nenhuma
 * linha existente muda e o portal segue como era enquanto nenhuma unidade tiver CPF.
 */
final class Version20261119130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Voto: CPF opcional na unidade e nomes personalizados dos campos Unidade e Bloco na assembleia.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voto.assembleia_unidade ADD cpf VARCHAR(11) DEFAULT NULL');
        $this->addSql('ALTER TABLE voto.assembleia ADD rotulo_unidade VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE voto.assembleia ADD rotulo_bloco VARCHAR(40) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voto.assembleia DROP rotulo_bloco');
        $this->addSql('ALTER TABLE voto.assembleia DROP rotulo_unidade');
        $this->addSql('ALTER TABLE voto.assembleia_unidade DROP cpf');
    }
}
