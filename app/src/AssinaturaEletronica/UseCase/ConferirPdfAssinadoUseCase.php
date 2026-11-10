<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\DTO\PdfAssinadoConferido;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\LinkInvalido;
use App\AssinaturaEletronica\Service\Cadeia\ValidadorDeCadeia;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\LimitesDaConferencia;
use App\AssinaturaEletronica\Service\ConferenciaDoPdfAssinado;
use App\AssinaturaEletronica\Service\ResultadoDoPdfAssinado;
use App\AssinaturaEletronica\Service\SignatarioDoLink;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A pré-conferência do PDF que o signatário gov.br ou ICP assinou fora do BlueJus e devolve pela tela pública (etapa 5,
 * bloco 5C; `subirAss`, dc:466): roda ao escolher o arquivo, antes de "Assinar", e não grava nada (nem versão, nem
 * trilha, nem evento) nem chama o validador de cadeia. Quem decide de verdade é a assinatura com certificado, que
 * reconfere o arquivo reenviado.
 *
 * Na ordem: o signatário pelo token ({@see LinkInvalido} também para quem não é gov.br nem ICP: esta ação não existe
 * para ele); a vez dele, como na Simples (documento aguardando, no prazo, signatário pendente e, com ordem, a vez); o
 * validador de cadeia configurado (sem ele, gov.br e ICP não fecham, E6-4: `cadeia_indisponivel`); o arquivo, só PDF
 * pelo conteúdo e até 20 MB, o teto do motor (E5-8), senão `ilegivel`; e a {@see ConferenciaDoPdfAssinado} com o CPF
 * cadastrado do signatário, quando há. A recusa sai como {@see AcaoRecusada} com a chave do texto do catálogo público.
 */
final readonly class ConferirPdfAssinadoUseCase
{
    private const TIPO_DO_PDF = 'application/pdf';

    public function __construct(
        private SignatarioDoLink $link,
        private ValidadorDeCadeia $validador,
        private ConferenciaDoPdfAssinado $conferencia,
        private ClockInterface $relogio,
    ) {
    }

    /**
     * @throws LinkInvalido
     * @throws AcaoRecusada
     */
    public function executar(#[\SensitiveParameter] string $token, UploadedFile $pdf): PdfAssinadoConferido
    {
        $signatario = $this->link->localizar($token);
        if (!$signatario->assinaComCertificado()) {
            throw new LinkInvalido();
        }
        $signatario->getDocumento()->exigirVez($signatario, \DateTimeImmutable::createFromInterface($this->relogio->now()));
        if (!$this->validador->configurado()) {
            throw self::recusa(ResultadoDoPdfAssinado::CADEIA_INDISPONIVEL);
        }

        $resultado = $this->conferencia->conferir($signatario->getDocumento(), self::conteudo($pdf), self::cpfCadastrado($signatario));
        if ($resultado->erro !== null) {
            throw self::recusa($resultado->erro);
        }

        return PdfAssinadoConferido::de($resultado, $pdf->getClientOriginalName());
    }

    /** Os bytes do PDF enviado, depois do tipo pelo conteúdo e do teto do motor (sem ler o arquivo grande). */
    private static function conteudo(UploadedFile $pdf): string
    {
        if (!$pdf->isValid() || (int) $pdf->getSize() > LimitesDaConferencia::PDF_MAXIMO_EM_BYTES || $pdf->getMimeType() !== self::TIPO_DO_PDF) {
            throw self::recusa(ResultadoDoPdfAssinado::ILEGIVEL);
        }

        return $pdf->getContent();
    }

    /** Só os dígitos do CPF cadastrado; nulo sem ele. */
    private static function cpfCadastrado(Signatario $signatario): ?string
    {
        $cpf = (string) preg_replace('/\D/', '', (string) $signatario->getCpf());

        return $cpf !== '' ? $cpf : null;
    }

    private static function recusa(string $erro): AcaoRecusada
    {
        return new AcaoRecusada(ResultadoDoPdfAssinado::chave($erro));
    }
}
