<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\CobrancaDoDia;
use App\Pasta\DTO\PendenciaDoChecklist;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaChecklistCobranca;
use App\Pasta\Entity\PastaChecklistItem;
use App\Pasta\Repository\PastaChecklistCobrancaRepository;
use App\Pasta\Service\AuditoriaDoChecklist;
use App\Pasta\Service\AvisoDoChecklist;
use App\Pasta\Service\ControladoriaDoEscritorioInterface;
use App\Pasta\Service\DiaUtilDoEscritorio;
use App\Pasta\Service\MensagensDaCobrancaDoChecklist as Msg;
use App\Pasta\Service\PlanoDeCobrancaDoChecklist;
use App\Repository\UserTenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A rotina diária da cobrança do checklist num escritório (desenho 02, `cobrarChecklist`, `ckAuditar` e
 * `ckLembrete`; spec `docs/specs/pasta-cobranca-do-checklist.md`). Para cada pasta com checklist:
 *  - desativado: o lembrete "Revisar checklist" ao responsável a cada 30 dias;
 *  - ativo: a auditoria "sem anexo" e, em dia útil, as cobranças do plano do dia ao responsável, com cópia
 *    à controladoria no 4º aviso (e a cada 3 depois) e na previsão descumprida.
 *
 * Idempotente no dia: o envio de hoje fica no registro do item, e o plano não repete quem já foi cobrado.
 * Roda sem sessão: tudo por escritório explícito. Pasta sem responsável ativo não é cobrada.
 */
final class CobrarChecklistDoEscritorioUseCase
{
    private const DIAS_ENTRE_LEMBRETES = 30;

    public function __construct(
        private readonly PastaChecklistCobrancaRepository $cobrancas,
        private readonly PlanoDeCobrancaDoChecklist $plano,
        private readonly AuditoriaDoChecklist $auditoria,
        private readonly AvisoDoChecklist $aviso,
        private readonly ControladoriaDoEscritorioInterface $controladoria,
        private readonly DiaUtilDoEscritorio $diaUtil,
        private readonly UrlGeneratorInterface $urls,
        private readonly EntityManagerInterface $em,
        private readonly UserTenantRepository $vinculos,
    ) {
    }

    /**
     * Uma pasta por vez: carrega, processa, grava e solta (clear), para o custo e a memória não crescerem com
     * o tamanho do escritório. Depois do clear o `$escritorio` recebido fica solto: só o id é usado.
     *
     * @return array{cobrancas: int, lembretes: int}
     */
    public function executar(Tenant $escritorio, \DateTimeImmutable $agora): array
    {
        $diaUtil = $this->diaUtil->ehDiaUtil($escritorio, $agora);
        $total = ['cobrancas' => 0, 'lembretes' => 0];
        foreach ($this->cobrancas->idsDasPastasDaRotina((int) $escritorio->getId()) as $pastaId) {
            $pasta = $this->em->find(Pasta::class, $pastaId);
            if ($pasta !== null) {
                $this->processar($pasta, $agora, $diaUtil, $total);
                $this->em->flush();
            }
            $this->em->clear();
        }

        return $total;
    }

    /** @param array{cobrancas: int, lembretes: int} $total */
    private function processar(Pasta $pasta, \DateTimeImmutable $agora, bool $diaUtil, array &$total): void
    {
        $responsavel = $this->responsavelAtivo($pasta);
        if (!$pasta->isChecklistAtivo()) {
            $total['lembretes'] += $this->lembrar($pasta, $responsavel, $agora) ? 1 : 0;

            return;
        }

        $registros = $this->cobrancas->daPasta((int) $pasta->getTenant()?->getId(), (int) $pasta->getId());
        $this->auditoria->varrer($pasta, $responsavel, $registros, $agora);
        if ($responsavel !== null) {
            $total['cobrancas'] += $this->cobrar($pasta, $responsavel, $registros, $agora, $diaUtil);
        }
    }

