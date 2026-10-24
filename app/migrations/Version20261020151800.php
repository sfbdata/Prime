<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Aba "Configurações da I.A." do Painel do Master (spec `docs/specs/ia-anthropic-ligar.md` §10, decisões do dono de
 * 24/10/2026):
 *
 * - `inteligencia_plataforma`: linha ÚNICA (id 1) com a chave geral (`ligada_para_todos`, nasce DESLIGADA) e o modelo
 *   escolhido pelo Master (nasce `claude-haiku-5-5`) e os meses de retenção das respostas da IA (nasce 12; a tarefa
 *   de expiração, de outro PR, lê daqui). Global, sem tenant. Quem alterou vira NULL se o usuário for
 *   apagado (SET NULL), para não travar a purga. A chave da API não mora aqui: fica só no `.env.prod`.
 * - `inteligencia_configuracao`: por escritório, a liberação do Master (`liberada_pelo_master`, nasce false: nenhum
 *   escritório passa a usar a IA com esta migration), o modo de cobrança (nasce `incluso`) e o orçamento mensal em
 *   centavos de dólar (NULL = sem orçamento).
 *
 * Nada de escritório é preenchido para trás além dos padrões das colunas.
 */
final class Version20261020151800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BlueJus IA: configuração da plataforma (chave geral, modelo e retenção) e liberação, cobrança e orçamento por escritório';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE inteligencia_plataforma (id INT NOT NULL, ligada_para_todos BOOLEAN DEFAULT false NOT NULL, modelo VARCHAR(40) DEFAULT 'claude-haiku-5-5' NOT NULL, meses_de_retencao INT DEFAULT 12 NOT NULL, alterada_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, alterada_por_id INT DEFAULT NULL, PRIMARY KEY (id))");
        $this->addSql('CREATE INDEX idx_inteligencia_plataforma_alterada_por ON inteligencia_plataforma (alterada_por_id)');
        $this->addSql('ALTER TABLE inteligencia_plataforma ADD CONSTRAINT FK_102CE6EB58CC7A25 FOREIGN KEY (alterada_por_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql("INSERT INTO inteligencia_plataforma (id, ligada_para_todos, modelo, meses_de_retencao, alterada_em) VALUES (1, false, 'claude-haiku-5-5', 12, NOW())");
        $this->addSql('ALTER TABLE inteligencia_configuracao ADD liberada_pelo_master BOOLEAN DEFAULT false NOT NULL');
        $this->addSql("ALTER TABLE inteligencia_configuracao ADD modo_de_cobranca VARCHAR(20) DEFAULT 'incluso' NOT NULL");
        $this->addSql('ALTER TABLE inteligencia_configuracao ADD orcamento_mensal_centavos INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inteligencia_configuracao DROP orcamento_mensal_centavos');
        $this->addSql('ALTER TABLE inteligencia_configuracao DROP modo_de_cobranca');
        $this->addSql('ALTER TABLE inteligencia_configuracao DROP liberada_pelo_master');
        $this->addSql('DROP TABLE inteligencia_plataforma');
    }
}
