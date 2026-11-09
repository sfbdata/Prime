<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Exception\LinkInvalido;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;

/**
 * A porta da tela pública (contrato da etapa 2, seção 5.2): acha o signatário pelo token do link e trava o documento
 * dele para a ação. Token malformado, desconhecido, de documento purgado ou de signatário que não existe dão o mesmo
 * {@see LinkInvalido}. O escritório sai sempre do próprio documento achado, nunca da sessão.
 *
 * A busca é só pelo `token_hash` guardado no envio e não depende do segredo: trocar o `ASSINATURA_SEGREDO` não derruba o
 * link já enviado (ele abre até o prazo). Para cortar um link vazado, o escritório cancela o envio.
 */
final readonly class SignatarioDoLink
{
    private const FORMATO_DO_TOKEN = '/^[a-f0-9]{64}$/';

    public function __construct(
        private DocumentoParaAssinarRepository $documentos,
    ) {
    }

    /** @throws LinkInvalido */
    public function localizar(#[\SensitiveParameter] string $token): Signatario
    {
        if (preg_match(self::FORMATO_DO_TOKEN, $token) !== 1) {
            throw new LinkInvalido();
        }

        return $this->documentos->signatarioPeloTokenHash(ChaveDaAssinatura::hashDoToken($token)) ?? throw new LinkInvalido();
    }

    /**
     * Trava a linha do documento e relê documento e signatários do banco: quem decide (vez, código, situação) decide
     * com o que está gravado. Exige transação aberta.
     *
     * @throws LinkInvalido o documento sumiu entre a leitura e a trava
     */
    public function travar(Signatario $signatario): void
    {
        $documento = $signatario->getDocumento();
        if (!$this->documentos->travarERecarregar($documento, $documento->getTenant())) {
            throw new LinkInvalido();
        }

        $this->documentos->recarregarSignatarios($documento);
    }
}
