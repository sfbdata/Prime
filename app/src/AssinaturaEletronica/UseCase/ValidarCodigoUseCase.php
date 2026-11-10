<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Service\ChaveDoSelo;
use App\AssinaturaEletronica\Service\ProjecaoPublicaDaValidacao;

/**
 * Validar um código BJA (etapa 3, bloco 2; desenho dc:457): o código digitado é aparado e posto em maiúsculas, e vale
 * só a igualdade exata com o código gravado no envio. Acha o documento de qualquer escritório, mesmo na Lixeira (a
 * prova continua, E3-8), e devolve só a projeção pública. Nulo quando nada bate: a tela diz "Código não localizado.".
 *
 * Código fora do formato `BJA-XXXX-XXXX-XXXX` (o prefixo e o alfabeto do {@see ChaveDoSelo}, três grupos de quatro)
 * responde "não localizado" sem ir ao banco. O limitador por IP do controller já consumiu antes, como em toda procura.
 *
 * Roda só nas rotas públicas (`assinatura_eletronica_publica_validar*`), onde o filtro de escritório já foi desligado
 * pelo {@see \App\AssinaturaEletronica\EventListener\AssinaturaPublicaListener}.
 */
final readonly class ValidarCodigoUseCase
{
    public function __construct(
        private DocumentoParaAssinarRepository $documentos,
        private ProjecaoPublicaDaValidacao $projecao,
    ) {
    }

    /** @return array<string, mixed>|null a projeção pública, ou nulo quando o código não é de nenhum documento */
    public function executar(string $digitado): ?array
    {
        $codigo = self::normalizar($digitado);
        if (!self::noFormato($codigo)) {
            return null;
        }

        $documento = $this->documentos->porCodigo($codigo);

        return $documento !== null ? $this->projecao->de($documento) : null;
    }

    /** `trim().toUpperCase()` do desenho; texto que não é UTF-8 válido vira vazio (nada a procurar). */
    public static function normalizar(string $digitado): string
    {
        return mb_strtoupper((string) preg_replace('/^\s+|\s+$/u', '', $digitado));
    }

    /** O código já normalizado tem o formato que o selo gera: `BJA-` e três grupos de quatro símbolos do alfabeto. */
    private static function noFormato(string $codigo): bool
    {
        $grupo = '[' . ChaveDoSelo::ALFABETO . ']{4}';

        return preg_match('/\A' . preg_quote(ChaveDoSelo::PREFIXO, '/') . $grupo . '(?:-' . $grupo . '){2}\z/', $codigo) === 1;
    }
}
