<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\DTO\CartaDaAssinatura;
use App\AssinaturaEletronica\DTO\DesfechoDaAcaoPublica;
use App\AssinaturaEletronica\DTO\EvidenciaDaRequisicao;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\LinkInvalido;
use App\AssinaturaEletronica\Exception\MuitasTentativas;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Repository\EventoDaTrilhaRepository;
use App\AssinaturaEletronica\Service\ChaveDaAssinatura;
use App\AssinaturaEletronica\Service\CorreioDaAssinatura;
use App\AssinaturaEletronica\Service\SignatarioDoLink;
use Psr\Clock\ClockInterface;

/**
 * "Enviar código" e "Reenviar" (contrato da etapa 2, seção 3): com a linha travada e relida, só quem tem a vez; código
 * novo de 6 dígitos (o anterior deixa de valer), só o HMAC no banco, e o e-mail depois do COMMIT, com o Reply-To de
 * quem enviou o documento. O código nunca volta na resposta. Só o e-mail entregue grava `codigo_enviado` (pelo correio,
 * com a evidência da requisição); falhou, só `email_nao_enviado`. Teto de 10 códigos entregues em 24 h por signatário,
 * contado pela trilha: passado o teto, o signatário fica bloqueado até o fim da janela (o bloqueio é gravado mesmo com
 * a recusa).
 *
 * Etapa 5 (bloco 5C): quem assina gov.br ou ICP não usa código (a prova é o PDF assinado): para ele a ação não existe,
 * e o pedido é o mesmo {@see LinkInvalido} do token desconhecido, sem código nem e-mail.
 */
final readonly class SolicitarCodigoUseCase
{
    public const TETO_DIARIO = 10;
    public const JANELA = '24 hours';

    public function __construct(
        private SignatarioDoLink $link,
        private DocumentoParaAssinarRepository $documentos,
        private EventoDaTrilhaRepository $eventos,
        private ChaveDaAssinatura $chave,
        private CorreioDaAssinatura $correio,
        private ClockInterface $relogio,
    ) {
    }

    /**
     * @throws LinkInvalido
     * @throws AcaoRecusada
     * @throws MuitasTentativas
     */
    public function executar(#[\SensitiveParameter] string $token, EvidenciaDaRequisicao $evidencia): void
    {
        $signatario = $this->link->localizar($token);
        if ($signatario->assinaComCertificado()) {
            throw new LinkInvalido();
        }
        $this->chave->exigirConfigurada();
        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());

        $desfecho = $this->documentos->emTransacao(function () use ($signatario, $evidencia, $agora): DesfechoDaAcaoPublica {
            $this->link->travar($signatario);
            $signatario->getDocumento()->exigirVez($signatario, $agora);
            if ($signatario->estaBloqueado($agora) || $this->estourouOTeto($signatario, $agora)) {
                return DesfechoDaAcaoPublica::recusado(new MuitasTentativas());
            }

            $codigo = ChaveDaAssinatura::novoCodigo();
            $signatario->registrarCodigo($this->chave->hashDoCodigo($signatario, $codigo), $agora);
            $quemEnviou = $this->eventos->quemEnviou($signatario->getDocumento());

            return DesfechoDaAcaoPublica::feito([CartaDaAssinatura::codigo($signatario, $codigo, $quemEnviou, $evidencia)]);
        });
        $desfecho->lancarSeRecusado();

        if (!$this->correio->entregar($desfecho->cartas)) {
            throw new AcaoRecusada('assinatura_eletronica.erro.codigo_nao_enviado');
        }
    }

    /** Dez códigos nas últimas 24 h: bloqueia até 24 h depois do mais antigo deles. */
    private function estourouOTeto(Signatario $signatario, \DateTimeImmutable $agora): bool
    {
        [$enviados, $maisAntigo] = $this->eventos->contarDoSignatarioDesde($signatario, TipoDeEvento::CodigoEnviado, $agora->modify('-' . self::JANELA));
        if ($enviados < self::TETO_DIARIO) {
            return false;
        }

        $signatario->bloquearAte(($maisAntigo ?? $agora)->modify('+' . self::JANELA));

        return true;
    }
}
