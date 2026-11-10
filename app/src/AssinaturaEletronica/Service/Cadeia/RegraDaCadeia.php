<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\Cadeia;

use App\AssinaturaEletronica\Enum\NivelDeAssinatura;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\AssinaturaConferida;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDoPrefixo;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ResultadoDaConferencia;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\SituacaoDaConferencia;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\VereditoDaAssinatura;

/**
 * A regra de combinação do motor com o validador de cadeia (E6-6, spec `docs/specs/assinatura-documentos-etapa-6.md`).
 *
 * Antes do validador ({@see antesDoValidador()}): motor `ilegivel` ou `sem_assinatura`, alguma assinatura `alterada`,
 * prefixo que não confere ou nenhuma assinatura nova recusam sem chamá-lo.
 *
 * Depois ({@see decidir()}): cada assinatura nova do motor (as últimas `assinaturasNovas`) tem de casar pela faixa com
 * um item do validador `intacta`, `valida`, `confiavel` com a âncora do nível (ICP também vale para quem é gov.br),
 * revogação `boa`, nível de modificação até `formulario`, cobertura do arquivo inteiro e `docmdp_ok` não falso. O
 * `motivo` do validador nunca decide (nulo não quer dizer aceito): ele fica no item gravado, para o log.
 *
 * Os códigos de recusa seguem a ordem do validador (integridade, assinatura, confiança, revogação, modificação,
 * cobertura, DocMDP).
 */
final class RegraDaCadeia
{
    public const MOTOR_ILEGIVEL = 'pdf_ilegivel';
    public const SEM_ASSINATURA = 'sem_assinatura';
    public const ASSINATURA_ALTERADA = 'assinatura_alterada';
    public const PREFIXO_NAO_CONFERE = 'prefixo_nao_confere';
    public const SEM_ASSINATURA_NOVA = 'sem_assinatura_nova';
    public const NAO_CONFERIDA = 'assinatura_nao_conferida';
    public const NAO_INTACTA = 'assinatura_nao_intacta';
    public const INVALIDA = 'assinatura_invalida';
    public const NAO_CONFIAVEL = 'cadeia_nao_confiavel';
    public const ANCORA_DE_OUTRO_NIVEL = 'ancora_de_outro_nivel';
    public const REVOGACAO_NAO_BOA = 'revogacao_nao_boa';
    public const MODIFICACAO_NAO_PERMITIDA = 'modificacao_nao_permitida';
    public const COBERTURA_PARCIAL = 'cobertura_parcial';
    public const DOCMDP_VIOLADO = 'docmdp_violado';

    /** Até onde a modificação depois da assinatura é aceita. */
    private const MODIFICACOES_ACEITAS = [ModificacaoDaCadeia::Nenhuma, ModificacaoDaCadeia::Ltv, ModificacaoDaCadeia::Formulario];

    /**
     * A âncora que se pede ao validador para o nível: ICP só a ICP-Brasil; gov.br a gov.br ou a ICP-Brasil.
     *
     * @throws \LogicException nível que não passa pelo validador (Simples, Reforçada)
     */
    public static function ancoraDoNivel(NivelDeAssinatura $nivel): string
    {
        return match ($nivel) {
            NivelDeAssinatura::Icp => ValidadorDeCadeia::ANCORA_ICP,
            NivelDeAssinatura::Govbr => ValidadorDeCadeia::ANCORA_QUALQUER,
            default => throw new \LogicException(sprintf('O nível %s não passa pelo validador de cadeia.', $nivel->value)),
        };
    }

    /** O motivo de recusar sem chamar o validador; nulo quando o validador decide. */
    public function antesDoValidador(ResultadoDaConferencia $motor): ?string
    {
        return match (true) {
            $motor->situacao === SituacaoDaConferencia::Ilegivel => self::MOTOR_ILEGIVEL,
            $motor->situacao === SituacaoDaConferencia::SemAssinatura, !$motor->temAssinatura() => self::SEM_ASSINATURA,
            $motor->situacao === SituacaoDaConferencia::Alterado, self::algumaAlterada($motor) => self::ASSINATURA_ALTERADA,
            $motor->prefixo === ConferenciaDoPrefixo::NaoConfere => self::PREFIXO_NAO_CONFERE,
            $motor->assinaturasNovas < 1 => self::SEM_ASSINATURA_NOVA,
            default => null,
        };
    }

    /** O desfecho para o nível, com a resposta do validador para o mesmo PDF que o motor conferiu. */
    public function decidir(ResultadoDaConferencia $motor, NivelDeAssinatura $nivel, ResultadoDaCadeia $cadeia): DecisaoDaCadeia
    {
        $antes = $this->antesDoValidador($motor);
        if ($antes !== null) {
            return DecisaoDaCadeia::recusada($antes, $cadeia);
        }
        if ($cadeia->erro !== null) {
            return DecisaoDaCadeia::recusada($cadeia->erro, $cadeia);
        }
        $ancoras = self::ancorasAceitas($nivel);
        foreach (self::novas($motor) as $nova) {
            $conferida = $cadeia->daFaixa($nova->faixaDeBytes);
            if ($conferida === null) {
                return DecisaoDaCadeia::recusada(self::NAO_CONFERIDA, $cadeia);
            }
            $motivo = self::problema($conferida, $ancoras);
            if ($motivo !== null) {
                return DecisaoDaCadeia::recusada($motivo, $cadeia, $conferida);
            }
        }

        return DecisaoDaCadeia::confirmada($cadeia);
    }

    /** @param list<string> $ancoras */
    private static function problema(AssinaturaDaCadeia $assinatura, array $ancoras): ?string
    {
        return match (true) {
            !$assinatura->intacta => self::NAO_INTACTA,
            !$assinatura->valida => self::INVALIDA,
            !$assinatura->confiavel => self::NAO_CONFIAVEL,
            !\in_array($assinatura->ancora, $ancoras, true) => self::ANCORA_DE_OUTRO_NIVEL,
            $assinatura->revogacao !== RevogacaoDaCadeia::Boa => self::REVOGACAO_NAO_BOA,
            !\in_array($assinatura->nivelDeModificacao, self::MODIFICACOES_ACEITAS, true) => self::MODIFICACAO_NAO_PERMITIDA,
            $assinatura->cobertura !== CoberturaDaCadeia::ArquivoInteiro => self::COBERTURA_PARCIAL,
            $assinatura->docmdpOk === false => self::DOCMDP_VIOLADO,
            default => null,
        };
    }

    /** @return list<string> */
    private static function ancorasAceitas(NivelDeAssinatura $nivel): array
    {
        return self::ancoraDoNivel($nivel) === ValidadorDeCadeia::ANCORA_ICP
            ? [ValidadorDeCadeia::ANCORA_ICP]
            : [ValidadorDeCadeia::ANCORA_GOVBR, ValidadorDeCadeia::ANCORA_ICP];
    }

    /** @return list<AssinaturaConferida> as últimas `assinaturasNovas` (o motor as ordena da mais antiga para a mais nova) */
    private static function novas(ResultadoDaConferencia $motor): array
    {
        return \array_slice($motor->assinaturas, -$motor->assinaturasNovas);
    }

    private static function algumaAlterada(ResultadoDaConferencia $motor): bool
    {
        foreach ($motor->assinaturas as $assinatura) {
            if ($assinatura->veredito === VereditoDaAssinatura::Alterada) {
                return true;
            }
        }

        return false;
    }
}
