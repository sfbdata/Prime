<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Pasta\DTO\CobrancaDoDia;
use App\Pasta\Entity\Pasta;

/**
 * Os textos literais do desenho 02 para a cobrança do checklist (`ckCobMsgBase`, `ckCobMsg`, `ckAuditar`,
 * `ckLembrete` e o aviso de desativar; dc 4247-4372). O tom sobe a cada aviso sem retorno, como no desenho.
 * Cada método devolve [título, texto].
 */
final class MensagensDaCobrancaDoChecklist
{
    private const PERGUNTA = ' Até que dia você resolve? Responda com a data aqui no Chat I.A, na conversa com a BlueJus IA (ex.: até 08/10).';

    /** @return array{string, string} */
    public static function cobranca(CobrancaDoDia $c, Pasta $pasta): array
    {
        $doc = $c->pendencia->titulo;
        if ($c->quebrou && $c->pendencia->previsao !== null) {
            $q = $c->pendencia->quebras + 1;

            return ['Você não cumpriu o prazo: ' . $doc, 'Você se comprometeu a resolver ' . $doc . ' até ' . $c->pendencia->previsao->format('d/m') . ' e não resolveu. ' . ($q > 1 ? 'É a ' . $q . 'ª vez que a data informada não é cumprida. ' : '') . 'Compromisso assumido tem que ser cumprido. A controladoria foi informada. Resolva hoje e informe uma nova data que você vá de fato cumprir.'];
        }

        [$titulo, $texto] = self::porNivel($c->nivel, $doc, self::rotuloDaPasta($pasta), self::tempo($c->diasPendente));

        return [$titulo, $texto . self::PERGUNTA];
    }

    /** @return array{string, string} */
    public static function paraCoordenacao(CobrancaDoDia $c, Pasta $pasta, string $responsavel): array
    {
        $doc = $c->pendencia->titulo;
        if ($c->quebrou && $c->pendencia->previsao !== null) {
            return ['Previsão descumprida: ' . $doc, $responsavel . ' informou que resolveria ' . $doc . ' até ' . $c->pendencia->previsao->format('d/m') . ' e não cumpriu. Pasta ' . self::numero($pasta) . ' (' . self::cliente($pasta) . ') merece atenção.'];
        }

        return ['Documento sem retorno: ' . $doc, $responsavel . ' recebeu ' . $c->nivel . ' cobranças e o item segue pendente ' . ($c->diasPendente > 0 ? 'há ' . $c->diasPendente . ' dias' : '') . ' na pasta ' . self::numero($pasta) . '.'];
    }

    /** @return array{string, string} */
    public static function semAnexoParaQuemMarcou(string $doc, Pasta $pasta): array
    {
        return ['Item marcado sem anexo: ' . $doc, 'Você marcou ' . $doc . ' como entregue na pasta ' . self::numero($pasta) . ', mas não há nenhum documento correspondente anexado. Marcar sem juntar o documento não resolve a pendência. A pasta será auditada. Anexe o documento hoje.'];
    }

    /** @return array{string, string} */
    public static function semAnexoParaControladoria(string $doc, Pasta $pasta, string $quem): array
    {
        return ['Pasta ' . self::numero($pasta) . ' para auditoria', $quem . ' marcou ' . $doc . ' como entregue sem anexar o documento. Recomendo auditar a pasta ' . self::numero($pasta) . ' (' . self::cliente($pasta) . ').'];
    }

    /** @return array{string, string} */
    public static function desativado(Pasta $pasta, string $quem, string $motivo, int $pendentes): array
    {
        return ['Checklist desativado: Pasta ' . self::numero($pasta), $quem . ' desativou o checklist de documentação (' . $motivo . ')' . ($pendentes > 0 ? ' com ' . $pendentes . ' pendências em aberto' : '') . '.'];
    }

    /** @return array{string, string} */
    public static function lembrete(Pasta $pasta, int $dias, string $motivo): array
    {
        return ['Revisar checklist da Pasta ' . self::numero($pasta), 'O checklist de documentação está desativado há ' . $dias . ' dias (' . $motivo . '). Sem ele, o sistema não confere os documentos necessários para instruir o processo. Reative ou confirme que continua não se aplicando.'];
    }

    /** @return array{string, string} */
    private static function porNivel(int $n, string $doc, string $pasta, string $tempo): array
    {
        return match (true) {
            $n === 1 => ['Documento pendente: ' . $doc, 'A ' . $pasta . ' está com a documentação incompleta ' . $tempo . '. Providencie hoje.'],
            $n === 2 => ['Segundo aviso: ' . $doc, $doc . ' continua pendente na ' . $pasta . '. Sem esse documento a pasta não avança. Preciso disso resolvido hoje.'],
            $n === 3 => ['Estou insistindo: ' . $doc, 'Estou insistindo e não estou vendo retorno. ' . $doc . ' segue pendente ' . $tempo . ' na ' . $pasta . '. Isso já virou atraso. Resolva agora ou registre o impedimento na pasta.'],
            $n === 4 => ['Último aviso antes da coordenação: ' . $doc, '3 cobranças sem resposta. ' . $doc . ' está pendente ' . $tempo . ' na ' . $pasta . '. Não dá mais para adiar: resolva hoje. A partir deste aviso a coordenação acompanha junto.'],
            default => [$n . 'º aviso sem retorno: ' . $doc, 'Já são ' . ($n - 1) . ' cobranças ignoradas. ' . $doc . ' segue pendente ' . $tempo . ' na ' . $pasta . ' e a coordenação está acompanhando. Resolva hoje ou registre por escrito o que está impedindo.'],
        };
    }

    /** "pasta N (Fulano Silva)": o cliente só com as duas primeiras palavras, como no desenho. */
    private static function rotuloDaPasta(Pasta $pasta): string
    {
        $cliente = implode(' ', \array_slice(preg_split('/\s+/', self::cliente($pasta)) ?: [], 0, 2));

        return 'pasta ' . self::numero($pasta) . ' (' . $cliente . ')';
    }

    private static function tempo(int $dias): string
    {
        if ($dias <= 0) {
            return 'desde hoje';
        }

        return 'há ' . $dias . ($dias === 1 ? ' dia' : ' dias');
    }

    public static function numero(Pasta $pasta): string
    {
        return $pasta->getNup() ?? ('#' . $pasta->getId());
    }

    private static function cliente(Pasta $pasta): string
    {
        return trim((string) $pasta->getNomeCliente());
    }
}
