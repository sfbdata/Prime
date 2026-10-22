<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\DiasUteisDoEscritorio;
use App\Tarefa\DTO\DespachoNaLinhaDoTempoOutput;
use App\Tarefa\DTO\DespachosDaMetaOutput;
use App\Tarefa\DTO\FormularioDoDespachoOutput;
use App\Tarefa\Enum\AcaoDoDespacho;
use App\Tarefa\Enum\NaturezaDoDespacho;
use App\Tarefa\Service\QuemCuidaDaMeta;

/**
 * Monta, para a tela da meta, o bloco DESPACHO e os despachos da linha do tempo (desenho 04B de 22/10, L57-71,
 * L118, L159-161; spec `minhas-metas-esteira-2210.md`, C11 a C15).
 *
 * Prazo padrão: 5 dias úteis depois de hoje pela regra do escritório (C1, C12), com a UF do escritório. A lista
 * de dias não úteis cobre {@see JANELA_EM_DIAS} dias, para o navegador recontar o "N dias úteis" pela mesma regra.
 * A numeração ("DESPACHO N") é do histórico inteiro, no `MontarLinhaDoTempoDaMetaUseCase`.
 */
final class MontarDespachosDaMetaUseCase
{
    public const UTEIS_DO_PRAZO_PADRAO = 5;
    public const JANELA_EM_DIAS = 180;

    public function __construct(
        private readonly DiasUteisDoEscritorio $diasUteis,
        private readonly QuemCuidaDaMeta $quemCuida,
    ) {
    }

    public function executar(Tarefa $meta, User $quemVe, Tenant $escritorio, \DateTimeImmutable $hoje): DespachosDaMetaOutput
    {
        $hoje = $hoje->setTime(0, 0);
        $podeCertificar = $this->quemCuida->ehResponsavelOuCriador($meta, $quemVe);
        $despachos = $meta->getMensagens()->toArray();

        $linhas = [];
        foreach ($despachos as $despacho) {
            $linhas[(int) $despacho->getId()] = $this->linha($despacho, $escritorio, $hoje, $podeCertificar);
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

    private function linha(TarefaMensagem $d, Tenant $escritorio, \DateTimeImmutable $hoje, bool $podeCertificar): DespachoNaLinhaDoTempoOutput
    {
        $prazo = $d->temCertidao() ? null : $d->getPrazoCumprimento();
        $cidade = trim((string) $escritorio->getCidade());

        return new DespachoNaLinhaDoTempoOutput(
            natureza: $d->getNatureza()?->rotulo(),
            tipo: $d->getNatureza()?->rotulo() ?? ($d->getAcao() ?? AcaoDoDespacho::Despacho)->rotulo(),
            acao: $d->getAcao()?->value,
            codigo: $d->getCodigo(),
            autor: (string) ($d->getUsuario()?->getFullName() ?? $d->getUsuario()?->getEmail()),
            registradoPor: $d->getRegistradoPorNome(),
            cargo: $d->getRegistradoPorCargo(),
            registradoEm: $d->getCriadoEm(),
            cidade: $cidade === '' ? null : $cidade,
            prazo: $prazo,
            prazoVencido: $prazo !== null && $prazo < $hoje,
            ciente: self::primeiroNome($d->getCientePor()),
            cienteEm: $d->getCienteEm(),
            certidao: $d->getCertidao(),
            certificadoPor: $d->getCertificadoPor()?->getFullName(),
            certificadoEm: $d->getCertificadoEm(),
            podeCertificar: $podeCertificar && $d->getAcao() === AcaoDoDespacho::Despacho && !$d->temCertidao(),
        );
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
