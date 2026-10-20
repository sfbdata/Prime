<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Contribua, boleto de apoio (chave "Boleto" do desenho 08, só com a Cora; spec docs/specs/contribua-boleto.md):
 * o boleto é uma cobrança da mesma tabela das cobranças PIX por conta. Colunas novas: `forma` (pix ou boleto;
 * as linhas que já existem ficam pix), a linha digitável e a URL do PDF. O vencimento e o Pix do boleto usam
 * as colunas que já existem (`vencimento`, `copia_e_cola`). Gerada pelo doctrine:migrations:diff e limpa: o
 * diff trazia também ruído antigo de outras áreas (messenger_messages, DEFAULT de cobranca_pessoa_* e índices
 * funcionais de cobrança), que não é desta frente. Horário reservado pela coordenação, depois da C4b
 * (Version20261021160000).
 */
final class Version20261021170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Contribua: boleto de apoio na tabela das cobranças por conta (forma, linha digitável, URL do PDF)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE contribua_cobranca_pix ADD forma VARCHAR(10) DEFAULT 'pix' NOT NULL");
        $this->addSql('ALTER TABLE contribua_cobranca_pix ADD linha_digitavel VARCHAR(60) DEFAULT NULL');
        $this->addSql('ALTER TABLE contribua_cobranca_pix ADD url_do_boleto TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contribua_cobranca_pix DROP forma');
        $this->addSql('ALTER TABLE contribua_cobranca_pix DROP linha_digitavel');
        $this->addSql('ALTER TABLE contribua_cobranca_pix DROP url_do_boleto');
    }
}
