<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\DTO\EditarSedeDaSeccionalInput;
use App\Master\Entity\SeccionalOab;
use App\Master\Exception\SeccionalNaoEncontrada;
use App\Master\Exception\SeccionalRecusada;
use App\Master\Repository\SeccionalOabRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Psr\Clock\ClockInterface;

/**
 * Edita a sede de uma seccional da OAB: endereço, telefone e site (spec docs/specs/master-seccionais-oab.md,
 * "Editar sede"; desenho 05, Perfis 1.2, VS 21.10.26, botão "Editar sede" do cartão).
 *
 * Quem dispara: o Master, na janela "Editar sede" do cartão da seccional (ROLE_SUPER_ADMIN no controller).
 * Objetivo: completar a sede que a carga deixou vazia (nada é inventado) ou corrigi-la, com a fonte oficial
 * do CFOAB ao lado.
 * Pré-condições: a UF é uma das 27 (o controller já recusa com 404 as outras) e a linha existe.
 * Fluxo principal: carrega a seccional, confere os tamanhos e o site, troca a sede inteira (campo vazio vira
 * nulo), grava quem e quando, e salva dentro do escopo de plataforma.
 * Fluxos alternativos: linha ausente (banco sem a carga) é 404; endereço acima de 255, telefone acima de 40,
 * site acima de 255 ou sem `https://` são recusados com a mensagem (422) e nada muda.
 * Pós-condições: o cartão passa a mostrar a sede nova para todos os Masters.
 * Auditoria: SeccionalOab é Auditavel; dentro do EscopoDeAuditoriaDePlataforma a linha do audit_log fica sem
 * escritório, só na trilha do Master ("Plataforma (sem conta)").
 */
final class EditarSedeDaSeccionalUseCase
{
    public function __construct(
        private readonly SeccionalOabRepository $repositorio,
        private readonly ClockInterface $relogio,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    public function executar(EditarSedeDaSeccionalInput $input): SeccionalOab
    {
        $seccional = $this->repositorio->buscarPorUf($input->uf) ?? throw new SeccionalNaoEncontrada(SeccionalNaoEncontrada::RECUSA);
        $this->exigirTamanhos($input);
        $this->exigirSiteSeguro($input->site);

        $seccional->editarSede($input->endereco, $input->telefone, $input->site, $input->ator, $this->relogio->now());
        $this->escopoDePlataforma->executar(fn () => $this->repositorio->salvar($seccional, flush: true));

        return $seccional;
    }

    private function exigirTamanhos(EditarSedeDaSeccionalInput $input): void
    {
        if (mb_strlen((string) $input->endereco) > SeccionalOab::ENDERECO_MAXIMO) {
            throw new SeccionalRecusada(SeccionalRecusada::RECUSA_ENDERECO_LONGO, ['limite' => SeccionalOab::ENDERECO_MAXIMO]);
        }

        if (mb_strlen((string) $input->telefone) > SeccionalOab::TELEFONE_MAXIMO) {
            throw new SeccionalRecusada(SeccionalRecusada::RECUSA_TELEFONE_LONGO, ['limite' => SeccionalOab::TELEFONE_MAXIMO]);
        }

        if (mb_strlen((string) $input->site) > SeccionalOab::SITE_MAXIMO) {
            throw new SeccionalRecusada(SeccionalRecusada::RECUSA_SITE_LONGO, ['limite' => SeccionalOab::SITE_MAXIMO]);
        }
    }

    /** Só `https://` com um domínio: o link sai no cartão e abre em outra aba. */
    private function exigirSiteSeguro(?string $site): void
    {
        if ($site === null) {
            return;
        }

        $valido = filter_var($site, \FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($site, \PHP_URL_SCHEME)) === 'https'
            && (string) parse_url($site, \PHP_URL_HOST) !== '';
        if (!$valido) {
            throw new SeccionalRecusada(SeccionalRecusada::RECUSA_SITE_SEM_HTTPS);
        }
    }
}
