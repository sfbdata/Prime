<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\DiasUteisDoEscritorio;
use App\Repository\UserTenantRepository;
use App\Tarefa\DTO\DespachoNaLinhaDoTempoOutput;
use App\Tarefa\DTO\DespachosDaMetaOutput;
use App\Tarefa\DTO\FormularioDoDespachoOutput;
use App\Tarefa\Enum\NaturezaDoDespacho;
use App\Tarefa\Service\QuemCuidaDaMeta;

/**
 * Monta, para a tela da meta, o bloco DESPACHO e os despachos da linha do tempo (desenho 04B de 22/10, L57-71,
 * L118, L159-161; spec `minhas-metas-esteira-2210.md`, C11 a C15).
 *
 * Prazo padrão: 5 dias úteis depois de hoje pela regra do escritório (C1, C12), com a UF do escritório. A lista
 * de dias não úteis cobre {@see JANELA_EM_DIAS} dias, para o navegador recontar o "N dias úteis" pela mesma regra.
 * Os despachos são numerados do mais antigo (DESPACHO 1) ao mais recente.
 */
final class MontarDespachosDaMetaUseCase
{
    public const UTEIS_DO_PRAZO_PADRAO = 5;
    public const JANELA_EM_DIAS = 180;

    /** @var array<int, ?string> cargo por id da pessoa, nesta montagem */
    private array $cargos = [];

    public function __construct(
        private readonly DiasUteisDoEscritorio $diasUteis,
        private readonly UserTenantRepository $vinculos,
        private readonly QuemCuidaDaMeta $quemCuida,
    ) {
    }

    public function executar(Tarefa $meta, User $quemVe, Tenant $escritorio, \DateTimeImmutable $hoje): DespachosDaMetaOutput
    {
        $hoje = $hoje->setTime(0, 0);
        $this->cargos = [];
        $podeCertificar = $this->quemCuida->ehResponsavelOuCriador($meta, $quemVe);
        $despachos = array_values($meta->getMensagens()->toArray());
        usort($despachos, static fn (TarefaMensagem $a, TarefaMensagem $b): int => $a->getCriadoEm() <=> $b->getCriadoEm());

        $linhas = [];
        foreach ($despachos as $i => $despacho) {
            $linhas[(int) $despacho->getId()] = $this->linha($despacho, $i + 1, $i === count($despachos) - 1, $escritorio, $hoje, $podeCertificar);
        }

        return new DespachosDaMetaOutput($this->formulario($escritorio, $hoje), $linhas);
    }

    private function formulario(Tenant $escritorio, \DateTimeImmutable $hoje): FormularioDoDespachoOutput
    {
        $uf = $escritorio->getEstado();

        return new FormularioDoDespachoOutput(
            hoje: $hoje->format('Y-m-d'),
            prazoPadrao: $this->diasUteis->somarUteis($hoje, self::UTEIS_DO_PRAZO_PADRAO, $uf)->format('Y-m-d'),
            uteisDoPrazoPadrao: self::UTEIS_DO_PRAZO_PADRAO,
            naoUteis: $this->diasUteis->naoUteisEntre($hoje, $hoje->modify('+' . self::JANELA_EM_DIAS . ' days'), $uf),
            naturezas: array_map(
                static fn (NaturezaDoDespacho $n): array => ['valor' => $n->value, 'rotulo' => $n->rotulo(), 'inicio' => $n->inicioDoTexto()],
                NaturezaDoDespacho::cases(),
            ),
        );
    }

    private function linha(TarefaMensagem $d, int $numero, bool $maisRecente, Tenant $escritorio, \DateTimeImmutable $hoje, bool $podeCertificar): DespachoNaLinhaDoTempoOutput
    {
        $prazo = $d->temCertidao() ? null : $d->getPrazoCumprimento();
        $cidade = trim((string) $escritorio->getCidade());

        return new DespachoNaLinhaDoTempoOutput(
            numero: $numero,
            maisRecente: $maisRecente,
            natureza: $d->getNatureza()?->rotulo(),
            codigo: $d->getCodigo(),
            autor: (string) ($d->getUsuario()?->getFullName() ?? $d->getUsuario()?->getEmail()),
            cargo: $this->cargo($d->getUsuario(), $escritorio),
            registradoEm: $d->getCriadoEm(),
            cidade: $cidade === '' ? null : $cidade,
            prazo: $prazo,
            prazoVencido: $prazo !== null && $prazo < $hoje,
            ciente: self::primeiroNome($d->getCientePor()),
            cienteEm: $d->getCienteEm(),
            certidao: $d->getCertidao(),
            certificadoPor: $d->getCertificadoPor()?->getFullName(),
            certificadoEm: $d->getCertificadoEm(),
            podeCertificar: $podeCertificar && !$d->temCertidao(),
        );
    }

    private function cargo(?User $pessoa, Tenant $escritorio): ?string
    {
        if ($pessoa === null || $pessoa->getId() === null) {
            return null;
        }
        $id = $pessoa->getId();
        if (!array_key_exists($id, $this->cargos)) {
            $this->cargos[$id] = $this->vinculos->findPorUserETenant($pessoa, $escritorio)?->getCargo()?->getNome();
        }

        return $this->cargos[$id];
    }

    private static function primeiroNome(?User $pessoa): ?string
    {
        if ($pessoa === null) {
            return null;
        }
        $nome = trim((string) ($pessoa->getFullName() ?? $pessoa->getEmail()));

        return explode(' ', $nome)[0];
    }
}
