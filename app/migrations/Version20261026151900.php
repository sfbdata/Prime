<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recompensas do Master, F1 (spec docs/specs/perfis-1.2-vs-25-10-recompensas-e-planos.md): duas tabelas GLOBAIS, sem
 * tenant. `programa_de_recompensa` é a regra geral (linha única, id 1; sem a linha valem os padrões da entidade, então
 * nada é semeado). `condicao_de_recompensa` é a condição individual de um indicador (PK = user_id, apagada junto com o
 * usuário); campo nulo usa a regra geral. Dinheiro em centavos inteiros. Nada de saldo é gravado: o cálculo é na leitura.
 */
final class Version20261026151900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recompensas F1: tabelas programa_de_recompensa (linha única) e condicao_de_recompensa (por indicador)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE programa_de_recompensa (id INT NOT NULL, ativo BOOLEAN DEFAULT true NOT NULL, valor_centavos INT DEFAULT 1000 NOT NULL, min_escritorios INT DEFAULT 10 NOT NULL, carencia_dias INT DEFAULT 30 NOT NULL, alerta_qtd INT DEFAULT 5 NOT NULL, exige_pasta BOOLEAN DEFAULT true NOT NULL, alterado_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, alterado_por_id INT DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT chk_programa_de_recompensa_linha_unica CHECK (id = 1))');
        $this->addSql('CREATE INDEX idx_programa_de_recompensa_alterado_por ON programa_de_recompensa (alterado_por_id)');
        $this->addSql('ALTER TABLE programa_de_recompensa ADD CONSTRAINT FK_PROGRAMA_DE_RECOMPENSA_USER FOREIGN KEY (alterado_por_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');

        $this->addSql('CREATE TABLE condicao_de_recompensa (user_id INT NOT NULL, valor_centavos INT DEFAULT NULL, min_escritorios INT DEFAULT NULL, carencia_dias INT DEFAULT NULL, recorrente BOOLEAN DEFAULT false NOT NULL, mensal_centavos INT DEFAULT NULL, motivo VARCHAR(255) DEFAULT NULL, alterada_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, alterada_por_id INT DEFAULT NULL, PRIMARY KEY (user_id))');
        $this->addSql('CREATE INDEX idx_condicao_de_recompensa_alterada_por ON condicao_de_recompensa (alterada_por_id)');
        $this->addSql('ALTER TABLE condicao_de_recompensa ADD CONSTRAINT FK_CONDICAO_DE_RECOMPENSA_INDICADOR FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE condicao_de_recompensa ADD CONSTRAINT FK_CONDICAO_DE_RECOMPENSA_ALTERADA_POR FOREIGN KEY (alterada_por_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE condicao_de_recompensa');
        $this->addSql('DROP TABLE programa_de_recompensa');
    }
}
