<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\RelatorioSemProcessoOutput;
use App\Pasta\DTO\ResultadoDoEnvioSemProcesso;
use App\Pasta\DTO\ResumoSemProcesso;
use App\Pasta\Entity\EnvioDoRelatorioSemProcesso;
use App\Pasta\Repository\EnvioDoRelatorioSemProcessoRepository;
use App\Pasta\Service\MontagemDoRelatorioSemProcesso;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Tenant\Service\RegraDosPrimarios;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Enviar à Controladoria" (desenho 1.2.3, `spEnviar` L4566-4568 e `semProcNotificar` em
 * bluejus-central.js L3225-3233; spec relatorio-processos-controladoria §4).
 *
 * Quem: quem edita a pasta de onde clicou (manual, a guarda é do controller), ou o comando agendado
 * (automático, sem pessoa). O quê: avisar a Controladoria e os primários do retrato das pastas sem
 * processo do escritório inteiro, e avisar cada responsável das pastas DELE que pedem ação.
 *
 * O chat da BlueJus I.A não existe (spec D3): o aviso é a notificação do sino e da Caixa da Central,
 * do tipo `relatorio_sem_processo`, com link para o relatório.
 *
 * Fluxo:
 *  1. automático: só na primeira segunda-feira do mês, a partir das 10h, e uma vez por mês por
 *     escritório (o histórico diz se já saiu); fora disso não envia e diz por quê;
 *  2. analisa todas as pastas ativas do escritório e monta as contagens (L4565 `spResumo`);
 *  3. Controladoria + primários ativos (menos quem enviou): o resumo e a evolução desde o último
 *     envio automático, ou "Primeiro envio.";
 *  4. cada responsável (menos quem enviou, e só com vínculo ativo): as pastas dele que pedem ação;
 *  5. automático grava o histórico (o manual não grava, L4567); uma transação só.
 *
 * Sem ninguém na Controladoria o aviso vai só aos primários e a mensagem é a do desenho (L2718).
 * `$simular` (o `--dry-run` do comando) faz tudo menos gravar e avisar.
 */
final class EnviarRelatorioSemProcessoUseCase
{
    public const TIPO_NOTIFICACAO = 'relatorio_sem_processo';

    public const MENSAGEM_ENVIADO          = 'Relatório enviado à Controladoria';
    public const MENSAGEM_SEM_CONTROLADORIA = 'Nenhum usuário com perfil de Controladoria cadastrado.';

    /** Quantos números de pasta cabem no aviso ao responsável antes do "…". */
    private const PASTAS_NO_AVISO = 10;

    public function __construct(
        private readonly MontagemDoRelatorioSemProcesso $montagem,
        private readonly UserTenantRepository $userTenantRepository,
        private readonly RegraDosPrimarios $regraDosPrimarios,
        private readonly EnvioDoRelatorioSemProcessoRepository $envioRepository,
        private readonly NotificacaoService $notificacaoService,
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ClockInterface $clock,
    ) {
    }

    public function executar(Tenant $tenant, ?User $quem, bool $automatico, bool $simular = false): ResultadoDoEnvioSemProcesso
    {
        $agora = $this->clock->now();
        $recusa = $automatico ? $this->motivoParaNaoEnviarAutomatico($tenant, $agora) : null;
        if ($recusa !== null) {
            return ResultadoDoEnvioSemProcesso::naoEnviado($recusa);
        }

        $relatorio = $this->montagem->montar($tenant, null, true, $agora);
        $vinculos  = $this->userTenantRepository->findAtivosComPerfilPorTenant($tenant);
        $controladoria = $this->userTenantRepository->findUsuariosDaControladoria($tenant);

        $avisos = array_merge(
            $this->avisosGerais($relatorio, array_merge($controladoria, $this->primarios($vinculos)), $quem),
            $this->avisosAosResponsaveis($relatorio, $vinculos, $quem),
        );

        if (!$simular) {
            $this->gravar($tenant, $avisos, $relatorio->resumo, $agora, $automatico);
        }

        return new ResultadoDoEnvioSemProcesso(
            true,
            $controladoria === [] ? self::MENSAGEM_SEM_CONTROLADORIA : self::MENSAGEM_ENVIADO,
            \count($avisos),
            $controladoria === [],
            $relatorio->resumo,
        );
    }

