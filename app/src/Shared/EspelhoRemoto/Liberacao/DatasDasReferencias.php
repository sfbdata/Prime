<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Liberacao;

use App\Shared\EspelhoRemoto\Reconciliacao\ReferenciaDeArquivo;
use Doctrine\DBAL\Connection;

/**
 * A data que o banco guarda para a linha que referencia o arquivo: é o "criado há mais de N dias" do
 * critério de frio da liberação (spec `leitura-pelo-r2-e-liberacao-de-disco.md` §5.2, item 3).
 *
 * Só as fontes com data entram aqui. Documento da pasta: o mais novo entre o envio (`uploaded_at`) e a
 * última modificação (`modificado_em`), para um documento mexido há pouco não contar como frio. Fonte
 * sem data: null, e quem chama usa o `mtime` do arquivo.
 *
 * Por DBAL, sem filtro de escritório nem de lixeira, como a {@see \App\Shared\EspelhoRemoto\Reconciliacao\FontesDeReferencia}:
 * a chave da referência já traz o escritório da própria linha. As colunas são `TIMESTAMP WITHOUT TIME
 * ZONE` gravadas no fuso da aplicação; a diferença de horas não pesa num critério de dias.
 */
final class DatasDasReferencias
{
    /** @var array<string, string> fonte => SQL (id, data) */
    private const FONTES_COM_DATA = [
        'pasta_documento' => 'SELECT id, GREATEST(uploaded_at, COALESCE(modificado_em, uploaded_at)) AS data FROM pasta_documento',
    ];

    /** @var array<string, array<int, string>> fonte => id => data */
    private array $carregadas = [];

    public function __construct(
        private readonly Connection $conexao,
    ) {
    }

    public function dataDe(ReferenciaDeArquivo $referencia): ?\DateTimeImmutable
    {
        $sql = self::FONTES_COM_DATA[$referencia->fonte] ?? null;
        if ($sql === null) {
            return null;
        }

        if (!isset($this->carregadas[$referencia->fonte])) {
            $datas = [];
            foreach ($this->conexao->iterateAssociative($sql) as $linha) {
                if ($linha['data'] !== null) {
                    $datas[(int) $linha['id']] = (string) $linha['data'];
                }
            }
            $this->carregadas[$referencia->fonte] = $datas;
        }

        $data = $this->carregadas[$referencia->fonte][$referencia->idDaLinha] ?? null;

        return $data === null ? null : new \DateTimeImmutable($data);
    }
}
