<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Comercial F4, datas comemorativas (docs/specs/comercial-e-emails.md §13; desenho 05 VS 21.10.26 L678-682): a tabela
 * `comercial_data_comemorativa`, uma linha por data, semeada com as 17 do dc no padrão dele (ligada, no dia, para
 * Todos). Semear não dispara nada: a chave geral `COMERCIAL_CAMPANHAS_LIGADAS` nasce desligada e segura o envio.
 *
 * Escrita à mão, com os nomes do índice e da FK que o mapeamento gera (o diff traria ruído de outras frentes).
 */
final class Version20261020149800 extends AbstractMigration
{
    private const DATAS = [
        'ano', 'carnaval', 'mulher', 'pascoa', 'tiradentes', 'trabalho', 'maes', 'namorados', 'pais', 'advogado',
        'independencia', 'cliente', 'criancas', 'finados', 'consciencia', 'natal', 'reveillon',
    ];

    public function getDescription(): string
    {
        return 'Comercial F4: datas comemorativas (estado das 17 datas, semeadas ligadas, no dia, para Todos)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE comercial_data_comemorativa (chave VARCHAR(20) NOT NULL, ligada BOOLEAN NOT NULL, antecedencia SMALLINT NOT NULL, publico VARCHAR(10) NOT NULL, alterada_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, alterada_por_id INT DEFAULT NULL, PRIMARY KEY (chave))');
        $this->addSql('CREATE INDEX IDX_6CA0EC2A58CC7A25 ON comercial_data_comemorativa (alterada_por_id)');
        $this->addSql('ALTER TABLE comercial_data_comemorativa ADD CONSTRAINT FK_6CA0EC2A58CC7A25 FOREIGN KEY (alterada_por_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        foreach (self::DATAS as $chave) {
            $this->addSql("INSERT INTO comercial_data_comemorativa (chave, ligada, antecedencia, publico) VALUES ('" . $chave . "', true, 0, 'todos')");
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comercial_data_comemorativa DROP CONSTRAINT FK_6CA0EC2A58CC7A25');
        $this->addSql('DROP TABLE comercial_data_comemorativa');
    }
}
