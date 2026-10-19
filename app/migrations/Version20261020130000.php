<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P4h (spec docs/specs/push-editar-analise-ia.md): "Editar" a Análise IA do Push.
 *
 * A edição do advogado mora em colunas próprias, ao lado da saída do modelo (`resumo`, `pontos`,
 * `texto_bruto` continuam intactos). Só acrescenta colunas anuláveis: nenhuma linha existente muda.
 */
final class Version20261020130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P4h: colunas da edição da Análise IA (resumo/pontos editados, quem e quando)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inteligencia_analise ADD resumo_editado TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE inteligencia_analise ADD pontos_editados JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE inteligencia_analise ADD editada_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE inteligencia_analise ADD editada_por_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE inteligencia_analise ADD CONSTRAINT FK_641CD60D528F4056 FOREIGN KEY (editada_por_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_inteligencia_analise_editada_por ON inteligencia_analise (editada_por_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inteligencia_analise DROP CONSTRAINT FK_641CD60D528F4056');
        $this->addSql('DROP INDEX idx_inteligencia_analise_editada_por');
        $this->addSql('ALTER TABLE inteligencia_analise DROP resumo_editado');
        $this->addSql('ALTER TABLE inteligencia_analise DROP pontos_editados');
        $this->addSql('ALTER TABLE inteligencia_analise DROP editada_em');
        $this->addSql('ALTER TABLE inteligencia_analise DROP editada_por_id');
    }
}
