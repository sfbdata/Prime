<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\DTO\CartaDaAssinatura;
use App\AssinaturaEletronica\DTO\EvidenciaDaRequisicao;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\LinkInvalido;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Service\CorreioDaAssinatura;
use App\AssinaturaEletronica\Service\PaginaDoSignatario;
use App\AssinaturaEletronica\Service\SignatarioDoLink;
use App\AssinaturaEletronica\Service\TrilhaDoDocumento;
use Psr\Clock\ClockInterface;

/**
 * "Recusar assinatura" da tela pública (contrato da etapa 2, seções 4.1 e 5.2): o motivo (1 a 500 caracteres), com a
 * linha travada e relida, só de quem tem a vez. O documento vira Recusado e os links dos demais deixam de valer. O aviso
 * ao remetente sai depois do COMMIT. Sem identificação além do link, como o desenho (decisão G4).
 */
final readonly class RecusarAssinaturaUseCase
{
    public const MOTIVO_MAXIMO = 500;

    public function __construct(
        private SignatarioDoLink $link,
        private DocumentoParaAssinarRepository $documentos,
        private TrilhaDoDocumento $trilha,
        private CorreioDaAssinatura $correio,
        private PaginaDoSignatario $pagina,
        private ClockInterface $relogio,
    ) {
    }

    /**
     * @return array<string, mixed> o `Publico` depois da recusa
     *
     * @throws LinkInvalido
     * @throws AcaoRecusada
     */
    public function executar(#[\SensitiveParameter] string $token, string $motivo, EvidenciaDaRequisicao $evidencia): array
    {
        $signatario = $this->link->localizar($token);
        $motivo = trim($motivo);
        if ($motivo === '') {
            throw new AcaoRecusada('assinatura_eletronica.erro.recusa_sem_motivo');
        }
        if (mb_strlen($motivo) > self::MOTIVO_MAXIMO) {
            throw new AcaoRecusada('assinatura_eletronica.erro.recusa_motivo_longo');
        }
        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());

        $this->documentos->emTransacao(function () use ($signatario, $motivo, $evidencia, $agora): void {
            $this->link->travar($signatario);
            $documento = $signatario->getDocumento();
            $documento->recusar($signatario, $motivo, $evidencia->ip, $evidencia->navegador, $agora);
            $this->trilha->acrescentar($documento, TipoDeEvento::Recusado, ['motivo' => $motivo], null, $signatario, $evidencia);
        });
        $this->correio->entregar([CartaDaAssinatura::recusa($signatario)]);

        return $this->pagina->publico($signatario, $token, $agora);
    }
}
