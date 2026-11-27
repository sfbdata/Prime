<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Contribua, lote 2 de 27/11/2026 (L2-M1): a chave "Ampliação de GB automática" do checkout nasce ligada no
 * espaço novo. Só o DEFAULT da coluna muda: as linhas que já existem ficam como estão, porque alguém escolheu
 * (decisão de 27/11/2026).
 *
 * **Aditiva:** nenhum dado é alterado nem apagado.
 */
final class Version20261127180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Contribua: ampliação de GB automática ligada por padrão no espaço novo';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contribua_espaco_do_escritorio ALTER ampliacao_automatica SET DEFAULT true');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contribua_espaco_do_escritorio ALTER ampliacao_automatica SET DEFAULT false');
    }
}