    /** `primeiraSeg` e a trava do mês de `spEnviar` (L4566). */
    private function motivoParaNaoEnviarAutomatico(Tenant $tenant, \DateTimeImmutable $agora): ?string
    {
        if ((int) $agora->format('N') !== 1 || (int) $agora->format('j') > 7) {
            return 'Hoje não é a primeira segunda-feira do mês.';
        }
        if ((int) $agora->format('G') < 10) {
            return 'O envio automático sai a partir das 10h.';
        }
        if ($this->envioRepository->existeNoMes($tenant, $agora->format('m/Y'))) {
            return 'O relatório deste mês já foi enviado.';
        }

        return null;
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
     * Um aviso por pessoa (quem é Controladoria E primário recebe um só), menos quem enviou.
     *
     * @param list<User> $pessoas
     *
     * @return list<array{0: User, 1: string, 2: string}> [destinatário, título, mensagem]
     */
    private function avisosGerais(RelatorioSemProcessoOutput $relatorio, array $pessoas, ?User $quem): array
    {
        $r      = $relatorio->resumo;
        $titulo = 'Relatório mensal: ' . $r->sem . ' pasta(s) sem processo';
        $texto  = sprintf(
            'Relatório de pastas sem processo (%s): %d sem processo vinculado: %d deveriam ter, %d precisam de revisão, %d sem classificação e %d administrativas coerentes.%s',
            $relatorio->hoje->format('d/m/Y'),
            $r->sem,
            $r->deveria,
            $r->rever,
            $r->classificar,
            $r->admin,
            self::evolucao($relatorio),
        );

        $avisos = [];
        foreach (self::semRepetir($pessoas, $quem) as $pessoa) {
            $avisos[] = [$pessoa, $titulo, $texto];
        }

        return $avisos;
    }

    /** L3227: a evolução desde o último envio automático, ou o primeiro envio. */
    private static function evolucao(RelatorioSemProcessoOutput $relatorio): string
    {
        $ultimo = $relatorio->ultimoEnvio();
        if ($ultimo === null) {
            return ' Primeiro envio.';
        }
        $mes = $relatorio->historico[\count($relatorio->historico) - 1]['mes'];
        $r   = $relatorio->resumo;

        return sprintf(
            ' Desde o último envio (%s): deveriam ter processo %d → %d, rever cadastro %d → %d, sem classificação %d → %d.',
            $mes,
            $ultimo->deveria,
            $r->deveria,
            $ultimo->rever,
            $r->rever,
            $ultimo->classificar,
            $r->classificar,
        );
    }

    /**
     * L3230: cada responsável recebe as pastas dele que pedem ação. Só quem tem vínculo ativo e conta
     * ativa no escritório; quem enviou não avisa a si mesmo.
     *
     * @param list<UserTenant> $vinculos
     *
     * @return list<array{0: User, 1: string, 2: string}>
     */
    private function avisosAosResponsaveis(RelatorioSemProcessoOutput $relatorio, array $vinculos, ?User $quem): array
    {
        $ativos = [];
        foreach ($vinculos as $v) {
            if ($v->getUser()->isActive() && $v->getUser()->getId() !== $quem?->getId()) {
                $ativos[(int) $v->getUser()->getId()] = $v->getUser();
            }
        }

        $porResponsavel = [];
        foreach ($relatorio->analises as $analise) {
            if ($analise->pedeAcao() && $analise->responsavelId !== null && isset($ativos[$analise->responsavelId])) {
                $porResponsavel[$analise->responsavelId][] = $analise->numero;
            }
        }

        $avisos = [];
        foreach ($porResponsavel as $id => $numeros) {
            $avisos[] = [$ativos[$id], 'Pastas sem processo: ' . \count($numeros) . ' sob sua responsabilidade', self::mensagemAoResponsavel($numeros)];
        }

        return $avisos;
    }

    /** "N pasta(s) sua(s) sem processo precisam de atenção: 1180, 1181…" (spec §4). */
    private static function mensagemAoResponsavel(array $numeros): string
    {
        $lista = implode(', ', \array_slice($numeros, 0, self::PASTAS_NO_AVISO));

        return \count($numeros) . ' pasta(s) sua(s) sem processo precisam de atenção: ' . $lista
            . (\count($numeros) > self::PASTAS_NO_AVISO ? '…' : '.');
    }

    /**
     * @param list<User> $pessoas
     *
     * @return list<User>
     */
    private static function semRepetir(array $pessoas, ?User $quem): array
    {
        $unicas = [];
        foreach ($pessoas as $pessoa) {
            $id = (int) $pessoa->getId();
            if ($id !== (int) $quem?->getId()) {
                $unicas[$id] = $pessoa;
            }
        }

        return array_values($unicas);
    }

    /**
     * @param list<array{0: User, 1: string, 2: string}> $avisos
     */
    private function gravar(Tenant $tenant, array $avisos, ResumoSemProcesso $resumo, \DateTimeImmutable $agora, bool $automatico): void
    {
        $url = $this->urlGenerator->generate('expediente_relatorio_processos');
        foreach ($avisos as [$pessoa, $titulo, $texto]) {
            $this->notificacaoService->criar($pessoa, $tenant, self::TIPO_NOTIFICACAO, $titulo, $texto)->setUrl($url);
        }
        if ($automatico) {
            $this->envioRepository->salvar(new EnvioDoRelatorioSemProcesso($tenant, $resumo, $agora, true));
        }
        $this->em->flush();
    }
}
