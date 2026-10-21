<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\PastaParada;
use App\Pasta\DTO\RelatorioPastasParadasOutput;
use App\Pasta\DTO\ResultadoDoEnvioPastasParadas;
use App\Pasta\Repository\EnvioDoRelatorioPastasParadasRepository;
use App\Pasta\Service\MontagemDoRelatorioPastasParadas;
use App\Pasta\Service\PastasVisiveisNoRelatorio;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Service\PermissionChecker;
use App\Tenant\Service\RegraDosPrimarios;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Envio mensal do relatório de pastas paradas (desenho 02, `paradasEnviar` L4823-4830 e
 * `paradasNotificar` em bluejus-central.js L3238-3246; spec docs/specs/pastas-paradas.md, "Envio
 * automático").
 *
 * Quem: só o comando agendado (não há envio manual no desenho). O quê: avisar cada responsável das
 * pastas DELE que estão paradas há mais de 30 dias, para dizer se vão receber movimentação ou ser
 * arquivadas, e a Controladoria e os primários da lista inteira.
 *
 * O chat da BlueJus I.A não existe: o aviso é a notificação do sino e da Caixa da Central, do tipo
 * `relatorio_pastas_paradas`, com link para a página do relatório. É aviso do sistema: não fala da I.A.
 *
 * Fluxo:
 *  1. só na primeira segunda-feira do mês, a partir das 10h; fora disso não envia e diz por quê;
 *  2. analisa todas as pastas ativas do escritório (sem o financeiro: nenhum aviso fala dele);
 *  3. GRAVA o envio do mês ANTES de avisar, por `INSERT ... ON CONFLICT DO NOTHING`: quem não
 *     gravou não avisa (dois crons não duplicam); gravação e avisos numa transação só;
 *  4. cada responsável com vínculo ativo: as pastas dele QUE ELE PODE VER (o link abre a página com
 *     as pastas visíveis a ele; sem nenhuma, não há aviso); Controladoria e primários, sem repetir: a
 *     lista inteira. Só quem tem o módulo Expediente (é onde o relatório abre).
 *
 * Sem pasta parada, o mês é gravado e ninguém é avisado. `$simular` (o `--dry-run` do comando)
 * faz tudo menos gravar e avisar.
 */
final class EnviarRelatorioPastasParadasUseCase
{
    public const TIPO_NOTIFICACAO = 'relatorio_pastas_paradas';

    public const MENSAGEM_ENVIADO     = 'Relatório de pastas paradas enviado';
    public const MENSAGEM_JA_ENVIADO  = 'O relatório deste mês já foi enviado.';
    public const MENSAGEM_NAO_E_O_DIA = 'Hoje não é a primeira segunda-feira do mês.';
    public const MENSAGEM_CEDO        = 'O envio automático sai a partir das 10h.';

    /** Fim do aviso ao responsável (L3244). */
    public const PEDIDO_AO_RESPONSAVEL = ' Informe se vai movimentar ou arquivar.';

    /** Tamanho máximo do texto de cada aviso (spec, "Avisos"). */
    public const TAMANHO_DO_AVISO = 255;

    public function __construct(
        private readonly MontagemDoRelatorioPastasParadas $montagem,
        private readonly UserTenantRepository $userTenantRepository,
        private readonly RegraDosPrimarios $regraDosPrimarios,
        private readonly EnvioDoRelatorioPastasParadasRepository $envioRepository,
        private readonly NotificacaoService $notificacaoService,
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ClockInterface $clock,
        private readonly PermissionChecker $permissionChecker,
        private readonly PastasVisiveisNoRelatorio $visiveis,
    ) {
    }

    public function executar(Tenant $tenant, bool $simular = false): ResultadoDoEnvioPastasParadas
    {
        $agora  = $this->clock->now();
        $recusa = $this->motivoParaNaoEnviar($tenant, $agora);
        if ($recusa !== null) {
            return ResultadoDoEnvioPastasParadas::naoEnviado($recusa);
        }

        $relatorio = $this->montagem->montar($tenant, null, null, $agora);
        $vinculos  = $this->comExpediente($this->userTenantRepository->findAtivosComPerfilPorTenant($tenant), $tenant);
        $gerais    = array_filter(
            $this->userTenantRepository->findUsuariosDaControladoria($tenant),
            fn (User $u): bool => $this->permissionChecker->canAccessModule($u, $tenant, 'expediente'),
        );
        $avisos = array_merge(
            self::avisosGerais($relatorio, array_merge(array_values($gerais), $this->primarios($vinculos))),
            $this->avisosAosResponsaveis($relatorio, $vinculos, $tenant),
        );

        if (!$simular && !$this->gravar($tenant, $relatorio, $avisos, $agora)) {
            return ResultadoDoEnvioPastasParadas::naoEnviado(self::MENSAGEM_JA_ENVIADO);
        }

        return new ResultadoDoEnvioPastasParadas(
            true,
            $relatorio->pastas === [] ? RelatorioPastasParadasOutput::NENHUMA : self::MENSAGEM_ENVIADO,
            \count($avisos),
            \count($relatorio->pastas),
        );
    }

    /** `primeiraSeg`, `hora` e a trava do mês de `paradasEnviar` (L4825-4826). */
    private function motivoParaNaoEnviar(Tenant $tenant, \DateTimeImmutable $agora): ?string
    {
        if ((int) $agora->format('N') !== 1 || (int) $agora->format('j') > 7) {
            return self::MENSAGEM_NAO_E_O_DIA;
        }
        if ((int) $agora->format('G') < 10) {
            return self::MENSAGEM_CEDO;
        }
        // Atalho para o --dry-run e para o dia a dia; a garantia é a gravação com ON CONFLICT.
        if ($this->envioRepository->existeNoMes($tenant, $agora)) {
            return self::MENSAGEM_JA_ENVIADO;
        }

        return null;
    }

    /**
     * Só os vínculos de quem tem o módulo Expediente (o relatório abre lá).
     *
     * @param list<UserTenant> $vinculos
     *
     * @return list<UserTenant>
     */
    private function comExpediente(array $vinculos, Tenant $tenant): array
    {
        return array_values(array_filter(
            $vinculos,
            fn (UserTenant $v): bool => $this->permissionChecker->canAccessModule($v->getUser(), $tenant, 'expediente'),
        ));
    }

    /**
     * @param list<UserTenant> $vinculos
     *
     * @return list<User>
     */
    private function primarios(array $vinculos): array
    {
        return array_values(array_map(
            static fn (UserTenant $v): User => $v->getUser(),
            array_filter($vinculos, fn (UserTenant $v): bool => $this->regraDosPrimarios->ehPrimario($v)),
        ));
    }

    /**
     * L3245: Controladoria e primários, um aviso por pessoa, com a lista inteira. Sem pasta parada,
     * nenhum.
     *
     * @param list<User> $pessoas
     *
     * @return list<array{0: User, 1: string, 2: string}> [destinatário, título, mensagem]
     */
    private static function avisosGerais(RelatorioPastasParadasOutput $relatorio, array $pessoas): array
    {
        if ($relatorio->pastas === []) {
            return [];
        }
        $titulo = 'Relatório mensal: ' . \count($relatorio->pastas) . ' pasta(s) paradas';
        $texto  = self::cortar(implode(' | ', array_map(
            static fn (PastaParada $p): string => $p->numero . ' · ' . $p->cliente . ' · ' . $p->dias . ' dias · ' . $p->responsavel,
            $relatorio->pastas,
        )), self::TAMANHO_DO_AVISO);

        $unicas = [];
        foreach ($pessoas as $pessoa) {
            $unicas[(int) $pessoa->getId()] = [$pessoa, $titulo, $texto];
        }

        return array_values($unicas);
    }

    /**
     * L3244: cada responsável (vínculo ativo, conta ativa, módulo Expediente) recebe as pastas dele
     * que pode ver, com a primeira sugestão de cada uma (nunca a do financeiro).
     *
     * @param list<UserTenant> $vinculos
     *
     * @return list<array{0: User, 1: string, 2: string}>
     */
    private function avisosAosResponsaveis(RelatorioPastasParadasOutput $relatorio, array $vinculos, Tenant $tenant): array
    {
        $ativos = [];
        foreach ($vinculos as $v) {
            if ($v->getUser()->isActive()) {
                $ativos[(int) $v->getUser()->getId()] = $v->getUser();
            }
        }

        $visiveis       = [];
        $porResponsavel = [];
        foreach ($relatorio->pastas as $pasta) {
            $id = $pasta->responsavelId;
            if ($id === null || !isset($ativos[$id])) {
                continue;
            }
            if (!\array_key_exists($id, $visiveis)) {
                $visiveis[$id] = $this->visiveis->ids($ativos[$id], $tenant);
            }
            if ($visiveis[$id] === null || \in_array($pasta->pastaId, $visiveis[$id], true)) {
                $porResponsavel[$pasta->responsavelId][] = 'Pasta ' . $pasta->numero . ' (' . $pasta->cliente . '), ' . $pasta->dias . ' dias. ' . $pasta->primeiraSugestaoSemFinanceiro();
            }
        }

        $avisos = [];
        foreach ($porResponsavel as $id => $linhas) {
            $lista    = self::cortar(implode(' | ', $linhas), self::TAMANHO_DO_AVISO - mb_strlen(self::PEDIDO_AO_RESPONSAVEL));
            $avisos[] = [$ativos[$id], 'Pastas paradas: ' . \count($linhas) . ' sob sua responsabilidade', $lista . self::PEDIDO_AO_RESPONSAVEL];
        }

        return $avisos;
    }

    private static function cortar(string $texto, int $limite): string
    {
        return mb_strlen($texto) <= $limite ? $texto : rtrim(mb_substr($texto, 0, $limite - 1)) . '…';
    }

    /**
     * Grava o mês e, só se foi ESTA chamada que gravou, os avisos; tudo numa transação.
     *
     * @param list<array{0: User, 1: string, 2: string}> $avisos
     */
    private function gravar(Tenant $tenant, RelatorioPastasParadasOutput $relatorio, array $avisos, \DateTimeImmutable $agora): bool
    {
        $url = $this->urlGenerator->generate('expediente_pastas_paradas');

        return $this->em->wrapInTransaction(function () use ($tenant, $relatorio, $avisos, $agora, $url): bool {
            if (!$this->envioRepository->registrarSeNovo($tenant, $relatorio, $agora)) {
                return false;
            }
            foreach ($avisos as [$pessoa, $titulo, $texto]) {
                $this->notificacaoService->criar($pessoa, $tenant, self::TIPO_NOTIFICACAO, $titulo, $texto)->setUrl($url);
            }

            return true;
        });
    }
}
