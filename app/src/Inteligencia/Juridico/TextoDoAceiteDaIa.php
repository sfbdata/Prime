<?php

declare(strict_types=1);

namespace App\Inteligencia\Juridico;

use App\Inteligencia\Enum\SecaoDoContexto;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * O texto do aceite da BlueJus IA, como o administrador do escritório o lê em `inteligencia/admin/configuracao.html.twig`
 * (o rótulo do interruptor e o aviso "Ao ligar, o escritório aceita enviar..."), em texto puro para o jurídico baixar.
 *
 * Gerado na hora com as MESMAS chaves do catálogo e o mesmo enum de seções que a tela usa: mudou o aceite, mudou o
 * download. O rótulo do interruptor é esta constante, que o `ConfiguracaoDeInteligenciaType` também usa.
 */
final class TextoDoAceiteDaIa
{
    public const ROTULO_DO_INTERRUPTOR = 'Ligar a BlueJus IA neste escritório';
    private const P = 'inteligencia.admin.envio.';

    public function __construct(
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    public function texto(): string
    {
        $linhas = [
            self::ROTULO_DO_INTERRUPTOR,
            '',
            $this->t('intro'),
            '',
            '- ' . $this->t('pasta') . ' ' . $this->lista(['pasta_numero', 'pasta_situacao', 'pasta_prioridade', 'pasta_abertura', 'pasta_acao', 'pasta_responsavel', 'pasta_equipe']) . ';',
            '- ' . $this->t('processos') . ' ' . $this->lista(['processo_numero', 'processo_classe', 'processo_assunto', 'processo_tribunal', 'processo_orgao', 'processo_situacao']) . ';',
            '- ' . $this->t('agentes') . ' ' . $this->juntar(array_map(static fn (SecaoDoContexto $s): string => $s->rotuloCurto(), SecaoDoContexto::cases())) . ';',
            '- ' . $this->t('push'),
            '- ' . $this->t('analise_anterior'),
            '- ' . $this->t('documentos'),
            '',
            $this->t('fim'),
        ];

        return implode("\n", $linhas) . "\n";
    }

    /** @param list<string> $chaves */
    private function lista(array $chaves): string
    {
        return $this->juntar(array_map($this->t(...), $chaves));
    }

    /**
     * "a, b e c", como a tela junta os itens.
     *
     * @param list<string> $itens
     */
    private function juntar(array $itens): string
    {
        $ultimo = array_pop($itens);
        if ($itens === []) {
            return (string) $ultimo;
        }

        return implode(', ', $itens) . ' ' . $this->t('e') . ' ' . $ultimo;
    }

    private function t(string $chave): string
    {
        return $this->tradutor->trans(self::P . $chave, [], null, 'pt_BR');
    }
}
