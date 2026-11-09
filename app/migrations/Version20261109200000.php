<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ponto, etapa E6 (spec docs/specs/ponto-desenho-0811.md, P71 com o desvio da E6): abrangência do feriado.
 *
 * `feriado.lotacao_id` opcional. Vazia (todos os feriados que já existem), o feriado vale para todo o escritório, como
 * antes: nenhuma folha muda. Com uma lotação, vale só para quem tem aquela lotação no escritório do feriado.
 *
 * ON DELETE CASCADE: apagar a lotação apaga os feriados só dela. SET NULL faria esses feriados passarem a valer para
 * todo o escritório, e o saldo de quem nunca teve o feriado mudaria sem ninguém pedir.
 *
 * **Aditiva:** uma coluna nova, sem dado. O down tira a coluna (e com ela a abrangência dos feriados por lotação, que
 * voltam a valer para todos); não há dado anterior a restaurar.
 */
final class Version20261109200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ponto: abrangência do feriado por lotação (feriado.lotacao_id, apagada junto com a lotação)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE feriado ADD lotacao_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE feriado ADD CONSTRAINT FK_7D8640137E27C2B8 FOREIGN KEY (lotacao_id) REFERENCES lotacao (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_7D8640137E27C2B8 ON feriado (lotacao_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE feriado DROP CONSTRAINT FK_7D8640137E27C2B8');
        $this->addSql('DROP INDEX IDX_7D8640137E27C2B8');
        $this->addSql('ALTER TABLE feriado DROP lotacao_id');
    }
}
