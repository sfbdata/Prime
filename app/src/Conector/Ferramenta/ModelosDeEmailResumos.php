<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Comercial\DTO\MensagemDaCampanha;
use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\UseCase\PreviaDaCampanhaUseCase;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Service\HtmlParaTextoDoConector;
use App\Conector\Service\MascaraDeEmailDoConector;

/**
 * O que a pessoa vê antes do "sim" na `modelos_de_email` (6.9, decisão 37): o assunto e o texto do modelo em texto puro
 * (nunca HTML, e-mail sempre mascarado), e o hash do modelo, para recusar a confirmação se ele mudou desde a proposta.
 */
final class ModelosDeEmailResumos
{
    /** O texto inteiro do que será gravado tem de caber no resumo (teto de 30 mil da proposta). */
    public const TETO_DO_TEXTO = 20_000;
    public const TEXTO_GRANDE_DEMAIS = 'O texto deste modelo é grande demais para conferir numa proposta. Encurte o texto.';
    public const SEM_PREVIA = 'Este modelo não monta uma prévia.';

    public function __construct(private readonly PreviaDaCampanhaUseCase $previa)
    {
    }

    /** @param array<string, mixed> $modelo no formato da grade (`ModelosEfetivos`) */
    public static function hash(array $modelo): string
    {
        return hash('sha256', json_encode($modelo, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
    }

    /**
     * O texto puro do e-mail do modelo. Com `$cortarEm`, corta e avisa; sem ele, passar do teto recusa.
     *
     * @throws ErroDaFerramenta
     */
    public function texto(MensagemDaCampanha $mensagem, ?int $cortarEm = null): string
    {
        try {
            $html = $this->previa->executar($mensagem);
        } catch (CampanhaRecusada|\InvalidArgumentException) {
            throw ErroDaFerramenta::recusada(self::SEM_PREVIA);
        }
        $texto = (string) preg_replace_callback(
            '/[^\s@<>()]+@[^\s@<>()]+\.[^\s@<>()]+/u',
            static fn (array $m): string => MascaraDeEmailDoConector::mascarar($m[0]),
            HtmlParaTextoDoConector::converter($html),
        );
        if ($cortarEm !== null && mb_strlen($texto) > $cortarEm) {
            return mb_substr($texto, 0, $cortarEm) . ' (texto cortado)';
        }
        if ($cortarEm === null && mb_strlen($texto) > self::TETO_DO_TEXTO) {
            throw ErroDaFerramenta::recusada(self::TEXTO_GRANDE_DEMAIS);
        }

        return $texto;
    }

    /**
     * @param array<string, mixed> $modelo no formato da grade
     *
     * @throws ErroDaFerramenta
     */
    public static function mensagem(array $modelo): MensagemDaCampanha
    {
        try {
            return MensagemDaCampanha::deArray($modelo);
        } catch (CampanhaRecusada $e) {
            throw ErroDaFerramenta::recusada($e->getMessage());
        }
    }
}
