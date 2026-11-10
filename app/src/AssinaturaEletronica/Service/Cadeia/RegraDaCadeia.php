<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\Cadeia;

use App\AssinaturaEletronica\Enum\NivelDeAssinatura;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\AssinaturaConferida;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDoAcrescimo;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDoPrefixo;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ResultadoDaConferencia;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\SituacaoDaConferencia;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\VereditoDaAssinatura;

/**
 * A regra de combinação do motor com o validador de cadeia (E6-6, spec `docs/specs/assinatura-documentos-etapa-6.md`).
 *
 * Antes do validador ({@see antesDoValidador()}): motor `ilegivel` ou `sem_assinatura`, alguma assinatura `alterada`,
 * prefixo que não confere ou não pedido (E5-1), nenhuma assinatura nova, ou assinatura nova com acréscimo
 * `muda_conteudo` ou `nao_conferido` (M27: quem muda a página no próprio acréscimo assina o conteúdo mudado, e o
 * pyHanko não vê) recusam sem chamá-lo. O carimbo de documento (`/DocTimeStamp`,
 * {@see AssinaturaConferida::carimboDoTempo()}) não conta como assinatura nova: não vai ao validador nem dá CPF. A
 * situação `nao_conferido` do motor por outro motivo não recusa: o carimbo (`naoverif`), os bytes depois da última
 * assinatura (DSS), a assinatura antiga ou a nova que o motor não lê vão ao validador, e a cobertura e o `ltv` decidem.
 *
 * Depois ({@see decidir()}): cada assinatura nova do motor (as últimas `assinaturasNovas`, sem o carimbo) tem de casar
 * pela faixa com um item do validador `intacta`, `valida`, `confiavel` com a âncora do nível (ICP também vale para quem
 * é gov.br), revogação `boa` e `docmdp_ok` não falso. E o que veio depois dela: a última assinatura nova cobre o
 * `arquivo_inteiro` ou a `revisao_inteira` com `nivel_de_modificacao` `ltv` (DSS e carimbo de documento depois); a
 * assinatura nova seguida de outra nova também pode cobrir a `revisao_inteira` com `formulario`. O `arquivo_inteiro`
 * só vale com `nenhuma` (nada veio depois): outra combinação é contraditória e recusa. O `motivo` do validador nunca
 * decide (nulo não quer dizer aceito): ele fica no item gravado, para o log.
 *
 * Os códigos de recusa seguem a ordem do validador (integridade, assinatura, confiança, revogação, DocMDP, e o que veio
 * depois: cobertura e modificação).
 */
final class RegraDaCadeia
{
    public const MOTOR_ILEGIVEL = 'pdf_ilegivel';
    public const SEM_ASSINATURA = 'sem_assinatura';
    public const ASSINATURA_ALTERADA = 'assinatura_alterada';
    public const PREFIXO_NAO_CONFERE = 'prefixo_nao_confere';
    public const PREFIXO_NAO_PEDIDO = 'prefixo_nao_pedido';
    public const SEM_ASSINATURA_NOVA = 'sem_assinatura_nova';
    public const ACRESCIMO_MUDA_CONTEUDO = 'acrescimo_muda_conteudo';
    public const ACRESCIMO_NAO_CONFERIDO = 'acrescimo_nao_conferido';
    public const NAO_CONFERIDA = 'assinatura_nao_conferida';
    public const NAO_INTACTA = 'assinatura_nao_intacta';
    public const INVALIDA = 'assinatura_invalida';
    public const NAO_CONFIAVEL = 'cadeia_nao_confiavel';
    public const ANCORA_DE_OUTRO_NIVEL = 'ancora_de_outro_nivel';
    public const REVOGACAO_NAO_BOA = 'revogacao_nao_boa';
    public const DOCMDP_VIOLADO = 'docmdp_violado';
    public const COBERTURA_PARCIAL = 'cobertura_parcial';
    public const MODIFICACAO_NAO_PERMITIDA = 'modificacao_nao_permitida';

    /** O que pode vir depois da última assinatura nova, cobrindo a revisão inteira: só DSS e carimbo de documento. */
    private const DEPOIS_DA_ULTIMA = [ModificacaoDaCadeia::Ltv];

