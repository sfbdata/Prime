<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Onda 2 do redesign do Voto (etapa W2-4): a assembleia pode ser marcada como simulação (treino da mesa e teste do link).
 * Simulação pode ser reiniciada e apagada e não entra nas estatísticas. Padrão falso: as assembleias que já existem
 * continuam reais, e a marca só entra no hash da configuração quando verdadeira (o código das já publicadas não muda).
 */
final class Version20261117030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona a marca de simulação à assembleia do Voto.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voto.assembleia ADD simulacao BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voto.assembleia DROP simulacao');
    }
}
