<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Liberacao;

use App\Shared\EspelhoRemoto\Reconciliacao\ReferenciaDeArquivo;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * O que o banco guarda, na linha que referencia o arquivo, para o critério da liberação (spec
 * `leitura-pelo-r2-e-liberacao-de-disco.md` §5.2): a data (o "criado há mais de N dias" do frio) e se a
 * linha está na lixeira (documento na lixeira nunca é candidato: a purga dos 30 dias vai apagá-lo).
 *
 * Só as fontes com data entram aqui. Documento da pasta: o mais novo entre o envio (`uploaded_at`) e a
 * última modificação (`modificado_em`), para um documento mexido há pouco não contar como frio. Fonte
 * sem data: nada, e quem chama usa o `mtime` do arquivo.
 *
 * Consulta só as linhas pedidas, em blocos de {@see BLOCO} ids, e nunca a tabela inteira. Por DBAL, sem
 * filtro de escritório nem de lixeira (a lixeira é a resposta): a chave da referência já traz o
 * escritório da própria linha. As colunas são `TIMESTAMP WITHOUT TIME ZONE` no fuso da aplicação; a
 * diferença de horas não pesa num critério de dias.
 */
final class DatasDasReferencias
{
    private const BLOCO = 1000;

    /** @var array<string, string> fonte => SQL (id, data, na_lixeira) com o parâmetro :ids */
    private const FONTES_COM_DATA = [
        'pasta_documento' => 'SELECT id, GREATEST(uploaded_at, COALESCE(modificado_em, uploaded_at)) AS data, (excluido_em IS NOT NULL) AS na_lixeira FROM pasta_documento WHERE id IN (:ids)',
        // Documento da Assinatura: a última alteração; na Lixeira fica fora da liberação, como o da pasta.
        'assinatura_documento_arquivo' => 'SELECT id, alterado_em AS data, (excluido_em IS NOT NULL) AS na_lixeira FROM assinatura_eletronica_documento WHERE id IN (:ids)',
        'assinatura_documento_logo' => 'SELECT id, alterado_em AS data, (excluido_em IS NOT NULL) AS na_lixeira FROM assinatura_eletronica_documento WHERE id IN (:ids)',
        // O PDF final nasce depois do último salvamento (na conclusão ou no 1º download): sem data no banco, vale a do disco.
        'assinatura_documento_final' => 'SELECT id, NULL AS data, (excluido_em IS NOT NULL) AS na_lixeira FROM assinatura_eletronica_documento WHERE id IN (:ids)',
        // A versão do PDF (etapa 5) nunca muda depois de gravada: a data é a da criação; a Lixeira é a do documento.
        'assinatura_versao' => 'SELECT v.id, v.criado_em AS data, (d.excluido_em IS NOT NULL) AS na_lixeira FROM assinatura_eletronica_versao v JOIN assinatura_eletronica_documento d ON d.id = v.documento_id WHERE v.id IN (:ids)',
    ];

    public function __construct(
        private readonly Connection $conexao,
    ) {
    }

    /**
     * @param list<ReferenciaDeArquivo> $referencias
     *
     * @return array<string, array{data: ?\DateTimeImmutable, naLixeira: bool}> chave {@see chaveDe()} => o que o banco diz
     */
    public function para(array $referencias): array
    {
        $idsPorFonte = [];
        foreach ($referencias as $referencia) {
            if (isset(self::FONTES_COM_DATA[$referencia->fonte])) {
                $idsPorFonte[$referencia->fonte][] = $referencia->idDaLinha;
            }
        }

        $respostas = [];
        foreach ($idsPorFonte as $fonte => $ids) {
            foreach (array_chunk(array_values(array_unique($ids)), self::BLOCO) as $bloco) {
                foreach ($this->conexao->fetchAllAssociative(self::FONTES_COM_DATA[$fonte], ['ids' => $bloco], ['ids' => ArrayParameterType::INTEGER]) as $linha) {
                    $respostas[self::chaveDe($fonte, (int) $linha['id'])] = [
                        'data'      => $linha['data'] === null ? null : new \DateTimeImmutable((string) $linha['data']),
                        'naLixeira' => (bool) $linha['na_lixeira'],
                    ];
                }
            }
        }

        return $respostas;
    }

    public static function chaveDe(string $fonte, int $id): string
    {
        return $fonte . '#' . $id;
    }
}
