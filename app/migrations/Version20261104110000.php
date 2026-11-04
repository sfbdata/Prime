<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Contribua, desenho 08 VS 03.11.26 (spec `docs/specs/contribua-planos-cora.md`, "Estado da frente"):
 *
 * - `contribua_assinatura.multiplicador` e `contribua_espaco_do_escritorio.multiplicador`: os botões + e −
 *   do maior pacote dobram o espaço e o valor (×2, ×4...). 1 = o pacote como está no catálogo.
 * - `contribua_espaco_do_escritorio.ampliacao_automatica`: a chave "Ampliação de GB automática", por
 *   escritório. Só é gravada aqui; a dobra automática em si é o PR seguinte.
 * - `programa_de_recompensa.validade_desde`: o crédito de indicação expira em 3 meses. O saldo que já
 *   existe conta os 3 meses a partir desta migration (o deploy); o crédito novo, da data em que foi
 *   ganho. A linha única do programa nasce aqui se ainda não existe (em produção não havia nenhuma),
 *   com os valores padrão das colunas, para a data ficar gravada.
 *
 * **Aditiva:** três colunas com padrão e uma nula; nada é apagado.
 */
final class Version20261104110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Contribua VS 03.11: multiplicador do pacote, ampliação automática e validade do crédito de indicação';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contribua_assinatura ADD multiplicador INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE contribua_espaco_do_escritorio ADD multiplicador INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE contribua_espaco_do_escritorio ADD ampliacao_automatica BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE programa_de_recompensa ADD validade_desde TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('INSERT INTO programa_de_recompensa (id, alterado_em, validade_desde) VALUES (1, NOW(), NOW()) ON CONFLICT (id) DO UPDATE SET validade_desde = NOW()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE programa_de_recompensa DROP validade_desde');
        $this->addSql('ALTER TABLE contribua_espaco_do_escritorio DROP ampliacao_automatica');
        $this->addSql('ALTER TABLE contribua_espaco_do_escritorio DROP multiplicador');
        $this->addSql('ALTER TABLE contribua_assinatura DROP multiplicador');
    }
}
