<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Espelho;

use App\Cobranca\Enum\BlocoRelatorio;

/**
 * Uma linha do arquivo, já classificada mas ainda NÃO interpretada
 * (SPEC espelho §4.1, INV-L1).
 *
 * Os valores monetários vêm em centavos, e são `null` quando a célula estava vazia — célula vazia e
 * célula com zero são coisas diferentes na planilha, e achatar as duas em `0` já seria interpretar.
 *
 * `$bruto` guarda as células como texto, na ordem do arquivo. É a prova de que a conversão para
 * centavos acertou, para quando alguém duvidar do número.
 *
 * ## Os campos dos layouts novos (SPEC quatro-relatórios §4.3)
 *
 * Os quatro últimos campos nascem com os relatórios de acordos, receitas e cadastro. Ficam `null` na
 * inadimplência, que não os tem.
 *
 * 🔴 **Campo novo ganhou campo novo — nenhum foi encaixado num que já existia.** Guardar
 * `Valor recebido` dentro de `$total` porque "cabe" produz número errado com cara de certo, que é o
 * defeito que este módulo já levou duas vezes. Onde não há coluna, o dado fica em `$bruto` e **não**
 * vira número tipado.
 */
final readonly class LinhaEspelhada
{
    /**
     * @param list<string> $bruto
     * @param ?string      $aba           nome da aba; só os acordos têm uma por acordo
     * @param ?string      $tabela        qual das tabelas da aba de acordo — ver {@see TabelaDoAcordo}
     * @param ?string      $parcela       `5/40`, como a planilha de acordos escreve
     * @param ?\DateTimeImmutable $liquidacao data de liquidação (acordos) ou de recebimento (receitas)
     * @param ?int         $valorRecebido `Valor recebido` (receitas) ou `Valor liquidado` (acordos)
     */
    public function __construct(
        public int $numeroLinha,
        public BlocoRelatorio $bloco,
        public ?string $unidade,
        public ?string $sacado,
        public ?string $nn,
        public ?string $classe,
        public ?string $competencia,
        public ?\DateTimeImmutable $vencimento,
        public ?int $atraso,
        public ?int $valor,
        public ?int $juros,
        public ?int $multa,
        public ?int $correcao,
        public ?int $honorarios,
        public ?int $total,
        public ?string $acordoTexto,
        public ?string $recebimento,
        public array $bruto,
        public ?string $aba = null,
        public ?string $tabela = null,
        public ?string $parcela = null,
        public ?\DateTimeImmutable $liquidacao = null,
        public ?int $valorRecebido = null,
    ) {
    }
}
