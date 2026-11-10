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
 * A geolocalização (E7), a tela cheia, os sons e o lembrete de saída (E8), o comprovante em imagem (E15), os alertas
 * de intervalo (E11) e o aceite mensal da folha (E16) têm a camada Escritório: as outras chaves entram nas etapas das
 * suas funções (E18), para ninguém ligar função que ainda não existe.
 */
final class FuncoesDoPonto
{
    public const GEO = 'ponto-geo';
    public const COMPROVANTE = 'ponto-comprovante';
    public const ACEITE = 'ponto-aceite';

    /** Alertas de intervalo (E11, P21): a janela "Antes de registrar" e o aviso do intervalo no cartão. */
    public const ALERTAS = 'ponto-alertas';
    /** A validação em tela cheia (P79). Desligada, o resultado da batida vira aviso flutuante (P80). */
    public const ANIMACAO = 'ponto-animacao';
    /** Os sons de confirmação e de erro (P81). Desligada, some o botão de som e nada toca. */
    public const SONS = 'ponto-sons';
    /** O lembrete de saída (P83). Desligada, some "Saída prevista" e não há aviso, som nem notificação do lembrete. */
    public const LEMBRETE = 'ponto-lembrete';

    /** Quem decide a camada Escritório: a permissão de gerir o ponto, além do primário e do Master (P67; P58 não). */
    public const PERMISSAO_DE_CONFIGURAR = 'admin.ponto.manage';

    /**
     * As funções com a chave "Escritório", na ordem da aba Configurações. Título, ícone e ajuda da aba vêm do desenho
     * (`FEATS`, L414 a L423); o rótulo do Master ("Ponto: geolocalização") mora no CatalogoDeRecursos.
     *
     * @var array<string, array{titulo: string, icone: string, ajuda: string}>
     */
    public const COM_CAMADA_ESCRITORIO = [
        self::ANIMACAO => [
            'titulo' => 'Validação em tela cheia',
            'icone'  => 'bi-clock-history',
            'ajuda'  => 'Relógio completando a volta e confirmação ocupando toda a tela.',
        ],
        self::SONS => [
            'titulo' => 'Sons de confirmação e erro',
            'icone'  => 'bi-volume-up',
            'ajuda'  => 'Plim no registro e alerta diferente quando o ponto falha.',
        ],
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
        self::LEMBRETE => [
            'titulo' => 'Lembrete de saída',
            'icone'  => 'bi-bell',
            'ajuda'  => 'Avisa quando passar do horário previsto e a saída não foi registrada.',
        ],
        self::ALERTAS => [
            'titulo' => 'Alertas de intervalo',
            'icone'  => 'bi-exclamation-triangle',
            'ajuda'  => 'Avisa antes da batida sobre intervalo, interjornada e descanso semanal.',
        ],
        self::ACEITE => [
            'titulo' => 'Aceite mensal da folha',
            'icone'  => 'bi-pen',
            'ajuda'  => 'O colaborador confirma ou registra ressalva na folha fechada.',
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
