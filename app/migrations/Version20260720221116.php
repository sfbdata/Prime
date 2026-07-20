<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Encargos "ao vivo" (F3): adiciona `liquidada_em` à obrigação — a data em que a dívida foi QUITADA.
 * Quando preenchida, o relógio dos encargos parou (snapshot na liquidação, spec §4/§5). Coluna
 * nullable, sem default de negócio: obrigação existente nasce NULL (Viva/aberta), o que preserva o
 * comportamento atual. Nada mais é tocado.
 */
final class Version20260720221116 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona cobranca_obrigacao.liquidada_em (data da quitacao; snapshot dos encargos ao vivo)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cobranca_obrigacao ADD liquidada_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cobranca_obrigacao DROP liquidada_em');
    }
}
