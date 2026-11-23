<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Modelos editáveis (pedido M5.8 + M5.12 do Farlei; spec `comercial-e-emails.md` §8.2 e §13): a versão do Master de um
 * modelo padrão do sistema vira uma linha de `comercial_modelo` com `fixo_chave` (única por chave), e a data
 * comemorativa ganha o modelo escolhido (`modelo_chave`, nulo = o padrão da data). Aditiva e sem backfill.
 */
final class Version20261123200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Comercial: versão editada dos modelos padrão (fixo_chave) e modelo escolhido por data comemorativa.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comercial_modelo ADD fixo_chave VARCHAR(40) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_comercial_modelo_fixo_chave ON comercial_modelo (fixo_chave)');
        $this->addSql('ALTER TABLE comercial_data_comemorativa ADD modelo_chave VARCHAR(40) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comercial_data_comemorativa DROP modelo_chave');
        $this->addSql('DROP INDEX uniq_comercial_modelo_fixo_chave');
        $this->addSql('ALTER TABLE comercial_modelo DROP fixo_chave');
    }
}
