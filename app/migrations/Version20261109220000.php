<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ponto Eletrônico, etapa E13 (spec docs/specs/ponto-desenho-0811.md §5 e §6, `ponto_batida` = `registro_ponto`): a
 * batida guarda a hora do aparelho, a hora em que chegou ao servidor, a marca "enviada sem internet" (usada pela E14)
 * e o identificador do envio feito pelo navegador.
 *
 * O índice único (user_id, id_envio) barra a segunda gravação do mesmo envio, inclusive quando dois pedidos chegam ao
 * mesmo tempo. As batidas sem identificador ficam com NULL, que o PostgreSQL deixa repetir no índice único.
 *
 * Sem backfill: as batidas anteriores ficam com as horas nulas e sem a marca.
 */
final class Version20261109220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ponto E13: hora do aparelho, hora de chegada, marca de envio sem internet e identificador único do envio na batida';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE registro_ponto ADD hora_aparelho TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE registro_ponto ADD hora_chegada TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE registro_ponto ADD enviada_sem_internet BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE registro_ponto ADD id_envio VARCHAR(36) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_registro_ponto_user_envio ON registro_ponto (user_id, id_envio)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_registro_ponto_user_envio');
        $this->addSql('ALTER TABLE registro_ponto DROP id_envio');
        $this->addSql('ALTER TABLE registro_ponto DROP enviada_sem_internet');
        $this->addSql('ALTER TABLE registro_ponto DROP hora_chegada');
        $this->addSql('ALTER TABLE registro_ponto DROP hora_aparelho');
    }
}
