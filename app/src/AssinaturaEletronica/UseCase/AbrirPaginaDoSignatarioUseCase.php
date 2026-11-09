<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\DTO\EvidenciaDaRequisicao;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Enum\SituacaoDoDocumento;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Exception\LinkInvalido;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Repository\EventoDaTrilhaRepository;
use App\AssinaturaEletronica\Service\PaginaDoSignatario;
use App\AssinaturaEletronica\Service\SignatarioDoLink;
use App\AssinaturaEletronica\Service\TrilhaDoDocumento;
use Psr\Clock\ClockInterface;

/**
 * A página do signatário pelo link (contrato da etapa 2, seções 5.2 e 5.3). A primeira abertura de quem ainda pode
 * assinar grava `link_aberto`, uma vez por signatário: conferida sem trava (a recarga comum não abre transação) e de
 * novo com a linha travada, para duas abas ao mesmo tempo não gravarem duas.
 */
final readonly class AbrirPaginaDoSignatarioUseCase
{
    public function __construct(
        private SignatarioDoLink $link,
        private DocumentoParaAssinarRepository $documentos,
        private EventoDaTrilhaRepository $eventos,
        private TrilhaDoDocumento $trilha,
        private PaginaDoSignatario $pagina,
        private ClockInterface $relogio,
    ) {
    }

    /**
     * @return array<string, mixed> o `Publico`
     *
     * @throws LinkInvalido
     */
    public function executar(#[\SensitiveParameter] string $token, EvidenciaDaRequisicao $evidencia): array
    {
        $signatario = $this->link->localizar($token);
        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());

        if ($this->primeiraAbertura($signatario)) {
            $this->documentos->emTransacao(function () use ($signatario, $evidencia): void {
                $this->link->travar($signatario);
                if ($this->primeiraAbertura($signatario)) {
                    $this->trilha->acrescentar($signatario->getDocumento(), TipoDeEvento::LinkAberto, [], null, $signatario, $evidencia);
                }
            });
        }

        return $this->pagina->publico($signatario, $token, $agora);
    }

    private function primeiraAbertura(Signatario $signatario): bool
    {
        $documento = $signatario->getDocumento();

        return $signatario->estaPendente()
            && $documento->getSituacao() === SituacaoDoDocumento::Aguardando
            && !$documento->estaNaLixeira()
            && !$this->eventos->existeDoSignatario($signatario, TipoDeEvento::LinkAberto);
    }
}
