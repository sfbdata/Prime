<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Processo\Service\PrevisaoProcessual;

/**
 * A fase do processo para os documentos sugeridos, lida da ÚLTIMA movimentação do Push (desenho 02,
 * `bj-docsug.js` `faseDe`, que lê a fase do processo antes da classe). Regra, não IA nem leitura de documento:
 * reaproveita a "Situação atual" do painel do Push (`PrevisaoProcessual`, as regras do CPC sobre o texto da
 * movimentação) e traduz a situação na fase do catálogo de documentos.
 *
 * Sem movimentação, ou com situação que não diz fase (decisão interlocutória, prazo do texto), devolve null e a
 * sugestão continua pela classe processual, como antes.
 */
final class FaseDoProcessoPeloPush
{
    private const LIMITE_MOVIMENTACOES = 100;

    /** Situação da `PrevisaoProcessual` (texto da regra) => fase do `CatalogoDeDocumentos`. */
    private const FASE_POR_SITUACAO = [
        'Prazo para contestação' => 'contestacao',
        'Aguardando intimação para réplica' => 'replica',
        'Prazo para réplica' => 'replica',
        // `faseDe`: "conclusos" sem sentença ainda é a réplica (dc bj-docsug.js L57).
        'Concluso ao juízo' => 'replica',
        'Análise judicial após a réplica' => 'provas',
        'Prazo para especificar provas' => 'provas',
        'Prazo para esclarecimentos do saneamento' => 'provas',
        'Audiência designada' => 'audiencia',
        'Prazo recursal' => 'sentenca',
        'Prazo para contrarrazões' => 'recurso',
        'Fase de cumprimento' => 'cumprimento',
    ];

    public function __construct(
        private readonly PublicacaoDjenRepository $publicacoes,
        private readonly PrevisaoProcessual $previsao,
    ) {
    }

    /** @return array{fase: string, situacao: string}|null */
    public function daPasta(Pasta $pasta, Tenant $tenant, \DateTimeImmutable $hoje): ?array
    {
        $principal = $pasta->getProcessoPrincipal();
        if ($principal === null) {
            return null;
        }
        $numeros = [];
        foreach ($pasta->getPastaProcessos() as $vinculo) {
            $numeros[] = $vinculo->getProcesso()->getNumeroProcesso();
        }

        $ultima = $this->publicacoes->ultimaParaPrevisaoDoTenant($tenant, $numeros);
        if ($ultima === null) {
            return null;
        }
        $historico = $this->publicacoes->historicoParaPrevisaoDoTenant($tenant, $numeros, self::LIMITE_MOVIMENTACOES);
        $situacao = $this->previsao->preverComHistorico($ultima, $historico, $principal->getClasseProcessual(), $hoje)->situacao;
        $fase = self::FASE_POR_SITUACAO[$situacao] ?? null;

        return $fase === null ? null : ['fase' => $fase, 'situacao' => $situacao];
    }
}
