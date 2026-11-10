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
 * A geolocalização (E7) e o comprovante em imagem (E15) têm a camada Escritório: as outras chaves entram nas etapas das
 * suas funções (E8, E11, E16, E18), para ninguém ligar função que ainda não existe.
 */
final class FuncoesDoPonto
{
    public const GEO = 'ponto-geo';
    public const COMPROVANTE = 'ponto-comprovante';

    /** Quem decide a camada Escritório: a permissão de gerir o ponto, além do primário e do Master (P67; P58 não). */
    public const PERMISSAO_DE_CONFIGURAR = 'admin.ponto.manage';

    /**
     * As funções com a chave "Escritório", na ordem da aba Configurações. Título, ícone e ajuda da aba vêm do desenho
     * (`FEATS`, L414 a L423); o rótulo do Master ("Ponto: geolocalização") mora no CatalogoDeRecursos.
     *
     * @var array<string, array{titulo: string, icone: string, ajuda: string}>
     */
    public const COM_CAMADA_ESCRITORIO = [
        self::COMPROVANTE => [
            'titulo' => 'Comprovante em imagem',
            'icone'  => 'bi-receipt',
            'ajuda'  => "Imagem em alta definição com código de validação, QR Code, marca d'água e padrões de segurança.",
        ],
        self::GEO => [
            'titulo' => 'Geolocalização',
            'icone'  => 'bi-geo-alt',
            'ajuda'  => 'Exige que o registro seja feito dentro do raio de uma sede.',
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
