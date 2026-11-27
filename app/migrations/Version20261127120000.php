<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Central de envios do Master, M1c (pedido do Farlei de 25/11/2026; os três campos aprovados pelo Samuel em 27/11/2026):
 *
 *   - `comercial_campanha.base_legal` (C1): a base legal que o Master declara ao confirmar. Nula nas campanhas de antes,
 *     nos testes e nas das datas comemorativas; a tela e o conector passam a exigi-la na campanha nova.
 *   - `comercial_campanha.central` (D5): de qual Central saiu (`comercial` ou `indicacoes`); o Relatório de cada seção
 *     lista só as dela.
 *   - `texto_sem_nome` em `comercial_modelo` e na cópia congelada da campanha (A6): o que entra no `{nome}` quando o
 *     destinatário não tem nome, "colega" por padrão (o texto de antes).
 *
 * As campanhas de antes: as duas Centrais gravam pela mesma rota e nada na linha diz de qual seção veio. A da seção
 * Indicações (o modo venda) só aceita os públicos `leads`, `csv` e `indiv`, os modelos `convite`, `vagas`, `news`,
 * `novidades` ou um próprio (`cu<id>`) e o filtro só com a busca e o Estado. A que foge disso só pode ter saído da
 * Comercial e fica `comercial`; a que cabe nas duas fica sem Central (nula) e continua aparecendo nas duas seções, como
 * antes. As das datas comemorativas são do sistema e ficam `comercial`.
 *
 * Gerada pelo `doctrine:schema:update --dump-sql` e limpa: só os quatro ALTER desta frente entraram.
 */
final class Version20261127120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Central de envios: base legal e Central de origem da campanha; texto quando não houver nome no modelo.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comercial_campanha ADD base_legal VARCHAR(30) DEFAULT NULL');
        $this->addSql("ALTER TABLE comercial_campanha ADD central VARCHAR(12) DEFAULT 'comercial'");
        $this->addSql("ALTER TABLE comercial_campanha ADD texto_sem_nome VARCHAR(40) DEFAULT 'colega' NOT NULL");
        $this->addSql("ALTER TABLE comercial_modelo ADD texto_sem_nome VARCHAR(40) DEFAULT 'colega' NOT NULL");
        $this->addSql(
            "UPDATE comercial_campanha SET central = NULL
             WHERE chave_idempotencia NOT LIKE 'data:%'
               AND publico IN ('leads', 'csv', 'indiv', 'teste')
               AND (modelo_chave IN ('convite', 'vagas', 'news', 'novidades') OR modelo_chave ~ '^cu[1-9][0-9]*$')
               AND NOT COALESCE(CASE WHEN json_typeof(publico_parametros) = 'object' AND json_typeof(publico_parametros -> 'filtro') = 'object'
                   THEN EXISTS (SELECT 1 FROM json_object_keys(publico_parametros -> 'filtro') AS k(chave) WHERE k.chave NOT IN ('q', 'uf'))
                   ELSE false END, false)"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comercial_modelo DROP texto_sem_nome');
        $this->addSql('ALTER TABLE comercial_campanha DROP texto_sem_nome');
        $this->addSql('ALTER TABLE comercial_campanha DROP central');
        $this->addSql('ALTER TABLE comercial_campanha DROP base_legal');
    }
}