    /**
     * Por dia, não por segundo: o cron das 9h do 30º dia começa alguns segundos antes do de 30 dias atrás.
     * Os dias do texto são arredondados, como o `Math.round` do desenho.
     */
    private function lembrar(Pasta $pasta, ?User $responsavel, \DateTimeImmutable $agora): bool
    {
        $desde = $pasta->getChecklistLembreteEm() ?? $pasta->getChecklistDesativadoEm();
        $limite = $agora->setTime(0, 0)->modify('-' . self::DIAS_ENTRE_LEMBRETES . ' days');
        if ($desde === null || $responsavel === null || $desde->setTime(0, 0) > $limite) {
            return false;
        }

        $dias = (int) round(($agora->getTimestamp() - (int) $pasta->getChecklistDesativadoEm()?->getTimestamp()) / 86400);
        $motivo = $pasta->getChecklistMotivo()?->rotulo() ?? '';
        $this->aviso->avisar($responsavel, $pasta->getTenant(), AvisoDoChecklist::TIPO_PROVIDENCIA, Msg::lembrete($pasta, $dias, $motivo), $this->url($pasta));
        $pasta->registrarLembreteDoChecklist($agora);

        return true;
    }

    /** @param array<int, PastaChecklistCobranca> $existentes */
    private function cobrar(Pasta $pasta, User $responsavel, array $existentes, \DateTimeImmutable $agora, bool $diaUtil): int
    {
        $registros = $this->registrosDosPendentes($pasta, $existentes, $agora);
        $pendentes = array_map(static fn (PastaChecklistCobranca $r): PendenciaDoChecklist => new PendenciaDoChecklist((int) $r->getItem()->getId(), $r->getItem()->getTitulo(), $r->getPendenteDesde(), $r->getEnvios(), $r->getPrevisao(), $r->getQuebras()), array_values($registros));
        $cobrancas = $this->plano->doDia($pendentes, $agora, $diaUtil);
        foreach ($cobrancas as $cobranca) {
            $this->enviar($pasta, $responsavel, $cobranca, $registros[$cobranca->pendencia->itemId], $agora);
        }

        return \count($cobrancas);
    }

    private function enviar(Pasta $pasta, User $responsavel, CobrancaDoDia $cobranca, PastaChecklistCobranca $registro, \DateTimeImmutable $agora): void
    {
        $tenant = $pasta->getTenant();
        $this->aviso->avisar($responsavel, $tenant, AvisoDoChecklist::TIPO_COBRANCA, Msg::cobranca($cobranca, $pasta), $this->url($pasta));
        if (PlanoDeCobrancaDoChecklist::copiaCoordenacao($cobranca)) {
            $nome = (string) ($responsavel->getFullName() ?: $responsavel->getEmail());
            $this->aviso->avisarVarias($this->controladoria->pessoas($tenant), $responsavel, $tenant, AvisoDoChecklist::TIPO_COBRANCA, Msg::paraCoordenacao($cobranca, $pasta, $nome), $this->url($pasta));
        }
        $registro->registrarEnvio($agora);
        if ($cobranca->quebrou) {
            $registro->registrarQuebra();
        }
    }

    /**
     * Um registro por item pendente (o novo nasce hoje); itens concluídos sem auditoria perdem o registro.
     *
     * @param array<int, PastaChecklistCobranca> $existentes
     *
     * @return array<int, PastaChecklistCobranca> indexado pelo id do item
     */
    private function registrosDosPendentes(Pasta $pasta, array $existentes, \DateTimeImmutable $agora): array
    {
        $pendentes = [];
        /** @var PastaChecklistItem $item */
        foreach ($pasta->getChecklistItens() as $item) {
            $id = (int) $item->getId();
            $registro = $existentes[$id] ?? null;
            if ($item->isConcluido()) {
                if ($registro !== null && $registro->getAuditoriaEm() === null && $this->em->contains($registro)) {
                    $this->cobrancas->remover($registro);
                }
                continue;
            }
            $pendentes[$id] = $registro ?? new PastaChecklistCobranca($item->getTenant() ?? $pasta->getTenant(), $item, $agora->setTime(0, 0));
            $this->cobrancas->salvar($pendentes[$id]);
        }

        return $pendentes;
    }

    private function url(Pasta $pasta): string
    {
        return $this->urls->generate('pasta_show', ['id' => $pasta->getId(), 'aba' => 'documentos']);
    }

    /** Responsável com conta ativa E vínculo ativo neste escritório: quem saiu não é cobrado. */
    private function responsavelAtivo(Pasta $pasta): ?User
    {
        $responsavel = $pasta->getResponsavel();
        $escritorio = $pasta->getTenant();
        if ($responsavel === null || $escritorio === null || !$responsavel->isActive()) {
            return null;
        }

        return $this->vinculos->existeVinculoAtivo($responsavel, $escritorio) ? $responsavel : null;
    }
}
