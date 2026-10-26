<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Trabalho Externo, aba Pagamento (spec `docs/specs/trabalho-externo-25-10-26.md`):
 * `trabalho_externo_documento.comprovante` marca o comprovante de pagamento PIX que o solicitante envia.
 * O comprovante é um documento da demanda que fica fora da lista DOCUMENTOS; as linhas que já existem não são.
 *
 * Só o desta frente: o resto do `doctrine:schema:update --dump-sql` já divergia antes dela.
 */
final class Version20261024150700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Trabalho Externo: marca de comprovante de pagamento no documento da demanda';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trabalho_externo_documento ADD comprovante BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trabalho_externo_documento DROP comprovante');
    }
}
