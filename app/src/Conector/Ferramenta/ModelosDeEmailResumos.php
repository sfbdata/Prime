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
    /** O texto do resumo da edição cabe nos 30 mil da proposta; passando, o resumo é cortado e o modelo é gravado inteiro. */
    public const TETO_DO_TEXTO = 20_000;
    public const CORTADO_NO_RESUMO = ' (texto cortado no resumo; o modelo é gravado inteiro)';
    public const CORTADO = ' (texto cortado no resumo)';
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
     * O texto puro do e-mail do modelo, cortado em `$cortarEm` caracteres com a marca `$marca`.
     *
     * @throws ErroDaFerramenta
     */
    public function texto(MensagemDaCampanha $mensagem, int $cortarEm = self::TETO_DO_TEXTO, string $marca = self::CORTADO_NO_RESUMO): string
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

        return mb_strlen($texto) > $cortarEm ? mb_substr($texto, 0, $cortarEm) . $marca : $texto;
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
