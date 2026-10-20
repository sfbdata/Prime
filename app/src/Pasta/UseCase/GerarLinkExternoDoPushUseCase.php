<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\LinkExternoDoPushOutput;
use App\Pasta\Entity\LinkExternoDoPush;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\LinkExternoDoPushException;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Repository\LinkExternoDoPushRepository;
use App\Pasta\Service\ConteudoDoPushCompartilhado;
use App\Pasta\Service\GeradorDeEnderecoDoLinkExterno;
use App\Pasta\Service\MontadorDeLinksExternos;
use App\Processo\Entity\Processo;
use Psr\Clock\ClockInterface;

/**
 * "Gerar e copiar link" do modal do link externo do Push (spec push-link-externo §2.1).
 *
 * Quem: qualquer usuário que vê a aba Push da pasta (o guarda de VER a pasta é do controller).
 * O quê: um endereço público, válido por 30 dias, que mostra a terceiros o andamento sem login.
 * Recusa, com o texto do desenho: pasta sem processo, cancelada (ou excluída), arquivada ou sem
 * movimentação. "Incluir Análises IA" sem nenhuma análise compartilhável vira `false`. O nome do
 * cliente só entra no endereço quando o link vai mostrá-lo.
 */
final class GerarLinkExternoDoPushUseCase
{
    public function __construct(
        private readonly LinkExternoDoPushRepository $links,
        private readonly ConteudoDoPushCompartilhado $conteudo,
        private readonly GeradorDeEnderecoDoLinkExterno $gerador,
        private readonly MontadorDeLinksExternos $montador,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(
        Pasta $pasta,
        User $usuario,
        bool $exigirCodigo,
        bool $mostrarCliente,
        bool $incluirAnalises,
    ): LinkExternoDoPushOutput {
        $tenant = $this->tenantDaPasta($pasta);
        $processo = $this->exigirCompartilhavel($pasta, $tenant);
        $agora = $this->relogio->now();

        $link = new LinkExternoDoPush(
            tenant: $tenant,
            pasta: $pasta,
            processo: $processo,
            endereco: $this->gerador->gerar(
                $mostrarCliente ? $this->conteudo->nomeDoCliente($pasta) : null,
                $processo->getNumeroProcesso(),
            ),
            codigo: $exigirCodigo ? $this->gerador->gerarCodigo() : null,
            mostrarCliente: $mostrarCliente,
            incluirAnalises: $incluirAnalises && $this->conteudo->analisesCompartilhaveis($pasta, $tenant) !== [],
            criadoPor: $usuario,
            criadoEm: $agora,
        );
        $this->links->salvar($link, true);

        return $this->montador->montarUm($link, $tenant, $agora);
    }

    private function tenantDaPasta(Pasta $pasta): Tenant
    {
        $tenant = $pasta->getTenant();
        if ($tenant === null) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }

        return $tenant;
    }

    private function exigirCompartilhavel(Pasta $pasta, Tenant $tenant): Processo
    {
        $motivo = $this->conteudo->motivoDoBloqueio($pasta, $tenant);
        $processo = $pasta->getProcessoPrincipal();
        if ($motivo !== null || $processo === null) {
            throw LinkExternoDoPushException::bloqueio($motivo ?? LinkExternoDoPushException::SEM_PROCESSO);
        }

        return $processo;
    }
}
