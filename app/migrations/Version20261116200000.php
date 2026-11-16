<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ciclo de vida do Voto (redesign de 14 e 15/11): na pauta, suspensão, cancelamento e retirada de pauta; na
 * assembleia, arquivamento. Cada um guarda quando, o motivo e quem. Todas as colunas são opcionais: linha antiga fica
 * como estava.
 */
final class Version20261116200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona suspensão, cancelamento e retirada à pauta e arquivamento à assembleia do Voto.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voto.assembleia_pauta ADD suspensa_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, ADD suspensa_motivo VARCHAR(200) DEFAULT NULL, ADD suspensa_por_nome VARCHAR(180) DEFAULT NULL, ADD cancelada_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, ADD cancelada_motivo VARCHAR(200) DEFAULT NULL, ADD cancelada_por_nome VARCHAR(180) DEFAULT NULL, ADD retirada_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, ADD retirada_motivo VARCHAR(200) DEFAULT NULL, ADD retirada_por_nome VARCHAR(180) DEFAULT NULL');
        $this->addSql('ALTER TABLE voto.assembleia ADD arquivada_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, ADD arquivada_motivo VARCHAR(200) DEFAULT NULL, ADD arquivada_por_nome VARCHAR(180) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voto.assembleia_pauta DROP suspensa_em, DROP suspensa_motivo, DROP suspensa_por_nome, DROP cancelada_em, DROP cancelada_motivo, DROP cancelada_por_nome, DROP retirada_em, DROP retirada_motivo, DROP retirada_por_nome');
        $this->addSql('ALTER TABLE voto.assembleia DROP arquivada_em, DROP arquivada_motivo, DROP arquivada_por_nome');
    }
}
