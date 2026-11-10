<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ponto Eletrônico, etapa E12 (spec docs/specs/ponto-desenho-0811.md §5 e §6, P18 e P74): a batida fora do raio das
 * sedes pode entrar como trabalho externo.
 *
 * Em `registro_ponto` (`ponto_batida` na spec): a marca `trabalho_externo` (padrão falso) e o local e motivo que a pessoa
 * escreveu. Em `justificativa_ponto` (`ponto_justificativa`): a batida que a justificativa `trabalho_externo` analisa,
 * opcional. A batida apagada solta a justificativa (ON DELETE SET NULL); o índice serve a busca pela batida.
 *
 * Sem backfill: as batidas e as justificativas anteriores ficam sem a marca e sem batida ligada.
 */
final class Version20261109230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ponto E12: marca e motivo de trabalho externo na batida e a batida ligada na justificativa';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE registro_ponto ADD trabalho_externo BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE registro_ponto ADD motivo_trabalho_externo TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE justificativa_ponto ADD registro_ponto_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE justificativa_ponto ADD CONSTRAINT FK_211D30B6DFB72B6F FOREIGN KEY (registro_ponto_id) REFERENCES registro_ponto (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_justificativa_ponto_registro_ponto ON justificativa_ponto (registro_ponto_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_justificativa_ponto_registro_ponto');
        $this->addSql('ALTER TABLE justificativa_ponto DROP CONSTRAINT FK_211D30B6DFB72B6F');
        $this->addSql('ALTER TABLE justificativa_ponto DROP registro_ponto_id');
        $this->addSql('ALTER TABLE registro_ponto DROP motivo_trabalho_externo');
        $this->addSql('ALTER TABLE registro_ponto DROP trabalho_externo');
    }
}
