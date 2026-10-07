<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\MencionavelOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\Service\SimilaridadeDeNomes;
use App\Repository\UserRepository;
use App\Service\PermissionChecker;

/**
 * Autocompletar do "@" no Registro da pasta (item 20b): quem pode ser mencionado.
 *
 * Quem pergunta: alguém que VÊ a pasta (é quem escreve no Registro). O quê: os colegas que a
 * menção pode alcançar — e só eles, porque mencionar é notificar, e notificar quem não abre a
 * pasta seria mandar um link para uma tela negada (a mesma regra do P5 na resposta).
 *
 * Entra na lista, nesta ordem de filtros:
 *  1. vínculo ATIVO neste escritório e usuário ativo (`findColaboradoresAtivosPorTenant` — a
 *     consulta já é presa ao tenant da pasta; nada de outro escritório chega aqui);
 *  2. não é quem pergunta (mencionar a si mesmo não notifica ninguém);
 *  3. o nome casa com o termo: o nome inteiro ou qualquer palavra dele COMEÇA com o termo, sem
 *     acento e sem caixa (desenho `menAbrir`: `split(' ').some(p => p.startsWith(nq))`). Termo
 *     vazio (só "@") lista todo mundo;
 *  4. tem acesso de leitura a ESTA pasta (`canAccessResource` VIEW, a checagem da `pasta_show`).
 * Ordem alfabética (a da consulta), no máximo {@see LIMITE} — o desenho mostra 7.
 *
 * Guardas antes de tudo: pasta do escritório da sessão (404) e quem pergunta pode vê-la (403).
 */
final class ListarMencionaveisDaPastaUseCase
{
    public const LIMITE = 7;
    public const TERMO_MAXIMO = 40;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /** @return list<MencionavelOutput> */
    public function executar(Pasta $pasta, User $solicitante, Tenant $tenant, string $termo): array
    {
        if (!self::mesmoTenant($pasta->getTenant(), $tenant)) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }

        $pastaId = (int) $pasta->getId();
        if (!$this->permissionChecker->canAccessResource($solicitante, $tenant, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_VIEW)) {
            throw new SemPermissaoParaVerPastaException('Sem permissão para ver esta pasta.');
        }

        $termo = SimilaridadeDeNomes::normalizar(trim(mb_substr($termo, 0, self::TERMO_MAXIMO)));

        $escolhidos = [];
        foreach ($this->userRepository->findColaboradoresAtivosPorTenant($tenant) as $colega) {
            if (count($escolhidos) >= self::LIMITE) {
                break;
            }

            $nome = trim((string) $colega->getFullName());
            if ($nome === '' || !$colega->isActive() || self::mesmoUsuario($colega, $solicitante) || !self::casa($nome, $termo)) {
                continue;
            }

            if (!$this->permissionChecker->canAccessResource($colega, $tenant, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_VIEW)) {
                continue;
            }

            $escolhidos[] = $colega;
        }

        if ($escolhidos === []) {
            return [];
        }

        $cargos = $this->userRepository->findCargoPorColaboradores($tenant);
        $fotos  = $this->userRepository->findFotoPorColaboradores($tenant);

        return array_map(static function (User $colega) use ($cargos, $fotos): MencionavelOutput {
            $id   = (int) $colega->getId();
            $nome = trim((string) $colega->getFullName());

            return new MencionavelOutput($id, $nome, MencionavelOutput::iniciaisDe($nome), $cargos[$id] ?? null, $fotos[$id] ?? null);
        }, $escolhidos);
    }

    private static function casa(string $nome, string $termoNormalizado): bool
    {
        if ($termoNormalizado === '') {
            return true;
        }

        $nomeNormalizado = SimilaridadeDeNomes::normalizar($nome);
        if (str_starts_with($nomeNormalizado, $termoNormalizado)) {
            return true;
        }

        foreach (preg_split('/\s+/u', $nomeNormalizado) ?: [] as $palavra) {
            if ($palavra !== '' && str_starts_with($palavra, $termoNormalizado)) {
                return true;
            }
        }

        return false;
    }

    private static function mesmoUsuario(User $a, User $b): bool
    {
        return $a === $b || ($a->getId() !== null && $a->getId() === $b->getId());
    }

    /** Mesma instância ou mesmo id (proxy do ORM). Sem tenant ou sem id, a dúvida recusa. */
    private static function mesmoTenant(?Tenant $a, Tenant $b): bool
    {
        if ($a === null) {
            return false;
        }

        return $a === $b || ($a->getId() !== null && $a->getId() === $b->getId());
    }
}
