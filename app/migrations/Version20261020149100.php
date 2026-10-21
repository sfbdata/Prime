<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Confirmação de leitura por campanha (F3g, spec comercial-e-emails §10; desenho 05 VS 21.10.26 L291): a escolha fica
 * gravada em cada campanha. As que já existem ficam sem confirmação (false), como foram enviadas: o rastreio global
 * está desligado até a Política citá-lo.
 */
final class Version20261020149100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Comercial: confirmação de leitura por campanha (comercial_campanha.rastrear_leitura)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comercial_campanha ADD rastrear_leitura BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comercial_campanha DROP rastrear_leitura');
    }
}
