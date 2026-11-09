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
use App\AssinaturaEletronica\Service\MascaraDeEmail;
use App\AssinaturaEletronica\Service\SignatarioDoLink;
use App\AssinaturaEletronica\Service\TrilhaDoDocumento;
use Psr\Clock\ClockInterface;

/**
 * "Enviar código" e "Reenviar" (contrato da etapa 2, seção 3): com a linha travada e relida, só quem tem a vez; código
 * novo de 6 dígitos (o anterior deixa de valer), só o HMAC no banco, e o e-mail depois do COMMIT. O código nunca volta
 * na resposta. Teto de 10 envios em 24 h por signatário, contado pela trilha: passado o teto, o signatário fica
 * bloqueado até o fim da janela (o bloqueio é gravado mesmo com a recusa).
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
        private TrilhaDoDocumento $trilha,
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
            $this->trilha->acrescentar(
                $signatario->getDocumento(),
                TipoDeEvento::CodigoEnviado,
                ['para' => MascaraDeEmail::mascarar((string) $signatario->getEmail())],
                null,
                $signatario,
                $evidencia,
            );

            return DesfechoDaAcaoPublica::feito([CartaDaAssinatura::codigo($signatario, $codigo)]);
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
