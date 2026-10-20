<?php

declare(strict_types=1);

namespace App\Cliente\UseCase;

use App\Cliente\DTO\DocumentoEncontradoNaPasta;
use App\Cliente\DTO\InteligenciaCadastralOutput;
use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Cliente\Exception\ClienteNaoEncontradoException;
use App\Cliente\Service\AlertasDaInteligenciaCadastral;
use App\Cliente\Service\DocumentoDoCliente;
use App\Cliente\Service\RotulosDaInteligenciaCadastral;
use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Repository\PastaRepository;
use App\Repository\ClienteDocumentoRepository;
use App\Service\PermissionChecker;
use App\Shared\Service\DigitoVerificador;

/**
 * Monta o bloco "Inteligência cadastral" da aba Documentos do cadastro (F-A; spec docs/specs/cliente-inteligencia-cadastral.md).
 *
 * Quem: quem abre a aba Documentos de um cliente que pode ver (o controller confere o escritório e a visibilidade).
 * O quê: os alertas de identidade ({@see AlertasDaInteligenciaCadastral}) e os documentos de identificação e
 * comprovante que já estão nas pastas do cliente e ainda não foram vinculados ao cadastro. Só aparece com CPF (PF) ou
 * CNPJ (PJ) válido. Só lê: nada muda no cadastro.
 */
final class MontarInteligenciaCadastralUseCase
{
    public function __construct(
        private readonly AlertasDaInteligenciaCadastral $alertas,
        private readonly PastaRepository $pastas,
        private readonly PastaDocumentoRepository $documentosDaPasta,
        private readonly ClienteDocumentoRepository $documentosDoCliente,
        private readonly PermissionChecker $permissoes,
    ) {
    }

    public function executar(Cliente $cliente, User $usuario, Tenant $tenant): InteligenciaCadastralOutput
    {
        if ($cliente->getTenant()?->getId() !== $tenant->getId()) {
            throw new ClienteNaoEncontradoException((int) $cliente->getId());
        }
        $digitos = self::documentoValido($cliente);
        if ($digitos === null) {
            return InteligenciaCadastralOutput::desligado();
        }
        $documentos = $this->documentosDaPasta->identificacaoEComprovanteDasPastas($this->pastasVisiveis($cliente, $usuario, $tenant), $tenant);

        return new InteligenciaCadastralOutput(
            true,
            RotulosDaInteligenciaCadastral::subtitulo(\count($documentos), self::quantasPastas($documentos)),
            $this->alertas->de($cliente, $digitos, $usuario, $tenant),
            $this->encontrados($cliente, $documentos, $usuario, $tenant),
            RotulosDaInteligenciaCadastral::nota($cliente instanceof ClientePJ),
        );
    }

    /** Os dígitos do CPF/CNPJ quando o dígito verificador confere; senão null (o bloco não aparece). */
    private static function documentoValido(Cliente $cliente): ?string
    {
        $documento = match (true) {
            $cliente instanceof ClientePF => $cliente->getCpf(),
            $cliente instanceof ClientePJ => $cliente->getCnpj(),
            default => '',
        };
        $valido = $cliente instanceof ClientePF ? DigitoVerificador::cpfValido($documento) : DigitoVerificador::cnpjValido($documento);

        return $valido ? DocumentoDoCliente::digitos($documento) : null;
    }

    /** @return list<Pasta> as pastas em que o cliente está vinculado e que o usuário pode ver */
    private function pastasVisiveis(Cliente $cliente, User $usuario, Tenant $tenant): array
    {
        return array_values(array_filter(
            $this->pastas->findByCliente($cliente, $tenant),
            fn (Pasta $p): bool => $p->getTenant()?->getId() === $tenant->getId()
                && $this->permissoes->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $p->getId(), AccessRequest::ACTION_VIEW),
        ));
    }

    /**
     * @param list<PastaDocumento> $documentos
     *
     * @return list<DocumentoEncontradoNaPasta>
     */
    private function encontrados(Cliente $cliente, array $documentos, User $usuario, Tenant $tenant): array
    {
        $vinculados = $this->documentosDoCliente->idsDePastaDocumentoVinculados($cliente, $tenant);
        $podeVincular = $this->permissoes->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_CLIENTE, (int) $cliente->getId(), AccessRequest::ACTION_EDIT);
        $linhas = [];
        foreach ($documentos as $d) {
            if (!\in_array((int) $d->getId(), $vinculados, true)) {
                $linhas[] = new DocumentoEncontradoNaPasta(
                    (int) $d->getId(),
                    $d->getNomeOriginal(),
                    $d->getCategoria(),
                    RotulosDaInteligenciaCadastral::categoria($d->getCategoria()),
                    self::rotuloDaPasta($d->getPasta()),
                    $d->getCarregadoEm()->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('d/m/Y'),
                    $podeVincular,
                );
            }
        }

        return $linhas;
    }

    /** @param list<PastaDocumento> $documentos */
    private static function quantasPastas(array $documentos): int
    {
        return \count(array_unique(array_map(static fn (PastaDocumento $d): int => (int) $d->getPasta()?->getId(), $documentos)));
    }

    private static function rotuloDaPasta(?Pasta $pasta): string
    {
        $nup = $pasta?->getNup();

        return 'Pasta ' . ($nup !== null && $nup !== '' ? $nup : '#' . (int) $pasta?->getId());
    }
}
