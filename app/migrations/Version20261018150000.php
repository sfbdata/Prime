<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Central: "Excluir" uma conversa pelo botão direito = limpar PARA MIM. A participação guarda até que mensagem a
 * pessoa limpou; nada é apagado e o colega segue vendo tudo. Coluna nova com padrão 0 (nada limpo), nada existente muda.
 */
final class Version20261018150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Central: central_participante.limpa_ate_id (limpar conversa só para mim)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE central_participante ADD limpa_ate_id INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE central_participante DROP limpa_ate_id');
    }
}
