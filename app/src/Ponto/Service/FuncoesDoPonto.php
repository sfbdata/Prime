<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\Service\RecursosDoSistema;
use App\Ponto\Repository\ConfiguracaoDoPontoRepository;
use App\Service\PermissionChecker;

/**
 * As funções do Ponto que se ligam e desligam em duas camadas (spec docs/specs/ponto-desenho-0811.md, P95 e seção 7):
 * "Global" (o Master, para todos os escritórios, em `recurso_estado`) e "Escritório" (o administrador do ponto, só
 * no seu escritório, em `ponto_config_escritorio`). A função vale quando as duas estão ligadas; o Global desligado
 * trava a do escritório. Chave sem registro em qualquer das camadas vale ligada (P96).
 *
 * Na E7 só a geolocalização tinha a camada Escritório; a E11 pôs os alertas de intervalo. As outras chaves entram nas
 * etapas das suas funções (E8, E15, E16, E18), para ninguém ligar função que ainda não existe.
 */
final class FuncoesDoPonto
{
    public const GEO = 'ponto-geo';

    /** Alertas de intervalo (E11, P21): a janela "Antes de registrar" e o aviso do intervalo no cartão. */
    public const ALERTAS = 'ponto-alertas';

    /** Quem decide a camada Escritório: a permissão de gerir o ponto, além do primário e do Master (P67; P58 não). */
    public const PERMISSAO_DE_CONFIGURAR = 'admin.ponto.manage';

    /**
     * As funções com a chave "Escritório", na ordem da aba Configurações. Título, ícone e ajuda da aba vêm do desenho
     * (`FEATS`, L414 a L423); o rótulo do Master ("Ponto: geolocalização") mora no CatalogoDeRecursos.
     *
     * @var array<string, array{titulo: string, icone: string, ajuda: string}>
     */
    public const COM_CAMADA_ESCRITORIO = [
        self::GEO => [
            'titulo' => 'Geolocalização',
            'icone'  => 'bi-geo-alt',
            'ajuda'  => 'Exige que o registro seja feito dentro do raio de uma sede.',
        ],
        self::ALERTAS => [
            'titulo' => 'Alertas de intervalo',
            'icone'  => 'bi-exclamation-triangle',
            'ajuda'  => 'Avisa antes da batida sobre intervalo, interjornada e descanso semanal.',
        ],
    ];

    public function __construct(
        private readonly RecursosDoSistema $recursos,
        private readonly ConfiguracaoDoPontoRepository $configuracoes,
        private readonly PermissionChecker $permissoes,
    ) {
    }

    /** O valor efetivo da função no escritório: Global E Escritório. */
    public function vale(string $chave, Tenant $tenant): bool
    {
        return $this->liberadaPeloMaster($chave) && $this->ligadaNoEscritorio($chave, $tenant);
    }

    /** A camada Global: o Master liberou a função para todos os escritórios. */
    public function liberadaPeloMaster(string $chave): bool
    {
        return $this->recursos->estaAtivo($chave);
    }

    /** A camada Escritório, sem olhar o Global. Sem linha ou sem a chave no mapa, ligada. */
    public function ligadaNoEscritorio(string $chave, Tenant $tenant): bool
    {
        return $this->configuracoes->doTenant($tenant)?->estaLigada($chave) ?? true;
    }

    public function temCamadaEscritorio(string $chave): bool
    {
        return array_key_exists($chave, self::COM_CAMADA_ESCRITORIO);
    }

    /** Pode mexer na camada Escritório deste escritório (e ver a aba Configurações)? */
    public function podeConfigurar(User $quem, Tenant $tenant): bool
    {
        return $this->permissoes->canAdminister($quem, $tenant, self::PERMISSAO_DE_CONFIGURAR);
    }
}
