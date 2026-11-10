<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\DTO\LocalizacaoDoSignatario;
use App\AssinaturaEletronica\Entity\EventoDaTrilha;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * O texto de um evento da trilha, uma regra só para o detalhe da tela ({@see ApresentacaoDoDocumento}) e para o
 * manifesto do PDF ({@see ManifestoDoDocumento}): a chave do catálogo do tipo e os parâmetros abaixo.
 */
final class TextoDoEvento
{
    private function __construct()
    {
    }

    public static function de(TranslatorInterface $tradutor, EventoDaTrilha $evento): string
    {
        return $tradutor->trans($evento->getTipo()->chaveDoTexto(), self::parametros($evento));
    }

    /**
     * Os parâmetros do texto do evento: os valores simples de `dados` (o ICU não recebe lista) e o `{nome}` do
     * signatário ou do usuário do evento, quando o evento não traz um nome próprio (o "Duplicado de {nome}" traz).
     * Com latitude e longitude (assinatura Reforçada, etapa 7), também `{local}` com 5 casas, como a tela do desenho.
     * Como o desenho (L375, L390): o envio mostra os 16 primeiros caracteres do hash (`{hash_inicio}`), e a assinatura,
     * o nome que o signatário digitou ao assinar (o cadastrado só na falta dele).
     *
     * @return array<string, scalar>
     */
    public static function parametros(EventoDaTrilha $evento): array
    {
        $parametros = array_filter($evento->getDados(), is_scalar(...));
        if (!isset($parametros['nome'])) {
            $signatario = $evento->getSignatario();
            $digitado = $evento->getTipo() === TipoDeEvento::Assinado ? $signatario?->getNomeDigitado() : null;
            $nome = $digitado ?? $signatario?->getNome() ?? $evento->getUsuario()?->getFullName();
            if ($nome !== null) {
                $parametros['nome'] = $nome;
            }
        }
        if (\is_string($parametros['latitude'] ?? null) && \is_string($parametros['longitude'] ?? null)) {
            $parametros['local'] = LocalizacaoDoSignatario::textoDe($parametros['latitude'], $parametros['longitude']);
        }
        if (\is_string($parametros['hash'] ?? null)) {
            $parametros['hash_inicio'] = substr($parametros['hash'], 0, 16);
        }

        return $parametros;
    }
}
