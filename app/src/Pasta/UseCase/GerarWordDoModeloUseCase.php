<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\ExportarPecaTextoOutput;
use App\Pasta\Entity\ModeloDePeca;
use App\Pasta\Service\PreenchedorDeModelo;
use App\Pasta\Service\SanitizadorDeModeloDePeca;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * "Word" do desenho 02: baixa o modelo (com as variáveis como estão) ou a minuta preenchida, sem
 * guardar nada na pasta. Word de verdade (.docx), não o .doc em HTML do protótipo.
 */
final class GerarWordDoModeloUseCase
{
    public function __construct(
        private readonly PreenchedorDeModelo $preenchedor,
        private readonly SanitizadorDeModeloDePeca $sanitizador,
        private readonly ExportarPecaTextoUseCase $exportar,
    ) {
    }

    /** @param array<string, string> $valores */
    public function executar(ModeloDePeca $modelo, Tenant $tenant, bool $preenchido, array $valores): ExportarPecaTextoOutput
    {
        if ($modelo->getTenant() !== $tenant) {
            throw new AccessDeniedException('Modelo não pertence ao escritório.');
        }

        $corpo = $preenchido ? $this->preenchedor->preencher($modelo->getCorpo(), $valores) : $modelo->getCorpo();

        return $this->exportar->docxDeHtml($this->sanitizador->sanitizar($corpo), $modelo->getTitulo());
    }
}
