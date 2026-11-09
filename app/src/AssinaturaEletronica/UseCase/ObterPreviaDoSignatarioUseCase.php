<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Service\PaginaDoSignatario;
use App\Entity\Tenant\Tenant;
use Psr\Clock\ClockInterface;

/**
 * "Tela do signatário" do detalhe (contrato da etapa 2, decisão E): o `Publico` de um signatário de documento do
 * escritório, em modo prévia. Só leitura: nada é gravado, nenhum código, nenhum evento.
 */
final readonly class ObterPreviaDoSignatarioUseCase
{
    public function __construct(
        private DocumentoParaAssinarRepository $documentos,
        private PaginaDoSignatario $pagina,
        private ClockInterface $relogio,
    ) {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws DocumentoNaoEncontrado
     */
    public function executar(Tenant $tenant, int $id, int $ordem): array
    {
        $signatario = self::signatario($this->documentos, $tenant, $id, $ordem);

        return $this->pagina->previa($signatario, \DateTimeImmutable::createFromInterface($this->relogio->now()));
    }

    /**
     * O signatário de ordem `$ordem` de um documento do escritório, mesmo na Lixeira.
     *
     * @throws DocumentoNaoEncontrado
     */
    public static function signatario(DocumentoParaAssinarRepository $documentos, Tenant $tenant, int $id, int $ordem): Signatario
    {
        $documento = $documentos->doEscritorioMesmoNaLixeira($id, $tenant) ?? throw new DocumentoNaoEncontrado();
        foreach ($documento->getSignatarios() as $signatario) {
            if ($signatario->getOrdem() === $ordem) {
                return $signatario;
            }
        }

        throw new DocumentoNaoEncontrado();
    }
}
