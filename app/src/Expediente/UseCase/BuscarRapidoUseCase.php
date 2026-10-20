<?php

declare(strict_types=1);

namespace App\Expediente\UseCase;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Expediente\DTO\BuscaRapidaOutput;
use App\Expediente\DTO\ResultadoDaBuscaRapidaOutput;
use App\Pasta\DTO\OcorrenciaDaBuscaRapida;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Repository\ResourceAccessRepository;
use App\Service\PermissionChecker;
use App\Twig\DocumentoBrExtension;
use App\Twig\NumeroProcessoExtension;

/**
 * Busca rápida da pasta (Ctrl + K na página da pasta, desenho 02 EXPEDIENTES L405-421).
 *
 * Quem: qualquer pessoa do escritório com o módulo Pastas, de dentro de uma pasta. O que quer: pular
 * para outra pasta sem voltar à Lista, achando-a pelo número, pelo cliente (nome ou CPF/CNPJ) ou por
 * um processo dela. Campo vazio: as pastas mais recentes. No máximo 8 resultados. Não muda nada.
 *
 * O que entra (spec pasta-cadeado D1):
 *  - pasta fora do escopo da pessoa (ela não a veria nem sem cadeado): não aparece;
 *  - pasta OCULTA pelo cadeado para ela: aparece com `restrito`, casa só por número da pasta e cliente
 *    (nome e CPF/CNPJ), nunca pelo processo, e o subtítulo não traz a ação; abrir leva à tela trancada;
 *  - as demais: normais.
 */
final class BuscarRapidoUseCase
{
    public const LIMITE = 8;

    private const TAMANHO_MAXIMO_DO_TERMO = 100;

    public function __construct(
        private readonly PastaRepository $pastaRepository,
        private readonly PermissionChecker $permissionChecker,
        private readonly ResourceAccessRepository $resourceAccessRepository,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly DocumentoBrExtension $documentoBr,
        private readonly NumeroProcessoExtension $numeroCnj,
    ) {
    }

    public function executar(string $termo, User $user, Tenant $tenant): BuscaRapidaOutput
    {
        $termo  = mb_substr(trim($termo), 0, self::TAMANHO_MAXIMO_DO_TERMO);
        $escopo = $this->escopo($user, $tenant);
        if ($escopo === []) {
            return new BuscaRapidaOutput([]);
        }

        // Uma consulta para todas as pastas ocultas da pessoa (vazio para primário e Master): o SQL da
        // busca decide com ela o que casa e o que sai de cada pasta. Sem N+1.
        $ocultas     = $this->acessoAPasta->idsOcultosPara($user, (int) $tenant->getId());
        $ocorrencias = $this->pastaRepository->buscarParaBuscaRapida($termo, $tenant, self::LIMITE, $escopo, $ocultas);
        // Defesa: o SQL já não traz processo de pasta oculta; se trouxesse, ele não sai daqui.
        $ocorrencias = array_values(array_filter(
            $ocorrencias,
            static fn (OcorrenciaDaBuscaRapida $o): bool => !($o->restrito && $o->grupo === OcorrenciaDaBuscaRapida::GRUPO_PROCESSO),
        ));

        return new BuscaRapidaOutput(array_map($this->montarResultado(...), array_slice($ocorrencias, 0, self::LIMITE)));
    }

    /**
     * O escopo da pessoa: as pastas que ela veria se estivessem livres, em lote. É o
     * `AcessoAPasta::veriaSemCadeado` (o `canAccessResource` de ver a pasta sem o teto do cadeado), o
     * mesmo critério com que o `pasta_show` decide entre a tela trancada e a recusa. Devolve null quando
     * ela alcança todas as pastas do escritório (`resources.pasta.view` no perfil; o administrador do
     * escritório e o super admin já passam por `hasPermission`) ou os ids com acesso próprio de ver
     * (`ResourceAccess` com can_view, deste escritório), numa consulta só. O cadeado vem à parte, pelo
     * `idsOcultosPara`. O repositório aplica os dois DENTRO do SQL, antes do LIMIT.
     *
     * @return list<int>|null
     */
    private function escopo(User $user, Tenant $tenant): ?array
    {
        $permissao = sprintf('resources.%s.%s', AccessRequest::RESOURCE_PASTA, AccessRequest::ACTION_VIEW);
        if ($this->permissionChecker->hasPermission($user, $tenant, $permissao)) {
            return null;
        }

        return array_values(array_unique($this->resourceAccessRepository->idsComAcesso($user, $tenant, AccessRequest::RESOURCE_PASTA, AccessRequest::ACTION_VIEW)));
    }

    private function montarResultado(OcorrenciaDaBuscaRapida $o): ResultadoDaBuscaRapidaOutput
    {
        [$titulo, $subtitulo] = match ($o->grupo) {
            OcorrenciaDaBuscaRapida::GRUPO_CLIENTE  => $this->textosDoCliente($o),
            OcorrenciaDaBuscaRapida::GRUPO_PROCESSO => $this->textosDoProcesso($o),
            default                                 => $this->textosDaPasta($o),
        };

        return new ResultadoDaBuscaRapidaOutput($o->grupo, $titulo, $subtitulo, $o->pastaId, $o->nup, $o->restrito);
    }

    /**
     * @return array{0: string, 1: string} "Pasta 1176" / ação, ou o cliente quando não há ação (ou quando
     *                                     a pasta é oculta: dela só o cliente, D1)
     */
    private function textosDaPasta(OcorrenciaDaBuscaRapida $o): array
    {
        return [
            'Pasta ' . $o->nup,
            ($o->restrito ? null : $o->nomeAcao) ?? $o->nomeDoClientePrincipal ?? $o->nomeClienteDaPasta ?? '',
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
