<?php

declare(strict_types=1);

namespace App\Cobranca\Command;

use App\Cobranca\Service\Importacao\RecorteEsperado;
use App\Cobranca\Service\Importacao\ValidadorRodapeFiltros;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A conferência do recorte (linha `Filtros:` do rodapé) compartilhada pelos 4 comandos de importação —
 * spec `docs/specs/cobranca-validador-rodape-filtros.md` §3.2.
 *
 * Nasceu de achado da 1ª revisão: o mesmo bloco estava copiado nas quatro classes, e a primeira
 * correção de mensagem divergiria em três lugares.
 *
 * Quem usa este trait tem de injetar `ValidadorRodapeFiltros` no construtor com o nome
 * `$validadorRodape` — é a única dependência que ele consome.
 *
 * @property-read ValidadorRodapeFiltros $validadorRodape
 */
trait ConfereRecorteDoArquivo
{
    /**
     * Recusa o arquivo cujo recorte não seja o exigido.
     *
     * Vale nos DOIS modos, e isso é de propósito: um dry-run sobre arquivo errado imprime um relatório
     * convincente e falso, que é pior do que erro nenhum. Por isso a conferência vem ANTES do adapter,
     * não depois — e é essa ordem que `ValidadorRodapeNosComandosTest` prende.
     */
    private function recorteConfere(SymfonyStyle $io, string $arquivo, RecorteEsperado $esperado): bool
    {
        $rodape = $this->validadorRodape->validar($arquivo, $esperado);
        if ($rodape->aceito) {
            return true;
        }

        // Arquivo que não abre ≠ recorte errado (achado da 2ª revisão): a primeira frase precisa dizer
        // qual dos dois é, senão manda o operador reemitir o relatório quando o problema é o download.
        $io->error($rodape->arquivoIlegivel
            ? 'Não foi possível ler o arquivo. A importação foi RECUSADA.'
            : sprintf('O recorte deste arquivo não serve para "%s". A importação foi RECUSADA.', $esperado->fonte));
        $io->listing($rodape->motivos);
        if ($rodape->linha !== null) {
            $io->writeln('<comment>Rodapé lido no arquivo:</comment>');
            $io->writeln('  ' . $rodape->linha);
        }
        $io->note($rodape->arquivoIlegivel
            ? 'Baixe o arquivo de novo — o que está aqui não abre. Nada foi lido nem gravado.'
            : 'Emita o relatório de novo com o recorte correto. Nada foi lido nem gravado.');

        return false;
    }
}
