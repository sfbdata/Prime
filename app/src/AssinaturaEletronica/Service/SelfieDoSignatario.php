<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Exception\SelfieIlegivel;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;

/**
 * Abre a selfie guardada de quem assinou Reforçada (contrato da etapa 7, seção 5.3), para a etapa 3 (manifesto). Não há
 * rota nesta etapa: o desenho não mostra a foto em lugar nenhum (lacuna L1).
 *
 * Quem chamar confere antes o módulo e o acesso ao documento, responde com `no-store` e grava na trilha "selfie
 * visualizada" com o usuário, se mostrar a foto a alguém.
 */
final readonly class SelfieDoSignatario
{
    public function __construct(
        private ArmazenamentoDeArquivos $armazenamento,
        private CofreDaSelfie $cofre,
    ) {
    }

    /**
     * O JPEG decifrado da selfie (nulo de quem não tem selfie), só do escritório do documento e conferido pelo sha256
     * gravado na assinatura.
     *
     * @throws DocumentoNaoEncontrado documento de outro escritório (o mesmo 404 do que não existe)
     * @throws SelfieIlegivel         arquivo que sumiu, não abre ou não confere
     * @throws AcaoRecusada           sem a chave da selfie configurada
     */
    public function abrir(Signatario $signatario, Tenant $tenant): ?string
    {
        if ($signatario->getDocumento()->getTenant()->getId() !== $tenant->getId() || $tenant->getId() === null) {
            throw new DocumentoNaoEncontrado();
        }
        $chave = ChavesDeAssinaturaEletronica::selfie($signatario);
        if ($chave === null) {
            return null;
        }

        try {
            $arquivo = $this->armazenamento->ler($chave);
        } catch (ArquivoNaoEncontrado) {
            throw new SelfieIlegivel();
        }
        $jpeg = $this->cofre->decifrar($arquivo, $signatario);
        if (!hash_equals((string) $signatario->getSelfieSha256(), hash('sha256', $jpeg))) {
            throw new SelfieIlegivel();
        }

        return $jpeg;
    }
}
