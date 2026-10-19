<?php

declare(strict_types=1);

namespace App\Expediente\UseCase;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Permission\ResourceAccess;
use App\Entity\Tenant\Tenant;
use App\Expediente\DTO\BuscaRapidaOutput;
use App\Expediente\DTO\ResultadoDaBuscaRapidaOutput;
use App\Pasta\DTO\OcorrenciaDaBuscaRapida;
use App\Pasta\Repository\PastaRepository;
use App\Repository\ResourceAccessRepository;
use App\Service\PermissionChecker;
use App\Twig\DocumentoBrExtension;
use App\Twig\NumeroProcessoExtension;

/**
 * Busca rápida da pasta (Ctrl + K na página da pasta, desenho 02 EXPEDIENTES L405-421).
 *
 * Quem: qualquer pessoa do escritório com o módulo Pastas, de dentro de uma pasta. O que quer: pular
 * para outra pasta sem voltar à Lista, achando-a pelo número, pelo cliente (nome ou CPF/CNPJ) ou por
 * um processo dela. Campo vazio: as pastas mais recentes. Só entra o que a pessoa pode VER, e no
 * máximo 8 resultados. Não muda nada no sistema.
 */
final class BuscarRapidoUseCase
{
    public const LIMITE = 8;

    /** Ocorrências lidas do banco antes do filtro de visibilidade (que roda fora do SQL). */
    public const CANDIDATOS = 50;

    private const TAMANHO_MAXIMO_DO_TERMO = 100;

    public function __construct(
        private readonly PastaRepository $pastaRepository,
        private readonly PermissionChecker $permissionChecker,
        private readonly ResourceAccessRepository $resourceAccessRepository,
        private readonly DocumentoBrExtension $documentoBr,
        private readonly NumeroProcessoExtension $numeroCnj,
    ) {
    }

    public function executar(string $termo, User $user, Tenant $tenant): BuscaRapidaOutput
    {
        $termo       = mb_substr(trim($termo), 0, self::TAMANHO_MAXIMO_DO_TERMO);
        $ocorrencias = $this->pastaRepository->buscarParaBuscaRapida($termo, $tenant, self::CANDIDATOS);
        $visiveis    = array_slice($this->filtrarVisiveis($user, $tenant, $ocorrencias), 0, self::LIMITE);

        return new BuscaRapidaOutput(
            array_map($this->montarResultado(...), $visiveis),
            $this->permissionChecker->isAdministradorDoEscritorio($user, $tenant),
        );
    }

    /**
     * REGRA PROVISÓRIA de visibilidade, isolada aqui de propósito: é a mesma decisão do `pasta_show`
     * (`PermissionChecker::canAccessResource(..., RESOURCE_PASTA, id, ACTION_VIEW)`), só que em lote.
     * Quem tem `resources.pasta.view` no perfil (ou é administrador do escritório / super admin, que
     * `hasPermission` já cobre) vê todas; os demais só as pastas com acesso próprio de visualização
     * (`ResourceAccess`), numa consulta só.
     *
     * SERÁ TROCADA pelo serviço `App\Pasta\Service\AcessoAPasta` (sessão b5, spec
     * docs/specs/pasta-cadeado.md, D1), pelos métodos `niveisEmLote(User, array $pastas)` e
     * `restringirAoVisivel(QueryBuilder, aliasPasta, User)`. A regra que entra no lugar desta:
     * - nível Visualizar ou Editar: a pasta aparece normal;
     * - nível Oculto: a pasta APARECE, mas só com número da pasta, cliente e CPF e o cadeado
     *   (`restrito: true` no resultado), casa SÓ por número da pasta, nome do cliente e CPF (nunca
     *   por processo ou assunto), e ao abrir vai para a tela "Pasta trancada" com o pedido de acesso.
     *
     * @param list<OcorrenciaDaBuscaRapida> $ocorrencias
     *
     * @return list<OcorrenciaDaBuscaRapida>
     */
    private function filtrarVisiveis(User $user, Tenant $tenant, array $ocorrencias): array
    {
        $permissao = sprintf('resources.%s.%s', AccessRequest::RESOURCE_PASTA, AccessRequest::ACTION_VIEW);
        if ($ocorrencias === [] || $this->permissionChecker->hasPermission($user, $tenant, $permissao)) {
            return $ocorrencias;
        }

        $comAcessoProprio = $this->pastasComAcessoProprio($user, $ocorrencias);

        return array_values(array_filter(
            $ocorrencias,
            static fn (OcorrenciaDaBuscaRapida $o): bool => isset($comAcessoProprio[$o->pastaId]),
        ));
    }

    /**
     * @param list<OcorrenciaDaBuscaRapida> $ocorrencias
     *
     * @return array<int, true> id da pasta => true
     */
    private function pastasComAcessoProprio(User $user, array $ocorrencias): array
    {
        $ids = array_values(array_unique(array_map(static fn (OcorrenciaDaBuscaRapida $o): int => $o->pastaId, $ocorrencias)));

        /** @var list<ResourceAccess> $acessos */
        $acessos = $this->resourceAccessRepository->findBy([
            'user'         => $user,
            'resourceType' => AccessRequest::RESOURCE_PASTA,
            'resourceId'   => $ids,
        ]);

        $permitidas = [];
        foreach ($acessos as $acesso) {
            if ($acesso->allows(AccessRequest::ACTION_VIEW)) {
                $permitidas[(int) $acesso->getResourceId()] = true;
            }
        }

        return $permitidas;
    }

    private function montarResultado(OcorrenciaDaBuscaRapida $o): ResultadoDaBuscaRapidaOutput
    {
        [$titulo, $subtitulo] = match ($o->grupo) {
            OcorrenciaDaBuscaRapida::GRUPO_CLIENTE  => $this->textosDoCliente($o),
            OcorrenciaDaBuscaRapida::GRUPO_PROCESSO => $this->textosDoProcesso($o),
            default                                 => $this->textosDaPasta($o),
        };

        return new ResultadoDaBuscaRapidaOutput($o->grupo, $titulo, $subtitulo, $o->pastaId, $o->trancada);
    }

    /** @return array{0: string, 1: string} "Pasta 1176" / ação, ou o cliente quando não há ação */
    private function textosDaPasta(OcorrenciaDaBuscaRapida $o): array
    {
        return [
            'Pasta ' . $o->nup,
            $o->nomeAcao ?? $o->nomeDoClientePrincipal ?? $o->nomeClienteDaPasta ?? '',
        ];
    }

    /** @return array{0: string, 1: string} nome do cliente / "CPF 318.774.902-15 · Pasta 1176" */
    private function textosDoCliente(OcorrenciaDaBuscaRapida $o): array
    {
        $documento = $o->documento === null
            ? ''
            : $this->documentoBr->documentoBrRotulo($o->documento) . ' ' . $this->documentoBr->documentoBr($o->documento) . ' · ';

        return [(string) $o->nome, $documento . 'Pasta ' . $o->nup];
    }

    /** @return array{0: string, 1: string} número CNJ / "Indenização por danos morais · Pasta 1176" */
    private function textosDoProcesso(OcorrenciaDaBuscaRapida $o): array
    {
        $acao = $o->nomeAcao ?? $o->classeProcessual;

        return [
            $this->numeroCnj->numeroCnj($o->numeroProcesso),
            ($acao !== null ? $acao . ' · ' : '') . 'Pasta ' . $o->nup,
        ];
    }
}
