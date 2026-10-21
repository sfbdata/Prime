<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D2 da spec `docs/specs/cadastro-senha-no-link.md`: a página "Crie sua senha" grava um aceite novo dos Termos e a
 * ciência da Política, sem apagar os do cadastro. Para isso o aceite ganha a origem (`cadastro`, `link_confirmacao`,
 * `reaceite`), e o índice único passa de (user_id, versao) para (user_id, versao, origem), nas duas tabelas.
 *
 * Os registros existentes ficam `cadastro` pelo DEFAULT da coluna, que fica também no mapeamento: o código antigo,
 * que ainda roda durante o deploy, continua gravando sem a coluna. O índice novo nasce antes de o velho sair, então
 * a tabela nunca fica sem índice único.
 *
 * Deploy MANUAL (decisão do coordenador): a migration tem DROP INDEX. A guarda do deploy automático
 * (scripts/vps/deploy-automatico.sh) aceita DROP INDEX, mas a regra da frente é o dono aplicar esta à mão.
 *
 * O down volta ao índice (user_id, versao): antes, apaga os registros repetidos da mesma versão, ficando o mais
 * antigo (o do cadastro). Rodar o down perde a prova do aceite dado no link.
 */
final class Version20261020149400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Aceite dos Termos e ciência da Política: coluna origem e índice único (user_id, versao, origem)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE aceite_termo ADD origem VARCHAR(20) DEFAULT 'cadastro' NOT NULL");
        $this->addSql('CREATE UNIQUE INDEX uq_aceite_termo_user_versao_origem ON aceite_termo (user_id, versao, origem)');
        $this->addSql('DROP INDEX uq_aceite_termo_user_versao');

        $this->addSql("ALTER TABLE ciencia_politica ADD origem VARCHAR(20) DEFAULT 'cadastro' NOT NULL");
        $this->addSql('CREATE UNIQUE INDEX uq_ciencia_politica_user_versao_origem ON ciencia_politica (user_id, versao, origem)');
        $this->addSql('DROP INDEX uq_ciencia_politica_user_versao');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM aceite_termo a USING aceite_termo b WHERE a.user_id = b.user_id AND a.versao = b.versao AND a.id > b.id');
        $this->addSql('CREATE UNIQUE INDEX uq_aceite_termo_user_versao ON aceite_termo (user_id, versao)');
        $this->addSql('DROP INDEX uq_aceite_termo_user_versao_origem');
        $this->addSql('ALTER TABLE aceite_termo DROP origem');

        $this->addSql('DELETE FROM ciencia_politica a USING ciencia_politica b WHERE a.user_id = b.user_id AND a.versao = b.versao AND a.id > b.id');
        $this->addSql('CREATE UNIQUE INDEX uq_ciencia_politica_user_versao ON ciencia_politica (user_id, versao)');
        $this->addSql('DROP INDEX uq_ciencia_politica_user_versao_origem');
        $this->addSql('ALTER TABLE ciencia_politica DROP origem');
    }
}