    /** O que pode vir depois da assinatura nova seguida de outra nova: também o preenchimento e a assinatura do campo. */
    private const DEPOIS_DE_OUTRA_NOVA = [ModificacaoDaCadeia::Ltv, ModificacaoDaCadeia::Formulario];

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

    /**
     * As assinaturas novas que o validador decide: as últimas `assinaturasNovas` do motor (ele as ordena da mais antiga
     * para a mais nova), sem o carimbo de documento.
     *
     * @return list<AssinaturaConferida>
     */
    public static function novas(ResultadoDaConferencia $motor): array
    {
        if ($motor->assinaturasNovas < 1) {
            return [];
        }

        return array_values(array_filter(
            \array_slice($motor->assinaturas, -$motor->assinaturasNovas),
            static fn (AssinaturaConferida $assinatura): bool => !$assinatura->carimboDoTempo(),
        ));
    }

    /** O motivo de recusar sem chamar o validador; nulo quando o validador decide. */
    public function antesDoValidador(ResultadoDaConferencia $motor): ?string
    {
        $novas = self::novas($motor);

        return match (true) {
            $motor->situacao === SituacaoDaConferencia::Ilegivel => self::MOTOR_ILEGIVEL,
            $motor->situacao === SituacaoDaConferencia::SemAssinatura, !$motor->temAssinatura() => self::SEM_ASSINATURA,
            $motor->situacao === SituacaoDaConferencia::Alterado, self::algumaAlterada($motor) => self::ASSINATURA_ALTERADA,
            $motor->prefixo === ConferenciaDoPrefixo::NaoConfere => self::PREFIXO_NAO_CONFERE,
            $motor->prefixo === ConferenciaDoPrefixo::NaoPedida => self::PREFIXO_NAO_PEDIDO,
            $novas === [] => self::SEM_ASSINATURA_NOVA,
            self::algumAcrescimo($novas, ConferenciaDoAcrescimo::MudaConteudo) => self::ACRESCIMO_MUDA_CONTEUDO,
            self::algumAcrescimo($novas, ConferenciaDoAcrescimo::NaoConferido) => self::ACRESCIMO_NAO_CONFERIDO,
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
        $novas = self::novas($motor);
        $ultima = \count($novas) - 1;
        foreach ($novas as $posicao => $nova) {
            $conferida = $cadeia->daFaixa($nova->faixaDeBytes);
            if ($conferida === null) {
                return DecisaoDaCadeia::recusada(self::NAO_CONFERIDA, $cadeia);
            }
            $motivo = self::problema($conferida, $ancoras) ?? self::depois($conferida, $posicao === $ultima);
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
            $assinatura->docmdpOk === false => self::DOCMDP_VIOLADO,
            default => null,
        };
    }

    /** O que veio depois da assinatura: a cobertura e o nível de modificação aceitos para a posição dela. */
    private static function depois(AssinaturaDaCadeia $assinatura, bool $ultima): ?string
    {
        return match ($assinatura->cobertura) {
            CoberturaDaCadeia::ArquivoInteiro => $assinatura->nivelDeModificacao === ModificacaoDaCadeia::Nenhuma ? null : self::MODIFICACAO_NAO_PERMITIDA,
            CoberturaDaCadeia::RevisaoInteira => \in_array($assinatura->nivelDeModificacao, $ultima ? self::DEPOIS_DA_ULTIMA : self::DEPOIS_DE_OUTRA_NOVA, true)
                ? null
                : self::MODIFICACAO_NAO_PERMITIDA,
            CoberturaDaCadeia::Parcial => self::COBERTURA_PARCIAL,
        };
    }

    /** @return list<string> */
    private static function ancorasAceitas(NivelDeAssinatura $nivel): array
    {
        return self::ancoraDoNivel($nivel) === ValidadorDeCadeia::ANCORA_ICP
            ? [ValidadorDeCadeia::ANCORA_ICP]
            : [ValidadorDeCadeia::ANCORA_GOVBR, ValidadorDeCadeia::ANCORA_ICP];
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

    /** @param list<AssinaturaConferida> $novas */
    private static function algumAcrescimo(array $novas, ConferenciaDoAcrescimo $problema): bool
    {
        foreach ($novas as $nova) {
            if ($nova->acrescimo === $problema) {
                return true;
            }
        }

        return false;
    }
}
