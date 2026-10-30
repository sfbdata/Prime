<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Dá o código da conta ("BJ-XXXX-XXXX-XXXX") aos escritórios que ainda não têm, para o chip e a busca por código da
 * lista de "Contas e escritórios" do Master (desenho 05 VS 30.10.26). Só dados: a coluna e o índice único parcial já
 * existem. Cada código é sorteado (random_int, nada sequencial), 12 caracteres do alfabeto sem I, O, 0 e 1, e conferido
 * contra os já gravados e os sorteados nesta rodada. Idempotente: só toca onde codigo_conta é NULL. Tem UPDATE: o
 * deploy automático para nesta migration e o dono segue à mão.
 */
final class Version20261030100000 extends AbstractMigration
{
    private const ALFABETO = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function getDescription(): string
    {
        return 'Preenche o codigo_conta dos escritórios que ainda não têm (backfill único e não sequencial)';
    }

    public function up(Schema $schema): void
    {
        $usados = array_flip($this->connection->fetchFirstColumn('SELECT codigo_conta FROM tenant WHERE codigo_conta IS NOT NULL'));
        foreach ($this->connection->fetchFirstColumn('SELECT id FROM tenant WHERE codigo_conta IS NULL ORDER BY id') as $id) {
            do {
                $codigo = $this->sortear();
            } while (isset($usados[$codigo]));
            $usados[$codigo] = true;
            $this->addSql('UPDATE tenant SET codigo_conta = ? WHERE id = ? AND codigo_conta IS NULL', [$codigo, $id]);
        }
    }

    public function down(Schema $schema): void
    {
        // Sem desfazer de propósito: o código pode já ter sido mostrado ao cliente e usado em busca e suporte; apagar
        // geraria outro diferente na próxima consulta. A coluna continua nula-permitida e o sistema gera sob demanda.
    }

    private function sortear(): string
    {
        $grupos = [];
        for ($g = 0; $g < 3; ++$g) {
            $grupo = '';
            for ($i = 0; $i < 4; ++$i) {
                $grupo .= self::ALFABETO[random_int(0, \strlen(self::ALFABETO) - 1)];
            }
            $grupos[] = $grupo;
        }

        return 'BJ-' . implode('-', $grupos);
    }
}
