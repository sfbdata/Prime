<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cadastro do cliente na pasta (desenho 1.2.3, E12f fatia 3a): número, bairro e WhatsApp no cliente; nacionalidade
 * e gênero na pessoa física; nome completo de 50 para 150 caracteres. Só colunas novas anuláveis e um alargamento:
 * nada existente muda.
 */
final class Version20261016234500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'E12f: numero, bairro, whatsapp em cliente; nacionalidade, genero e nome_completo(150) em cliente_pf';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cliente ADD numero VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE cliente ADD bairro VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE cliente ADD whatsapp VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE cliente_pf ADD nacionalidade VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE cliente_pf ADD genero VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE cliente_pf ALTER nome_completo TYPE VARCHAR(150)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cliente DROP numero');
        $this->addSql('ALTER TABLE cliente DROP bairro');
        $this->addSql('ALTER TABLE cliente DROP whatsapp');
        $this->addSql('ALTER TABLE cliente_pf DROP nacionalidade');
        $this->addSql('ALTER TABLE cliente_pf DROP genero');
        $this->addSql('ALTER TABLE cliente_pf ALTER nome_completo TYPE VARCHAR(50)');
    }
}
