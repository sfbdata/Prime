<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Edita a Ação da pasta (`Pasta.nomeAcao`) pela aba Processo (decisão do dono de 23/10/2026: o desenho 02 diz
 * "Ação · vem do menu Processo", então é lá que ela se corrige; o "Editar dados" passa a só mostrar).
 *
 * Quem: quem pode EDITAR a pasta. O quê: trocar ou limpar a Ação. Por quê: a Ação aparece no cartão do processo
 * principal, no resumo copiado e no nome da pasta no Drive; quem vê o erro na aba Processo corrige ali.
 *
 * Guardas, nesta ordem (nada é gravado em nenhuma recusa):
 *   1. a pasta é do escritório da sessão, senão {@see PastaDeOutroEscritorioException} (404);
 *   2. o usuário pode editar a pasta (`canAccessResource` com `edit`, que já traz o teto do cadeado),
 *      senão {@see SemPermissaoParaEditarPastaException} (403);
 *   3. o texto cabe na coluna (255), senão `\InvalidArgumentException` (422).
 *
 * Campo vazio limpa a Ação. Mesma Ação de antes (depois do trim e das maiúsculas da entidade) não grava nada.
 * Com mudança, o Registro da pasta ganha o cartão do sistema "Dados da pasta: ação alterada pela aba Processo:
 * "A" → "B"." ({@see RegistroDoSistemaNaPasta}, tipo `pasta`, o mesmo do "Editar dados") e a troca do campo entra
 * no `audit_log` pelo subscriber (`Pasta` é `Auditavel`).
 */
final class EditarAcaoDaPastaUseCase
{
    public const TAMANHO_MAXIMO = 255;

    private const VAZIO = 'Não informado';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PermissionChecker $permissionChecker,
        private readonly RegistroDoSistemaNaPasta $registro,
    ) {
    }

    /** @return bool true quando a Ação mudou */
    public function executar(Pasta $pasta, ?string $acao, User $usuario, Tenant $tenant): bool
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }

        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_EDIT)) {
            throw new SemPermissaoParaEditarPastaException('Sem permissão para editar esta pasta.');
        }

        // Medida e comparada já em maiúsculas, como o setter grava ("ß" vira "SS").
        $depois = self::normal($acao);
        $depois = $depois !== null ? mb_strtoupper($depois) : null;
        if ($depois !== null && mb_strlen($depois) > self::TAMANHO_MAXIMO) {
            throw new \InvalidArgumentException(sprintf('A ação pode ter no máximo %d caracteres.', self::TAMANHO_MAXIMO));
        }

        $antes = self::normal($pasta->getNomeAcao());
        if ($antes === $depois) {
            return false;
        }
        $pasta->setNomeAcao($depois);

        $this->registro->registrar($pasta, $usuario, 'pasta', sprintf(
            'ação alterada pela aba Processo: "%s" → "%s".',
            $antes ?? self::VAZIO,
            $depois ?? self::VAZIO,
        ));
        $this->em->flush();

        return true;
    }

    private static function normal(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }
}
