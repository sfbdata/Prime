<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Plano de ativação de 7 dias (pedido do Farlei de 26/11/2026): as linhas dos dias 2 a 7 (`at2` a `at7`) em
 * `comercial_automacao`, desligadas e sem corte (`ligada_em` nulo), como a `dica`: o Master revê o texto e liga na
 * Central de envios. Só dados, sem mudança de esquema. O dia 1 é a boas-vindas do `val`, que já existe.
 */
final class Version20261126140000 extends AbstractMigration
{
    private const CHAVES = ['at2', 'at3', 'at4', 'at5', 'at6', 'at7'];

    public function getDescription(): string
    {
        return 'Comercial: automações dos dias 2 a 7 do plano de ativação, desligadas';
    }

    public function up(Schema $schema): void
    {
        foreach (self::CHAVES as $chave) {
            $this->addSql(
                'INSERT INTO comercial_automacao (chave, ligada, atraso, ligada_em) VALUES (:chave, false, NULL, NULL) ON CONFLICT (chave) DO NOTHING',
                ['chave' => $chave],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM comercial_automacao WHERE chave IN ('at2', 'at3', 'at4', 'at5', 'at6', 'at7')");
    }
}
