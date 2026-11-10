<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Service\ConferenciaDePdf\AssinaturaConferida;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ResultadoDaConferencia;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\VereditoDaAssinatura;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * O quadro "Documento já assinado" (etapa 5, bloco 5A; o `presV` do desenho, dc:381) e o parágrafo que entra no
 * conteúdo ao salvar (dc:374), a partir da conferência do motor. O quadro é o mesmo no passo 3 (antes de salvar) e no
 * detalhe (dc:188 e dc:218).
 *
 * A data de cada assinatura sai em ISO 8601 (`em`): a tela formata no fuso do navegador, como o `fm` do desenho, e
 * põe "data não informada" quando falta. O resto da linha (`detalhe`) já sai pronto, na ordem do desenho: tipo,
 * situação e emissor, separados por " · ".
 */
final readonly class ApresentacaoDaConferencia
{
    public const VERDE = '#1e7b45';
    public const VERMELHO = '#a3232b';
    public const AMARELO = '#8a6200';

    private const TEXTOS = 'assinatura_eletronica_tela.js.ja_assinado.';

    public function __construct(
        private TranslatorInterface $tradutor,
    ) {
    }

    /**
     * Nulo quando o PDF não traz assinatura digital (o `tem` do desenho). `carimbo` é o `dPrCar` do desenho (dc:466): o
     * motor permite o carimbo e nenhuma assinatura está alterada; o `aviso` segue só o `ana.carimbo` (dPrAv).
     *
     * @return array{titulo: string, cor: string, linhas: list<array{icone: string, cor: string, nome: string, documento: ?string, em: ?string, detalhe: string}>, nota: string, aviso: string, carimbo: bool}|null
     */
    public function quadro(ResultadoDaConferencia $resultado): ?array
    {
        if (!$resultado->temAssinatura()) {
            return null;
        }
        $alterada = self::temAlterada($resultado);

        return [
            'titulo' => $this->titulo($resultado, $alterada),
            'cor' => $alterada ? self::VERMELHO : self::VERDE,
            'linhas' => array_map($this->linha(...), $resultado->assinaturas),
            'nota' => $this->texto('nota') . ($resultado->docMdp === 1 ? $this->texto('nota_docmdp') : ''),
            'aviso' => $this->texto($resultado->permiteCarimbo ? 'aviso_carimbo' : 'aviso_bloqueado'),
            'carimbo' => $resultado->permiteCarimbo && !$alterada,
        ];
    }

    /** "Este documento já continha N assinatura(s) digital(is) de {nomes}. ... com hash SHA-256 {sha}." (dc:374) */
    public function paragrafo(ResultadoDaConferencia $resultado, string $sha256): string
    {
        return $this->texto('paragrafo', [
            'n' => \count($resultado->assinaturas),
            'nomes' => implode(', ', array_map($this->nome(...), $resultado->assinaturas)),
            'sha' => $sha256,
        ]);
    }

    private function titulo(ResultadoDaConferencia $resultado, bool $alterada): string
    {
        $quantas = \count($resultado->assinaturas);
        if ($alterada) {
            return $this->texto('titulo_alterada');
        }

        return $quantas === 1 ? $this->texto('titulo_um') : $this->texto('titulo_varios', ['n' => $quantas]);
    }

    /** @return array{icone: string, cor: string, nome: string, documento: ?string, em: ?string, detalhe: string} */
    private function linha(AssinaturaConferida $assinatura): array
    {
        [$icone, $cor] = match ($assinatura->veredito) {
            VereditoDaAssinatura::Integra => ['bi-check-circle-fill', self::VERDE],
            VereditoDaAssinatura::Alterada => ['bi-x-circle-fill', self::VERMELHO],
            VereditoDaAssinatura::NaoConferida => ['bi-question-circle-fill', self::AMARELO],
        };
        $emissor = $assinatura->emissor !== null && $assinatura->emissor !== '' ? $this->texto('emissor', ['emissor' => $assinatura->emissor]) : '';

        return [
            'icone' => $icone,
            'cor' => $cor,
            'nome' => $this->nome($assinatura),
            'documento' => $assinatura->documentoMascarado,
            'em' => $assinatura->horaDeclarada?->format(\DateTimeInterface::ATOM),
            'detalhe' => implode(' · ', array_values(array_filter([$assinatura->tipo, $this->situacao($assinatura), $emissor], static fn (string $p): bool => $p !== ''))),
        ];
    }

    private function situacao(AssinaturaConferida $assinatura): string
    {
        return match ($assinatura->veredito) {
            VereditoDaAssinatura::Integra => $this->texto($assinatura->cobreODocumento ? 'integra_cobre' : 'integra_acrescimos'),
            VereditoDaAssinatura::Alterada => $assinatura->motivo,
            VereditoDaAssinatura::NaoConferida => $assinatura->motivo !== '' ? $assinatura->motivo : $this->texto('nao_conferida'),
        };
    }

    /** Sem titular no certificado: "Não identificado" (pdf.js:17 do desenho). */
    private function nome(AssinaturaConferida $assinatura): string
    {
        return $assinatura->titular !== null && $assinatura->titular !== '' ? $assinatura->titular : $this->texto('nao_identificado');
    }

    private static function temAlterada(ResultadoDaConferencia $resultado): bool
    {
        foreach ($resultado->assinaturas as $assinatura) {
            if ($assinatura->veredito === VereditoDaAssinatura::Alterada) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, int|string> $parametros */
    private function texto(string $chave, array $parametros = []): string
    {
        return $this->tradutor->trans(self::TEXTOS . $chave, $parametros);
    }
}
